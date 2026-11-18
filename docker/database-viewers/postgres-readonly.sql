-- Ejecutar con psql -X como administrador, conectado a DB_DATABASE.
-- Identificadores: PG_VIEWER_USERNAME, DB_USERNAME y DB_MIGRATOR_USERNAME.
-- El instalador recibe un secreto de 64 hex por stdin y exporta
-- SYSFOREST_VIEWER_PASSWORD solo al proceso cliente. No usar -v con la clave.
\set ON_ERROR_STOP on
\set ECHO none
\set QUIET on
\set VERBOSITY terse
\getenv viewer_user PG_VIEWER_USERNAME
\getenv target_database DB_DATABASE
\getenv app_user DB_USERNAME
\getenv migrator_user DB_MIGRATOR_USERNAME

BEGIN;
CREATE TEMP TABLE sysforest_viewer_config (
    viewer_name text NOT NULL,
    database_name text NOT NULL,
    app_name text NOT NULL,
    migrator_name text NOT NULL
) ON COMMIT DROP;
INSERT INTO sysforest_viewer_config VALUES (
    :'viewer_user', :'target_database', :'app_user', :'migrator_user'
);
CREATE TEMP TABLE sysforest_viewer_secret (value text NOT NULL) ON COMMIT DROP;
-- \copy ejecuta printf en el CLIENTE psql; el secreto no forma parte del SQL.
\copy pg_temp.sysforest_viewer_secret FROM PROGRAM 'printf "%s\n" "$SYSFOREST_VIEWER_PASSWORD"'

DO $bootstrap$
DECLARE
    settings record;
    viewer_oid oid;
    migrator_oid oid;
    viewer_password text;
    relation record;
    marker constant text := 'SYSFOREST_DATABASE_VIEWER_READONLY_V1';
BEGIN
    SELECT * INTO STRICT settings FROM pg_temp.sysforest_viewer_config;
    SELECT value INTO STRICT viewer_password FROM pg_temp.sysforest_viewer_secret;
    IF viewer_password !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'El secreto del visor debe contener exactamente 64 caracteres hexadecimales.';
    END IF;
    IF settings.viewer_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.database_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.app_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.migrator_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.viewer_name ~ '^pg_'
        OR settings.viewer_name IN (settings.app_name, settings.migrator_name, current_user)
        OR settings.app_name = settings.migrator_name
        OR settings.database_name <> current_database() THEN
        RAISE EXCEPTION 'Configuración de identidades del visor PostgreSQL inválida.';
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = current_user AND rolsuper)
        OR NOT EXISTS (SELECT FROM pg_roles WHERE rolname = settings.app_name) THEN
        RAISE EXCEPTION 'Se requiere la conexión administrativa y los roles de aplicación existentes.';
    END IF;
    SELECT oid INTO migrator_oid FROM pg_roles WHERE rolname = settings.migrator_name;
    IF migrator_oid IS NULL THEN
        RAISE EXCEPTION 'El rol migrador debe existir antes de preparar el visor.';
    END IF;
    -- Serializa únicamente instaladores de este rol, no operaciones forestales.
    PERFORM pg_advisory_xact_lock(hashtextextended('sysforest:database-viewer:' || settings.viewer_name, 0));
    SELECT oid INTO viewer_oid FROM pg_roles WHERE rolname = settings.viewer_name;
    IF viewer_oid IS NOT NULL THEN
        IF shobj_description(viewer_oid, 'pg_authid') IS DISTINCT FROM marker THEN
            RAISE EXCEPTION 'El nombre del visor ya pertenece a un rol ajeno a este instalador.';
        END IF;
        IF EXISTS (SELECT FROM pg_roles WHERE oid = viewer_oid
            AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls))
            OR EXISTS (SELECT FROM pg_auth_members WHERE member = viewer_oid OR roleid = viewer_oid)
            OR EXISTS (SELECT FROM pg_shdepend WHERE refclassid = 'pg_authid'::regclass
                AND refobjid = viewer_oid AND deptype = 'o') THEN
            RAISE EXCEPTION 'El rol existente tiene poderes, membresías o propiedad incompatibles con la consulta.';
        END IF;
    ELSE
        EXECUTE format('CREATE ROLE %I NOLOGIN', settings.viewer_name);
        EXECUTE format('COMMENT ON ROLE %I IS %L', settings.viewer_name, marker);
        SELECT oid INTO viewer_oid FROM pg_roles WHERE rolname = settings.viewer_name;
    END IF;

    -- No cambia propietarios ni privilegios de PUBLIC, aplicación o migrador.
    EXECUTE format('ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 5', settings.viewer_name);
    EXECUTE format('REVOKE ALL ON DATABASE %I FROM %I', settings.database_name, settings.viewer_name);
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO %I', settings.database_name, settings.viewer_name);
    EXECUTE format('REVOKE ALL ON SCHEMA public FROM %I', settings.viewer_name);
    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', settings.viewer_name);
    FOR relation IN
        SELECT n.nspname, c.relname FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relowner = migrator_oid
            AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
    LOOP
        EXECUTE format('REVOKE ALL ON TABLE %I.%I FROM %I', relation.nspname, relation.relname, settings.viewer_name);
        EXECUTE format('GRANT SELECT ON TABLE %I.%I TO %I', relation.nspname, relation.relname, settings.viewer_name);
    END LOOP;
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT ON TABLES TO %I', settings.migrator_name, settings.viewer_name);

    -- Defensas auxiliares; read_only es una preferencia de sesión, no sustituye ACL.
    EXECUTE format('ALTER ROLE %I SET default_transaction_read_only = on', settings.viewer_name);
    EXECUTE format('ALTER ROLE %I SET row_security = on', settings.viewer_name);
    EXECUTE format('ALTER ROLE %I SET statement_timeout = %L', settings.viewer_name, '30s');
    EXECUTE format('ALTER ROLE %I SET lock_timeout = %L', settings.viewer_name, '5s');
    EXECUTE format('ALTER ROLE %I SET idle_in_transaction_session_timeout = %L', settings.viewer_name, '60s');
    EXECUTE format('ALTER ROLE %I SET search_path = public, pg_catalog', settings.viewer_name);

    IF has_database_privilege(viewer_oid, current_database(), 'CREATE')
        OR has_database_privilege(viewer_oid, current_database(), 'TEMP')
        OR EXISTS (SELECT FROM pg_namespace n WHERE n.nspname !~ '^pg_temp_'
            AND n.nspname !~ '^pg_toast_temp_' AND has_schema_privilege(viewer_oid, n.oid, 'CREATE'))
        OR EXISTS (SELECT FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                AND n.nspname !~ '^pg_(toast|temp)' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                AND (has_table_privilege(viewer_oid, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                    OR has_any_column_privilege(viewer_oid, c.oid, 'INSERT,UPDATE,REFERENCES')))
        OR EXISTS (SELECT FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname !~ '^pg_' AND CASE WHEN c.relkind = 'S'
                THEN has_sequence_privilege(viewer_oid, c.oid, 'USAGE,UPDATE') ELSE false END)
        OR EXISTS (SELECT FROM pg_default_acl defaults
            CROSS JOIN LATERAL aclexplode(defaults.defaclacl) privilege
            WHERE defaults.defaclrole = migrator_oid AND defaults.defaclobjtype = 'r'
                AND defaults.defaclnamespace IN (0, 'public'::regnamespace)
                AND privilege.grantee IN (0, viewer_oid)
                AND (privilege.privilege_type <> 'SELECT' OR privilege.is_grantable)) THEN
        RAISE EXCEPTION 'Hay privilegios efectivos de escritura o creación: revisar sin ampliar ni revocar PUBLIC automáticamente.';
    END IF;
    -- Los triggers del repositorio no son invocables directamente. Un nuevo
    -- SECURITY DEFINER accesible requiere revisión; el instalador no lo revoca.
    IF EXISTS (SELECT FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
            AND p.prosecdef AND p.prorettype NOT IN ('trigger'::regtype, 'event_trigger'::regtype)
            AND has_schema_privilege(viewer_oid, n.oid, 'USAGE')
            AND has_function_privilege(viewer_oid, p.oid, 'EXECUTE')) THEN
        RAISE EXCEPTION 'Existe una función SECURITY DEFINER accesible que requiere revisión antes de habilitar el visor.';
    END IF;

    -- No interpolar secretos en sentencias enviadas por psql ni imprimir errores
    -- de SQL dinámico que pudieran contener el valor de PASSWORD.
    BEGIN
        EXECUTE format('ALTER ROLE %I PASSWORD %L', settings.viewer_name, viewer_password);
    EXCEPTION WHEN OTHERS THEN
        RAISE EXCEPTION 'No se pudo configurar la credencial del visor PostgreSQL.';
    END;
    DELETE FROM pg_temp.sysforest_viewer_secret;
END;
$bootstrap$;
COMMIT;
\echo Visor PostgreSQL preparado con SELECT, sin membresías ni bypass de RLS.

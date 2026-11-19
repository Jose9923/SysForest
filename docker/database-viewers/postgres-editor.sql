-- Ejecutar con psql -X como administrador, conectado a DB_DATABASE.
-- Cuenta separada sysforest_editor: no modifica sysforest_viewer, datos ni triggers.
-- SQL directo no ejecuta validación, hash de contraseña ni auditoría de Laravel.
-- Identificadores: PG_EDITOR_USERNAME, DB_USERNAME y DB_MIGRATOR_USERNAME.
-- El instalador recibe un secreto de 64 hex por stdin y exporta
-- SYSFOREST_EDITOR_PASSWORD solo al proceso cliente. No usar -v con la clave.
\set ON_ERROR_STOP on
\set ECHO none
\set QUIET on
\set VERBOSITY terse
\getenv editor_user PG_EDITOR_USERNAME
\getenv target_database DB_DATABASE
\getenv app_user DB_USERNAME
\getenv migrator_user DB_MIGRATOR_USERNAME

BEGIN;
CREATE TEMP TABLE sysforest_editor_config (
    editor_name text NOT NULL,
    database_name text NOT NULL,
    app_name text NOT NULL,
    migrator_name text NOT NULL
) ON COMMIT DROP;
INSERT INTO sysforest_editor_config VALUES (
    :'editor_user', :'target_database', :'app_user', :'migrator_user'
);
CREATE TEMP TABLE sysforest_editor_secret (value text NOT NULL) ON COMMIT DROP;
-- \copy ejecuta printf en el CLIENTE psql; el secreto no forma parte del SQL.
\copy pg_temp.sysforest_editor_secret FROM PROGRAM 'printf "%s\n" "$SYSFOREST_EDITOR_PASSWORD"'

DO $bootstrap$
DECLARE
    settings record;
    editor_oid oid;
    migrator_oid oid;
    editor_password text;
    relation record;
    new_role boolean := false;
    marker constant text := 'SYSFOREST_DATABASE_EDITOR_DML_V1';
BEGIN
    SELECT * INTO STRICT settings FROM pg_temp.sysforest_editor_config;
    SELECT value INTO STRICT editor_password FROM pg_temp.sysforest_editor_secret;
    IF editor_password !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'El secreto del editor debe contener exactamente 64 caracteres hexadecimales.';
    END IF;
    IF settings.editor_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.database_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.app_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.migrator_name !~ '^[a-zA-Z][a-zA-Z0-9_]{0,62}$'
        OR settings.editor_name <> 'sysforest_editor'
        OR settings.editor_name ~ '^pg_'
        OR settings.editor_name IN (settings.app_name, settings.migrator_name, current_user)
        OR settings.app_name = settings.migrator_name
        OR settings.database_name <> current_database() THEN
        RAISE EXCEPTION 'Configuración de identidades del editor PostgreSQL inválida.';
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
    PERFORM pg_advisory_xact_lock(hashtextextended('sysforest:database-editor:' || settings.editor_name, 0));
    SELECT oid INTO editor_oid FROM pg_roles WHERE rolname = settings.editor_name;
    IF editor_oid IS NOT NULL THEN
        IF shobj_description(editor_oid, 'pg_authid') IS DISTINCT FROM marker THEN
            RAISE EXCEPTION 'El nombre del editor ya pertenece a un rol ajeno a este instalador.';
        END IF;
        IF EXISTS (SELECT FROM pg_roles WHERE oid = editor_oid
            AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls))
            OR EXISTS (SELECT FROM pg_auth_members WHERE member = editor_oid OR roleid = editor_oid)
            OR EXISTS (SELECT FROM pg_shdepend WHERE refclassid = 'pg_authid'::regclass
                AND refobjid = editor_oid AND deptype = 'o') THEN
            RAISE EXCEPTION 'El rol existente tiene poderes, membresías o propiedad incompatibles con la edición limitada.';
        END IF;
    ELSE
        EXECUTE format('CREATE ROLE %I NOLOGIN', settings.editor_name);
        new_role := true;
        EXECUTE format('COMMENT ON ROLE %I IS %L', settings.editor_name, marker);
        SELECT oid INTO editor_oid FROM pg_roles WHERE rolname = settings.editor_name;
    END IF;

    -- No cambia propietarios ni privilegios de PUBLIC, aplicación o migrador.
    EXECUTE format('ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 5', settings.editor_name);
    EXECUTE format('REVOKE ALL ON DATABASE %I FROM %I', settings.database_name, settings.editor_name);
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO %I', settings.database_name, settings.editor_name);
    EXECUTE format('REVOKE ALL ON SCHEMA public FROM %I', settings.editor_name);
    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', settings.editor_name);
    FOR relation IN
        SELECT n.nspname, c.relname FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relowner = migrator_oid
            AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
    LOOP
        EXECUTE format('REVOKE ALL ON TABLE %I.%I FROM %I', relation.nspname, relation.relname, settings.editor_name);
        EXECUTE format('GRANT SELECT, INSERT, UPDATE ON TABLE %I.%I TO %I', relation.nspname, relation.relname, settings.editor_name);
    END LOOP;
    FOR relation IN
        SELECT n.nspname, c.relname FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relowner = migrator_oid AND c.relkind = 'S'
    LOOP
        EXECUTE format('REVOKE ALL ON SEQUENCE %I.%I FROM %I', relation.nspname, relation.relname, settings.editor_name);
        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %I.%I TO %I', relation.nspname, relation.relname, settings.editor_name);
    END LOOP;
    -- Defaults solo del migrador en public; no alteran PUBLIC ni otros roles.
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public REVOKE ALL ON TABLES FROM %I', settings.migrator_name, settings.editor_name);
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE ON TABLES TO %I', settings.migrator_name, settings.editor_name);
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public REVOKE ALL ON SEQUENCES FROM %I', settings.migrator_name, settings.editor_name);
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I', settings.migrator_name, settings.editor_name);

    -- DML habilitado solo para este rol; los límites no sustituyen las ACL.
    EXECUTE format('ALTER ROLE %I SET default_transaction_read_only = off', settings.editor_name);
    EXECUTE format('ALTER ROLE %I SET row_security = on', settings.editor_name);
    EXECUTE format('ALTER ROLE %I SET statement_timeout = %L', settings.editor_name, '30s');
    EXECUTE format('ALTER ROLE %I SET lock_timeout = %L', settings.editor_name, '5s');
    EXECUTE format('ALTER ROLE %I SET idle_in_transaction_session_timeout = %L', settings.editor_name, '60s');
    EXECUTE format('ALTER ROLE %I SET search_path = public, pg_catalog', settings.editor_name);

    IF has_database_privilege(editor_oid, current_database(), 'CREATE')
        OR has_database_privilege(editor_oid, current_database(), 'TEMP')
        OR EXISTS (SELECT FROM pg_namespace n WHERE n.nspname !~ '^pg_temp_'
            AND n.nspname !~ '^pg_toast_temp_' AND has_schema_privilege(editor_oid, n.oid, 'CREATE'))
        OR EXISTS (SELECT FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                AND n.nspname !~ '^pg_(toast|temp)' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                AND (has_table_privilege(editor_oid, c.oid, 'DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
                    OR has_any_column_privilege(editor_oid, c.oid, 'REFERENCES')
                    OR ((n.nspname <> 'public' OR c.relowner <> migrator_oid)
                        AND (has_table_privilege(editor_oid, c.oid, 'INSERT,UPDATE')
                            OR has_any_column_privilege(editor_oid, c.oid, 'INSERT,UPDATE')))))
        OR EXISTS (SELECT FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname !~ '^pg_' AND CASE WHEN c.relkind = 'S' THEN
                has_sequence_privilege(editor_oid, c.oid, 'UPDATE')
                OR ((n.nspname <> 'public' OR c.relowner <> migrator_oid)
                    AND has_sequence_privilege(editor_oid, c.oid, 'USAGE,SELECT'))
                ELSE false END)
        OR EXISTS (SELECT FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            CROSS JOIN LATERAL aclexplode(c.relacl) privilege
            WHERE n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'
                AND privilege.grantee IN (0, editor_oid) AND privilege.is_grantable)
        OR EXISTS (SELECT FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            CROSS JOIN LATERAL aclexplode(a.attacl) privilege
            WHERE n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'
                AND privilege.grantee IN (0, editor_oid) AND privilege.is_grantable)
        OR EXISTS (SELECT FROM pg_default_acl defaults
            CROSS JOIN LATERAL aclexplode(defaults.defaclacl) privilege
            WHERE defaults.defaclobjtype IN ('r', 'S')
                AND privilege.grantee IN (0, editor_oid)
                AND (privilege.is_grantable
                    OR (defaults.defaclrole = migrator_oid AND defaults.defaclnamespace = 'public'::regnamespace
                        AND ((defaults.defaclobjtype = 'r' AND privilege.privilege_type NOT IN ('SELECT', 'INSERT', 'UPDATE'))
                            OR (defaults.defaclobjtype = 'S' AND privilege.privilege_type NOT IN ('SELECT', 'USAGE'))))
                    OR ((defaults.defaclrole <> migrator_oid OR defaults.defaclnamespace <> 'public'::regnamespace)
                        AND privilege.privilege_type <> 'SELECT'))) THEN
        RAISE EXCEPTION 'Hay privilegios efectivos de borrado, DDL, delegación o escritura fuera del alcance; revisar sin alterar PUBLIC ni otros roles.';
    END IF;
    -- Los triggers del repositorio no son invocables directamente. Un nuevo
    -- SECURITY DEFINER accesible requiere revisión; el instalador no lo revoca.
    IF EXISTS (SELECT FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
            AND p.prosecdef AND p.prorettype NOT IN ('trigger'::regtype, 'event_trigger'::regtype)
            AND has_schema_privilege(editor_oid, n.oid, 'USAGE')
            AND has_function_privilege(editor_oid, p.oid, 'EXECUTE')) THEN
        RAISE EXCEPTION 'Existe una función SECURITY DEFINER accesible que requiere revisión antes de habilitar el visor.';
    END IF;

    -- No interpolar secretos en sentencias enviadas por psql ni imprimir errores
    -- de SQL dinámico que pudieran contener el valor de PASSWORD.
    IF new_role THEN
        BEGIN
            EXECUTE format('ALTER ROLE %I PASSWORD %L', settings.editor_name, editor_password);
        EXCEPTION WHEN OTHERS THEN
            RAISE EXCEPTION 'No se pudo configurar la credencial nueva del editor PostgreSQL.';
        END;
    END IF;
    -- Repetir no cambia PASSWORD ni su hash SCRAM; la credencial vigente se verifica al conectar.
    DELETE FROM pg_temp.sysforest_editor_secret;
END;
$bootstrap$;
COMMIT;
\echo Editor PostgreSQL preparado con SELECT/INSERT/UPDATE y secuencias USAGE/SELECT, sin DELETE/DDL ni bypass de RLS.

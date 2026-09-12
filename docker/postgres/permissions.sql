\getenv app_user DB_USERNAME
\getenv migrator_user DB_MIGRATOR_USERNAME

CREATE EXTENSION IF NOT EXISTS postgis;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;

SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_user')
\gexec

SELECT format('GRANT USAGE, CREATE ON SCHEMA public TO %I', :'migrator_user')
\gexec

SELECT format(
    'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I',
    :'migrator_user', :'app_user'
)
\gexec

SELECT format(
    'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I',
    :'migrator_user', :'app_user'
)
\gexec

-- Los objetos de PostGIS siguen bajo propiedad administrativa. Solo damos CRUD sobre tablas de la aplicación.
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE %I.%I TO %I', n.nspname, c.relname, :'app_user')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_roles r ON r.oid = c.relowner
WHERE n.nspname = 'public'
  AND r.rolname = :'migrator_user'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
\gexec

SELECT format('GRANT USAGE, SELECT ON SEQUENCE %I.%I TO %I', n.nspname, c.relname, :'app_user')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_roles r ON r.oid = c.relowner
WHERE n.nspname = 'public'
  AND r.rolname = :'migrator_user'
  AND c.relkind = 'S'
\gexec

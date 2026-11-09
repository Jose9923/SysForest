\getenv target_database SYSFOREST_TARGET_DATABASE
\getenv app_user DB_USERNAME
\getenv migrator_user DB_MIGRATOR_USERNAME

SELECT format('CREATE DATABASE %I OWNER %I', :'target_database', :'migrator_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'target_database')
\gexec

SELECT format('ALTER DATABASE %I OWNER TO %I', :'target_database', :'migrator_user')
\gexec

-- Las consultas directas usan Bogotá; la conexión ORM fija explícitamente UTC.
SELECT format('ALTER DATABASE %I SET timezone TO %L', :'target_database', 'America/Bogota')
\gexec

SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'target_database')
\gexec

SELECT format('GRANT CONNECT ON DATABASE %I TO %I', :'target_database', :'app_user')
\gexec

#!/bin/bash
set -euo pipefail

: "${PGUSER:?Falta PGUSER}"
: "${DB_DATABASE:?Falta DB_DATABASE}"
: "${DB_TEST_DATABASE:?Falta DB_TEST_DATABASE}"
: "${DB_USERNAME:?Falta DB_USERNAME}"
: "${DB_MIGRATOR_USERNAME:?Falta DB_MIGRATOR_USERNAME}"
: "${DB_PASSWORD:?Falta la contraseña del usuario de aplicación}"
: "${POSTGRES_PASSWORD_FILE:?Falta el secreto administrativo}"
: "${DB_MIGRATOR_PASSWORD_FILE:?Falta el secreto de migraciones}"

if [[ "$DB_DATABASE" == "$DB_TEST_DATABASE" \
    || "$DB_USERNAME" == "$DB_MIGRATOR_USERNAME" \
    || "$DB_USERNAME" == "$PGUSER" \
    || "$DB_MIGRATOR_USERNAME" == "$PGUSER" ]]; then
    printf '%s\n' 'Las bases y los roles de administración, migraciones y aplicación deben ser distintos.' >&2
    exit 1
fi

# psql obtiene los secretos del entorno; no se interpolan en SQL ni argumentos CLI.
PGPASSWORD=$(cat "$POSTGRES_PASSWORD_FILE")
SYSFOREST_MIGRATOR_PASSWORD=$(cat "$DB_MIGRATOR_PASSWORD_FILE")
export PGPASSWORD SYSFOREST_MIGRATOR_PASSWORD

if [[ -z "$PGPASSWORD" || -z "$SYSFOREST_MIGRATOR_PASSWORD" ]]; then
    printf '%s\n' 'Los secretos PostgreSQL no pueden estar vacíos.' >&2
    exit 1
fi

psql --no-psqlrc --quiet --set=ON_ERROR_STOP=1 --dbname=postgres \
    --file=/opt/sysforest/roles.sql

for SYSFOREST_TARGET_DATABASE in "$DB_DATABASE" "$DB_TEST_DATABASE"; do
    export SYSFOREST_TARGET_DATABASE
    psql --no-psqlrc --quiet --set=ON_ERROR_STOP=1 --dbname=postgres \
        --file=/opt/sysforest/database.sql
    psql --no-psqlrc --quiet --set=ON_ERROR_STOP=1 --dbname="$SYSFOREST_TARGET_DATABASE" \
        --file=/opt/sysforest/permissions.sql
done

printf '%s\n' 'Bases, PostGIS y permisos de SYSFOREST preparados.'

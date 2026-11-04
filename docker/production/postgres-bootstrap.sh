#!/usr/bin/env bash
set -euo pipefail

: "${PGUSER:?Falta PGUSER}"
: "${DB_DATABASE:?Falta DB_DATABASE}"
: "${DB_USERNAME:?Falta DB_USERNAME}"
: "${DB_MIGRATOR_USERNAME:?Falta DB_MIGRATOR_USERNAME}"
: "${DB_PASSWORD:?Falta la contraseña de aplicación}"
: "${POSTGRES_PASSWORD_FILE:?Falta secreto administrativo}"
: "${DB_MIGRATOR_PASSWORD_FILE:?Falta secreto migrador}"

if [[ "$DB_USERNAME" == "$DB_MIGRATOR_USERNAME" || "$DB_USERNAME" == "$PGUSER" || "$DB_MIGRATOR_USERNAME" == "$PGUSER" ]]; then
    printf '%s\n' 'Los roles de administración, migraciones y aplicación deben ser distintos.' >&2
    exit 1
fi
for identifier in "$PGUSER" "$DB_DATABASE" "$DB_USERNAME" "$DB_MIGRATOR_USERNAME"; do
    if [[ ! "$identifier" =~ ^[a-zA-Z][a-zA-Z0-9_]{0,62}$ ]]; then
        printf '%s\n' 'Los identificadores PostgreSQL no tienen un formato válido.' >&2
        exit 1
    fi
done

PGPASSWORD=$(cat "$POSTGRES_PASSWORD_FILE")
SYSFOREST_MIGRATOR_PASSWORD=$(cat "$DB_MIGRATOR_PASSWORD_FILE")
export PGPASSWORD SYSFOREST_MIGRATOR_PASSWORD
if [[ -z "$PGPASSWORD" || -z "$SYSFOREST_MIGRATOR_PASSWORD" ]]; then
    printf '%s\n' 'Las credenciales de PostgreSQL no pueden estar vacías.' >&2
    exit 1
fi

# Mismos privilegios mínimos del entorno local, aplicados únicamente a la base operativa.
psql --no-psqlrc --quiet --set=ON_ERROR_STOP=1 --dbname=postgres --file=/opt/sysforest/roles.sql
SYSFOREST_TARGET_DATABASE="$DB_DATABASE"
export SYSFOREST_TARGET_DATABASE
psql --no-psqlrc --quiet --set=ON_ERROR_STOP=1 --dbname=postgres --file=/opt/sysforest/database.sql
psql --no-psqlrc --quiet --set=ON_ERROR_STOP=1 --dbname="$DB_DATABASE" --file=/opt/sysforest/permissions.sql
printf '%s\n' 'Base operativa, PostGIS y roles preparados. No se ejecutaron migraciones ni seeders.'

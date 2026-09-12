#!/bin/sh
set -eu

if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$DB_PASSWORD_FILE" ]; then
        printf '%s\n' 'No se puede leer el secreto de la conexión PostgreSQL.' >&2
        exit 1
    fi
    DB_PASSWORD=$(cat "$DB_PASSWORD_FILE")
    export DB_PASSWORD
    unset DB_PASSWORD_FILE
fi

if [ -z "${APP_KEY:-}" ]; then
    printf '%s\n' 'APP_KEY no está configurada. Ejecuta bin/setup antes de iniciar SYSFOREST.' >&2
    exit 1
fi

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

exec "$@"

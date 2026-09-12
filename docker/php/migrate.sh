#!/bin/sh
set -eu

: "${DB_DATABASE:?Falta DB_DATABASE}"
: "${DB_TEST_DATABASE:?Falta DB_TEST_DATABASE}"

if [ "$DB_DATABASE" = "$DB_TEST_DATABASE" ]; then
    printf '%s\n' 'La base de pruebas debe ser distinta de la base operativa.' >&2
    exit 1
fi

php artisan migrate --force --no-interaction
DB_DATABASE="$DB_TEST_DATABASE" php artisan migrate --force --no-interaction

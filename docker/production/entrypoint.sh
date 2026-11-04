#!/bin/sh
set -eu

# Rechazar una configuración local antes de iniciar procesos persistentes o tareas manuales.
php -r '
$url = getenv("APP_URL") ?: "";
$parts = parse_url($url);
$key = getenv("APP_KEY") ?: "";
$valid = getenv("APP_ENV") === "production"
    && in_array(strtolower(getenv("APP_DEBUG") ?: ""), ["false", "0"], true)
    && filter_var($url, FILTER_VALIDATE_URL) !== false
    && is_array($parts) && ($parts["scheme"] ?? "") === "https"
    && !isset($parts["user"]) && !isset($parts["pass"])
    && !isset($parts["query"]) && !isset($parts["fragment"])
    && in_array($parts["path"] ?? "", ["", "/"], true)
    && str_starts_with($key, "base64:")
    && strlen(base64_decode(substr($key, 7), true) ?: "") === 32
    && strlen(getenv("JWT_SECRET") ?: "") >= 32
    && in_array(strtolower(getenv("SESSION_SECURE_COOKIE") ?: ""), ["true", "1"], true);
if (!$valid) {
    fwrite(STDERR, "Configuración productiva inválida: revisa entorno, HTTPS, claves y cookies seguras.\n");
    exit(1);
}
'
exec sysforest-entrypoint "$@"

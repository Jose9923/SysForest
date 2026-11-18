"""Configuración del visor privado; no contiene credenciales de la base."""

SERVER_MODE = True
UPGRADE_CHECK_ENABLED = False
SHOW_GRAVATAR_IMAGE = False
ALLOW_SAVE_PASSWORD = False
ENABLE_PSQL = False
SERVER_PASSEXEC_COMMANDS = {}
SESSION_COOKIE_HTTPONLY = True
SESSION_COOKIE_SAMESITE = 'Lax'
# El navegador usa HTTP en localhost a través del túnel SSH, nunca un puerto público.
SESSION_COOKIE_SECURE = False
LOGIN_BANNER = 'SysForest · visor privado de consulta. Acceso exclusivo para personal autorizado.'

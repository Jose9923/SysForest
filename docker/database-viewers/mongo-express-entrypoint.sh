#!/bin/sh
set -eu

# Los secretos entran como archivos; no aparecen en Compose ni en argv/inspect.
cd /app
exec node --input-type=module - <<'JS'
import fs from 'node:fs';

function secret(name, minimum = 1) {
  let value;
  try {
    value = fs.readFileSync(`/run/secrets/${name}`, 'utf8').replace(/\r?\n$/, '');
  } catch {
    throw new Error(`No se puede leer el secreto requerido: ${name}`);
  }
  if (value.length < minimum || /[\r\n\0]/.test(value)) {
    throw new Error(`El secreto requerido no tiene un formato válido: ${name}`);
  }
  return value;
}

const password = secret('mongo_viewer_password', 24);
process.env.ME_CONFIG_MONGODB_URL = `mongodb://sysforest_audit_viewer:${encodeURIComponent(password)}@mongodb:27017/sysforest_audit?authSource=sysforest_audit&directConnection=true`;
process.env.ME_CONFIG_MONGODB_ENABLE_ADMIN = 'false';
process.env.ME_CONFIG_BASICAUTH = 'true';
process.env.ME_CONFIG_BASICAUTH_ENABLED = 'true';
process.env.ME_CONFIG_BASICAUTH_USERNAME = secret('mongo_express_username');
process.env.ME_CONFIG_BASICAUTH_PASSWORD = secret('mongo_express_password', 24);
process.env.ME_CONFIG_SITE_COOKIESECRET = secret('mongo_cookie_secret', 32);
process.env.ME_CONFIG_SITE_SESSIONSECRET = secret('mongo_session_secret', 32);
process.env.ME_CONFIG_OPTIONS_READONLY = 'true';
process.env.ME_CONFIG_OPTIONS_NO_DELETE = 'true';
process.env.ME_CONFIG_OPTIONS_NO_RAW_COMMAND = 'true';
process.env.ME_CONFIG_SITE_GRIDFS_ENABLED = 'false';
process.env.VCAP_APP_HOST = '0.0.0.0';
process.env.PORT = '8081';
process.env.ME_CONFIG_REQUEST_SIZE = '100kb';
process.argv = [process.execPath, '/app/app.js'];
await import('/app/app.js');
JS

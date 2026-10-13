/* Comprueba autenticación y lectura sin credenciales en argumentos ni contenido de registros. */
'use strict';

try {
    const fs = require('fs');
    const auditDb = db.getSiblingDB(process.env.AUDIT_MONGO_DATABASE);
    const password = fs.readFileSync(process.env.AUDIT_MONGO_PASSWORD_FILE, 'utf8').trim();
    if (!auditDb.auth(process.env.AUDIT_MONGO_USERNAME, password)) {
        quit(1);
    }
    auditDb.getCollection(process.env.AUDIT_MONGO_COLLECTION).findOne({ _id: '__sysforest_healthcheck__' }, { _id: 1 });
    quit(0);
} catch (error) {
    // Los errores de conexión pueden contener parámetros: el healthcheck solo devuelve su estado.
    quit(1);
}

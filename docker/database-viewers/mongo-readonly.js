/* Solo administración de una identidad de consulta; no crea ni cambia documentos o índices. */
'use strict';

const fs = require('fs');
const marker = 'SYSFOREST_DATABASE_VIEWER_READONLY_V1';
const roleName = 'sysforest_audit_viewer_read';
const databaseName = process.env.AUDIT_MONGO_DATABASE;
const collectionName = process.env.AUDIT_MONGO_COLLECTION;
const applicationUser = process.env.AUDIT_MONGO_USERNAME;
const viewerUser = process.env.MONGO_VIEWER_USERNAME;
const adminUser = process.env.MONGO_ROOT_USERNAME;

function requireIdentifier(value) {
    if (typeof value !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/.test(value)) {
        throw new Error('Configuración de nombres MongoDB inválida.');
    }
}

function worked(result) {
    if (!result || Number(result.ok) !== 1) {
        throw new Error('MongoDB no confirmó la preparación de la cuenta de consulta.');
    }
    return result;
}

function normalizedPrivileges(privileges) {
    return privileges.map((privilege) => ({
        resource: privilege.resource,
        actions: [...privilege.actions].sort(),
    })).sort((first, second) => JSON.stringify(first.resource).localeCompare(JSON.stringify(second.resource)));
}

try {
    [databaseName, collectionName, applicationUser, viewerUser, adminUser].forEach(requireIdentifier);
    if (['admin', 'config', 'local'].includes(databaseName)
        || viewerUser === applicationUser || viewerUser === adminUser) {
        throw new Error('El visor debe usar una cuenta exclusiva en la base de auditoría.');
    }

    // El secreto del visor llega como JSON {"password":"64 hex"} por stdin.
    // El archivo opcional permite al instalador usar un secreto montado.
    const viewerPassword = process.env.MONGO_VIEWER_PASSWORD_FILE
        ? fs.readFileSync(process.env.MONGO_VIEWER_PASSWORD_FILE, 'utf8').trim()
        : JSON.parse(fs.readFileSync(0, 'utf8')).password;
    if (typeof viewerPassword !== 'string' || !/^[0-9a-f]{64}$/.test(viewerPassword)) {
        throw new Error('El secreto del visor debe contener exactamente 64 caracteres hexadecimales.');
    }
    if (!process.env.MONGO_ROOT_PASSWORD_FILE) {
        throw new Error('Falta el archivo privado de autenticación administrativa.');
    }
    const adminPassword = fs.readFileSync(process.env.MONGO_ROOT_PASSWORD_FILE, 'utf8').trim();
    // El instalador ejecuta mongosh --nodb dentro del servicio Mongo existente.
    const connection = new Mongo('mongodb://127.0.0.1:27017');
    const adminDb = connection.getDB('admin');
    worked(adminDb.auth({ user: adminUser, pwd: adminPassword }));

    const auditDb = connection.getDB(databaseName);
    if (auditDb.getCollectionInfos({ name: collectionName }).length !== 1) {
        throw new Error('La colección de auditoría debe existir; este instalador no la crea.');
    }

    const privileges = [
        { resource: { db: databaseName, collection: collectionName }, actions: ['find', 'collStats', 'listIndexes'] },
        { resource: { db: databaseName, collection: '' }, actions: ['listCollections', 'dbStats'] },
    ];
    const oldRole = auditDb.getRole(roleName, { showPrivileges: true });
    if (oldRole !== null && (oldRole.roles.length !== 0
        || JSON.stringify(normalizedPrivileges(oldRole.privileges)) !== JSON.stringify(normalizedPrivileges(privileges)))) {
        throw new Error('El rol de consulta existente tiene un alcance diferente; se requiere revisión.');
    }
    const oldUser = auditDb.getUser(viewerUser);
    if (oldUser !== null && oldUser.customData?.managedBy !== marker) {
        throw new Error('La cuenta existente no pertenece a este instalador.');
    }
    const users = worked(auditDb.runCommand({ usersInfo: 1, showCredentials: false })).users;
    if (users.some((user) => user.user !== viewerUser
        && user.roles.some((role) => role.role === roleName && role.db === databaseName))) {
        throw new Error('El rol del visor está compartido con otra cuenta; se requiere revisión.');
    }

    if (oldRole === null) {
        worked(auditDb.runCommand({ createRole: roleName, privileges, roles: [] }));
    }
    const userSettings = {
        pwd: viewerPassword,
        roles: [{ role: roleName, db: databaseName }],
        customData: { managedBy: marker },
        mechanisms: ['SCRAM-SHA-256'],
    };
    worked(auditDb.runCommand(oldUser === null
        ? { createUser: viewerUser, ...userSettings }
        : { updateUser: viewerUser, ...userSettings }));
    print('Visor MongoDB preparado para consulta exclusiva de la auditoría, sin escritura.');
} catch (error) {
    // No imprimir excepciones de driver, comandos, objetos de usuario o secretos.
    print('No se pudo preparar la cuenta MongoDB de consulta; revisar configuración e identidades existentes.');
    quit(1);
}

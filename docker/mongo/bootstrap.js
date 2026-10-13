/* Inicialización privada de auditoría. El entrypoint oficial solo ejecuta esto en un volumen nuevo. */
'use strict';

const fs = require('fs');
const databaseName = process.env.AUDIT_MONGO_DATABASE;
const collectionName = process.env.AUDIT_MONGO_COLLECTION;
const username = process.env.AUDIT_MONGO_USERNAME;
const roleName = 'sysforest_audit_append_read';

function commandWorked(result) {
    if (result.ok !== 1) {
        throw new Error('No se pudo preparar el esquema de auditoría MongoDB.');
    }
}

for (const identifier of [databaseName, collectionName, username]) {
    if (typeof identifier !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/.test(identifier)) {
        throw new Error('Configuración de nombres MongoDB inválida.');
    }
}

const password = fs.readFileSync(process.env.AUDIT_MONGO_PASSWORD_FILE, 'utf8').trim();
if (password.length < 32) {
    throw new Error('El secreto de auditoría debe contener al menos 32 caracteres.');
}

const auditDb = db.getSiblingDB(databaseName);
const validator = {
    $jsonSchema: {
        bsonType: 'object',
        required: [
            '_id', 'schema_version', 'source', 'id', 'organization_id', 'actor_user_id',
            'occurred_at', 'occurred_at_iso', 'event', 'resource_type', 'resource_id',
            'before_values', 'after_values', 'metadata', 'context', 'version', 'payload_hash',
        ],
        additionalProperties: false,
        properties: {
            _id: { bsonType: 'string', minLength: 1, maxLength: 150 },
            schema_version: { bsonType: 'int', enum: [1] },
            source: { bsonType: 'string', minLength: 1, maxLength: 50 },
            id: { bsonType: 'string', minLength: 1, maxLength: 100 },
            organization_id: { bsonType: ['string', 'null'] },
            actor_user_id: { bsonType: ['string', 'null'] },
            occurred_at: { bsonType: 'date' },
            occurred_at_iso: {
                bsonType: 'string',
                pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$',
            },
            event: { bsonType: 'string', minLength: 1, maxLength: 255 },
            resource_type: { bsonType: ['string', 'null'] },
            resource_id: { bsonType: ['string', 'null'] },
            before_values: { bsonType: ['object', 'null'] },
            after_values: { bsonType: ['object', 'null'] },
            metadata: { bsonType: 'object' },
            context: { bsonType: 'object' },
            version: { bsonType: ['int', 'long', 'null'] },
            payload_hash: { bsonType: 'string', pattern: '^[a-f0-9]{64}$' },
        },
    },
};

if (auditDb.getCollectionInfos({ name: collectionName }).length === 0) {
    auditDb.createCollection(collectionName, {
        validator,
        validationLevel: 'strict',
        validationAction: 'error',
    });
} else {
    commandWorked(auditDb.runCommand({
        collMod: collectionName,
        validator,
        validationLevel: 'strict',
        validationAction: 'error',
    }));
}

commandWorked(auditDb.runCommand({ createIndexes: collectionName, indexes: [
    { key: { occurred_at_iso: -1, source: 1, id: -1 }, name: 'audit_timeline' },
    { key: { organization_id: 1, occurred_at_iso: -1, source: 1, id: -1 }, name: 'audit_organization_timeline' },
    { key: { event: 1, occurred_at_iso: -1 }, name: 'audit_event_timeline' },
    { key: { resource_type: 1, resource_id: 1, occurred_at_iso: -1 }, name: 'audit_resource_timeline' },
    { key: { actor_user_id: 1, occurred_at_iso: -1 }, name: 'audit_actor_timeline' },
    { key: { source: 1, organization_id: 1, resource_type: 1, resource_id: 1, event: 1, 'metadata.revision_id': 1 }, name: 'audit_revision_lookup' },
] }));

// La aplicación puede insertar y consultar exclusivamente esta colección; no actualizar ni borrar.
const privileges = [{ resource: { db: databaseName, collection: collectionName }, actions: ['find', 'insert'] }];
if (auditDb.getRole(roleName) === null) {
    auditDb.createRole({ role: roleName, privileges, roles: [] });
} else {
    auditDb.updateRole(roleName, { privileges, roles: [] });
}

const roles = [{ role: roleName, db: databaseName }];
if (auditDb.getUser(username) === null) {
    auditDb.createUser({ user: username, pwd: password, roles });
} else {
    auditDb.updateUser(username, { roles });
}
print('Auditoría MongoDB preparada con escritura por adición y lectura restringida.');

# Mantenimientos e intervenciones

## Alcance y fuentes

Fase 9 implementa el §27 de la solicitud original: órdenes creadas manualmente para un Tree, tipos nacionales existentes, prioridad, programación, responsable institucional, ejecución, resultado e imágenes antes/después. No se generan órdenes a partir de inspecciones. La matriz Excel continúa en 94/94; Maintenance no agrega variables ficticias a ese instrumento.

El usuario autorizó continuar tras la entrega F8. Las transiciones y prioridades siguientes concretan un flujo operativo inicial, no fórmulas ni exigencias forestales inferidas del Excel. Se comunicaron antes de implementar: Official opera; OrganizationAdmin/SuperAdmin verifican; tala y traslado no alteran automáticamente estado, geometría o inspecciones de Tree. La verificación describe una revisión interna, no reemplaza permisos de intervención de autoridades externas.

## Modelo e integridad

Migration000015 crea maintenances, maintenance_revisions y maintenance_status_histories. Maintenance contiene UUID, organization_id, tree_id, maintenance_type_id, código humano MNT-000001, status, priority, scheduled_date, performed_date, assigned_to, notes, result, created_by/updated_by, lock_version, timestamps y SoftDeletes con deleted_by/deletion_notes. Se reutiliza organization_sequences con el nombre maintenance; su reserva comparte la transacción de creación. El código es único por organización incluso después de anular.

UUID, organización, árbol, código, created_by y created_at son inmutables en PostgreSQL. FK compuestas protegen árbol y responsable: (organization_id,assigned_to) referencia (organization_id,user_id) de organization_memberships. Los CHECK protegen valores, versión y datos mínimos de estado; triggers rechazan hard delete, transiciones inválidas, correcciones operativas de órdenes finalizadas y referencias nuevas inactivas. Ninguna tabla de historial admite UPDATE/DELETE.

Migration000016 añade maintenance_id a Evidence, su FK tenant e índice. Exactly one owner se expresa mediante num_nonnulls(tree_id,tree_inspection_id,maintenance_id)=1; no hay polimorfismo sin FK. El rollback de esta migración se niega si hay fotografías de mantenimiento, incluidas anuladas: no las borra ni reasigna. Las pruebas de rollback se realizan exclusivamente en sysforest_testing.

## Estados y prioridades

La creación siempre produce pending, aunque ya se conozcan fecha y responsable. Introducir esos datos no cambia silenciosamente el estado.

| Origen | Destinos permitidos | Condiciones principales |
| --- | --- | --- |
| pending | scheduled, assigned, cancelled | Programar exige fecha; asignar exige responsable; cancelar exige motivo. |
| scheduled | assigned, cancelled | El responsable debe continuar vigente. |
| assigned | scheduled, in_progress, cancelled | Reprogramar retira la asignación; iniciar revalida responsable. |
| in_progress | executed, cancelled | Ejecutar exige responsable vigente, fecha real no futura y resultado. |
| executed | verified | Solo administradores autorizados verifican. |
| verified, cancelled | Ninguno | Conservan sus datos operativos. |

Prioridades: low (Baja), normal (Normal), high (Alta), urgent (Urgente). Normal es el valor inicial. Son etiquetas organizativas; no disparan plazos, alarmas ni evaluaciones automáticas de riesgo. Las fechas son fechas de calendario, sin hora. La comprobación de fecha de ejecución futura usa el día UTC del servidor; una intervención puede haberse realizado antes de su fecha programada.

Notas, tipo, prioridad, programación y asignación se corrigen en estados abiertos, con versión y auditoría. Resultado y fecha realizada sólo se registran al ejecutar; no pueden introducirse mediante edición general. Executed/verified/cancelled conservan sus datos; no existe reapertura silenciosa.

## Responsable y autorización

El responsable debe pertenecer a la organización, con membresía vigente, cuenta activa/verificada y rol OFFICIAL u ORGANIZATION_ADMIN activo. Una referencia histórica puede conservarse al corregir otros datos; asignar, iniciar y ejecutar vuelven a verificar su elegibilidad. SuperAdmin no recibe una membresía artificial para poder asignarse una orden.

Hay siete permisos nuevos, 43 en total:

| Rol | Consultar | Crear/editar/avanzar | Verificar | Anular/restaurar registro |
| --- | --- | --- | --- | --- |
| SUPER_ADMIN | Tenant seleccionado | Sí | Sí | Sí |
| ORGANIZATION_ADMIN | Su organización | Sí | Sí | Sí |
| OFFICIAL | Su organización | Sí | No | No |
| DEPARTMENT_ADMIN | Su departamento | No | No | No |

Permisos: maintenances.view/create/update/transition/verify/void/restore. Tenerlos nunca sustituye TreePolicy ni TenantContext. Se consulta el actor y su concesión vigentes en cada escritura. Funcionarios autorizados pueden colaborar con órdenes creadas o asignadas a colegas; created_by nunca cambia y el actor real queda en historial. El índice exige también trees.view y oculta órdenes de árboles anulados a quienes carecen de acceso histórico. Los flags internos de consulta no se aceptan del navegador.

## Cancelación, anulación y restauración

Cancelled expresa que una intervención no se realizará y requiere motivo; sigue visible y no utiliza SoftDelete. Void corrige un registro creado por error, requiere motivo y administración, conserva estado, resultado, revisiones y archivos. No se reutiliza automáticamente el catálogo de motivos de Tree.

Restore conserva UUID, código, autor y estado; exige autorización y árbol no anulado. Nunca restaura fotografías que se anularon independientemente. Árbol anulado impide nuevas órdenes, edición, transiciones y nuevas fotografías; su consulta histórica sigue TreePolicy.

## Arquitectura, concurrencia y auditoría

Controller → Service → Repository → Model. MaintenanceService coordina casos de uso; MaintenanceWriteService transacciones/locks/versión; MaintenanceWorkflowService transiciones; MaintenanceAuditService revisiones; MaintenanceReadService prepara datos para Blade. Repositorios separados resuelven órdenes, referencias, revisiones e historial. MaintenanceInput comparte reglas con Form Requests y llamadas internas.

El bloqueo sigue actor → organización → Tree → Maintenance. El responsable se valida con locks compartidos NOWAIT para evitar esperar por otra cuenta que a su vez espera la organización. Si no se puede confirmar esa referencia, la operación se revierte y devuelve conflicto. Una lock_version obsoleta devuelve409 sin sobrescribir cambios. El lote de escritura, reserva de código, historial y revisión se confirma en una transacción.

Cada creación, corrección, transición, anulación y restauración genera una revisión append-only con actor, tenant, momento, versión y valores anteriores/nuevos. Las transiciones tienen además historial de estados y motivo. security_events registra el UUID del recurso; no contiene fotografías, credenciales ni rutas físicas. Blade recibe etiquetas y diferencias preparadas por el Service, con referencias resueltas en lotes.

## Interfaz y fotografías

La navegación institucional incorpora Mantenimientos. Hay listado paginado de20 con filtros por árbol, tipo, estado, prioridad, responsable, fecha programada, texto y anuladas según permiso; formulario por Tree; detalle, acciones, auditoría y galería. La ficha del árbol muestra las diez órdenes activas más recientes y enlace al listado completo. Select2 se usa para tipo y responsable; las transiciones funcionan con formularios Blade y no requieren lógica de negocio JavaScript.

Las imágenes utilizan el mismo flujo privado F8, tipos EVD_MAINTENANCE_BEFORE y EVD_MAINTENANCE_AFTER, selección individual por archivo y almacenamiento existente. No se exige una cantidad arbitraria para ejecutar ni se duplica la foto en Tree/Inspection. Una fotografía de una orden verificada sigue siendo evidencia independiente, con su propio autor, clasificación, versión, anulación y restauración. Consultar fotos de una orden anulada requiere acceso histórico a la orden.

## Límites de esta entrega

No hay generación automática de órdenes, planificación recurrente, calendario externo, cuadrillas, costes, notificaciones por correo de mantenimiento ni alteraciones automáticas del árbol. Los disparadores y destinatarios de notificaciones quedan por definir; la infraestructura Mail/Queue existente no envía avisos nuevos por esta fase. No se implementan mapas generales, portal público, reportes ni exportaciones. Cualquier regla futura de reapertura, verificación independiente o prioridad con plazos deberá incorporarse explícitamente y con auditoría.

Resultados ejecutados y estado de servicios: [Fase 9](phase-9.md). Almacenamiento y límites de imágenes: [file-storage.md](file-storage.md).

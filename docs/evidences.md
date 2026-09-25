# Evidencias fotográficas

## Alcance y relación con el instrumento

Una Evidence representa un archivo de fotografía y una clasificación nacional. Pertenece exactamente a Tree, TreeInspection o Maintenance; nunca a varios propietarios ni a ninguno. Cada propietario admite cero o múltiples fotografías. No hay BLOB ni columnas de fotografías en árboles, inspecciones o fauna.

CN4 «FOTO FAUNA» corresponde a TreeInspection + EVD_FAUNA. CO4 «FOTO INDIVIDUO» corresponde a TreeInspection + EVD_WHOLE_TREE. Las dos evidencias documentan la visita del instrumento; Tree admite adicionalmente fotografías permanentes de perfil, independientes. El sistema no copia automáticamente una foto de una inspección al árbol. Fauna fotográfica coexiste con inspection_fauna sin inventar especies individuales ni enlaces entre una fotografía y una especie.

Se reutilizan los doce EvidenceTypes nacionales de Fase 5. Cada archivo exige un tipo independiente. Un tipo inactivo se muestra históricamente y puede conservarse al corregir la misma evidencia; no se admite en cargas nuevas ni al cambiar a otro tipo inactivo. La disponibilidad se valida en Service/Repository y mediante trigger PostgreSQL con FOR SHARE.

## Modelo y restricciones

evidences contiene UUID; organization_id; evidence_type_id; tree_id/tree_inspection_id/maintenance_id; disk/path; original_name/mime_type/extension/size_bytes; width/height/checksum_sha256; caption/notes/captured_at; metadata JSONB; uploaded_by/updated_by; lock_version; timestamps; deleted_at/deleted_by/deletion_notes. uploaded_by es obligatorio. Captura desconocida, notas y dimensiones admiten NULL en el esquema; el flujo actual calcula dimensiones para todos sus formatos.

`CHECK (num_nonnulls(tree_id,tree_inspection_id,maintenance_id)=1)` fija el propietario. Las FK `(organization_id,tree_id)`, `(organization_id,tree_inspection_id)` y `(organization_id,maintenance_id)` impiden cruces institucionales. `UNIQUE(disk,path)` conserva la identidad física incluso después de anular. La tabla prohíbe hard delete; trigger protege UUID, organización, propietario, uploaded_by/created_at y procedencia técnica del archivo. Una nueva imagen se carga como nueva Evidence: la edición no sustituye archivos ni cambia de propietario.

evidence_revisions registra una versión por cambio, actor, organización, momento y valores anteriores/nuevos. Lleva FK compuesta a Evidence y restricciones append-only. Se auditan propietario, tipo, descripciones, fecha y ciclo de vida; no contiene binarios, rutas, tokens ni EXIF. Los eventos son evidence.uploaded, evidence.updated, evidence.voided y evidence.restored; security_events conserva además el UUID del recurso.

## Arquitectura

EvidenceController coordina los Requests con EvidenceService, EvidenceReadService y EvidenceDeliveryService. EvidenceService valida y carga lotes; EvidenceWriteService organiza transacciones y versiones; EvidenceOwnerService resuelve propietarios; EvidenceAuditService registra revisiones. ImageMetadataService valida contenido y calcula datos técnicos. EvidenceStorageService/EvidenceStorageJournal encapsulan Filesystem. EvidenceRecoveryService reconcilia únicamente intentos pendientes registrados; también cierra journals cuyo archivo ya tiene una referencia confirmada.

EvidenceRepository concentra lecturas, filtros, escrituras, referencias físicas y locks. EvidenceTypeRepository prepara opciones actuales/históricas y valida disponibilidad. EvidenceRevisionRepository persiste y consulta revisiones. No hay consultas desde Blade, lógica forestal en JavaScript ni imágenes incrustadas en el código de la aplicación. Los fixtures visuales son imágenes técnicas de pruebas y no datos del piloto.

La lectura prepara DTOs con URLs de aplicación; no serializa Model directamente. Tree, TreeInspection y Maintenance tienen relaciones evidences. Evidence mantiene las relaciones tree y maintenance con registros anulados para autorizar su lectura histórica. El repositorio permite lotes de propietarios, galerías de seis elementos y listas paginadas de veinte; carga tipos, autores y propietarios antes de Blade. Una página de galería comparte propietario y estado, por lo que las acciones se resuelven una vez por página, evitando consultas de permisos por tarjeta.

## Tenant y permisos

| Permiso | SUPER_ADMIN | DEPARTMENT_ADMIN | ORGANIZATION_ADMIN | OFFICIAL |
| --- | --- | --- | --- | --- |
| evidences.view | Organización seleccionada | Organización de su departamento | Su organización | Su organización |
| evidences.create | Sí | No | Sí | Sí |
| evidences.update | Sí | No | Sí | Sí |
| evidences.delete | Sí | No | Sí | Sí |
| evidences.restore | Sí | No | Sí | No |

Los cinco permisos de evidencias establecidos en Fase 8 conservan sus concesiones en Fase 9. Tener permiso de evidencia nunca sustituye la autorización del propietario. El contexto se establece en servidor; un organization_id enviado por navegador se rechaza. Los UUID se buscan dentro del tenant antes de autorizar o validar contenido. SUPER_ADMIN selecciona tenant sin membresía artificial; DEPARTMENT_ADMIN mantiene lectura territorial. Una cuenta sin concesión/membresía vigente queda excluida.

Official puede corregir o anular la evidencia de un colega de la misma institución; uploaded_by no cambia y updated_by identifica al último actor. No se exige campaña activa para cargar o corregir evidencia. Tree raíz anulado impide cargas nuevas y correcciones, incluso si el propietario es una inspección histórica que un administrador puede leer. La lectura histórica conserva TreePolicy. Restaurar Tree no restaura evidencias anuladas independientemente.

## HTTP y formularios

Rutas privadas: GET `/{trees|inspections|maintenances}/{uuid}/evidences`, GET la misma ruta con `/create`, POST la ruta de colección; GET/PUT/DELETE `/evidences/{uuid}`; POST `/evidences/{uuid}/restore`; GET `/evidences/{uuid}/view` y `/download`. Exigen JWT, sesión, correo verificado, tenant y Policy. La carga admite treinta solicitudes por minuto; no sustituye los límites de bytes/archivos.

StoreEvidenceRequest recibe `items[i][file]`, `items[i][evidence_type_id]` y caption/notes/captured_at opcionales. Cada fila tiene su Select2 sin tipo preseleccionado, preview, quitar y errores. XHR envía el lote y muestra progreso total real. Un 422 conserva los archivos seleccionados y sus descripciones. No hay reintento automático tras un timeout ambiguo: se indica consultar la galería. Sin JavaScript hay formulario de una imagen. La selección múltiple y el botón de cámara son opciones separadas; cámara solicita `capture=environment`, no obliga a usarla ni promete soporte idéntico en todos los dispositivos.

Caption admite 2000 caracteres; notes 20000; deletion_notes 2000. Captured_at es opcional, ingresado explícitamente en UTC con precisión de minuto (`YYYY-MM-DDTHH:mm`), validado como calendario y almacenado timestamptz. No se infiere desde fecha de modificación ni EXIF. La interfaz escapa nombres, captions y notas; conserva el valor textual «0».

UpdateEvidenceRequest, VoidEvidenceRequest y RestoreEvidenceRequest exigen lock_version entero positivo; se valida antes de convertirlo. Una versión obsoleta devuelve 409: «La evidencia fue modificada por otro usuario.» El orden de bloqueo es actor → organización → Tree → TreeInspection o Maintenance, cuando corresponda → Evidence. Todas las escrituras de metadata/ciclo y auditoría se confirman en una transacción.

## Anulación, restauración y archivos

Anular guarda deleted_at/deleted_by/deletion_notes y oculta la evidencia de la galería normal. El archivo no se borra. Solo quien puede restaurar accede a la lista de anuladas y a sus originales, además de autorización del propietario. Restore comprueba que el archivo exista y preserva UUID, ruta y cargador; si falta, devuelve un error en español y no cambia el registro. No se reutilizan deletion_reasons de Tree.

En la galería institucional no hay miniaturas persistidas: sus tarjetas solicitan el original autorizado con lazy loading. La variante pública de Fase 11 se describe al final. Descarga utiliza attachment con UUID seguro; vista utiliza inline con MIME calculado, nosniff, CSP restrictiva y Cache-Control private/no-store. No hay ETag/304 que evada autorización, URLs físicas en HTML ni symlink público. Los detalles del protocolo de almacenamiento, compensación, MIME, límites, memoria y persistencia están en [file-storage.md](file-storage.md).

## Evolución acotada

Fase 8 se entregó sin is_public ni portal público: todos sus originales eran privados. Fase 11 incorpora la publicación explícita y variantes saneadas descritas más adelante, conservando esa entrega privada y excluyendo uploaded_by, email, disk/path, EXIF, auditoría y metadata privada del portal. Cambiar el disk lógico evidences a S3-compatible implica configurar el adaptador y copiar las claves relativas; no reescribir Tree/Evidence. La dependencia S3 no está instalada todavía.

La anulación formal de una inspección continúa pendiente de definición.

## Mantenimientos: extensión de Fase 9

Maintenance es el tercer propietario explícito. Sus fotografías antes y después utilizan los tipos nacionales existentes EVD_MAINTENANCE_BEFORE y EVD_MAINTENANCE_AFTER. No se copian al árbol ni a una inspección y no sustituyen los destinos CN/CO del instrumento. Cada imagen mantiene su tipo, original, autor, checksum, metadata y revisiones independientes.

La carga y la edición intersectan los permisos de evidencia con MaintenancePolicy.view, su organización y TreePolicy.view. Requieren orden y árbol raíz sin anular. Los estados ejecutado y verificado no impiden añadir fotografías ni corregir su descripción. Anular una orden conserva sus fotos y archivos privados; su consulta histórica exige maintenances.restore además de evidences.view y acceso al árbol. La anulación/restauración independiente de una Evidence conserva los controles evidences.delete/evidences.restore. Restaurar una orden no restaura automáticamente evidencias anuladas.

La migración 000016 agrega la FK compuesta, el índice de galería por organización/mantenimiento, el CHECK de tres propietarios y maintenance_id al trigger de identidad. Su rollback toma un bloqueo exclusivo y se rechaza si existe cualquier evidencia de mantenimiento, incluso anulada; nunca elimina archivos ni reasigna propietarios para permitir una reversión.

Las firmas anteriores conservan su orden. Las APIs agregan el nuevo argumento opcional al final:

| API | Firma ampliada |
| --- | --- |
| EvidenceService.upload | `(actor, treeId, inspectionId, input, maintenanceId = null)` |
| EvidenceOwnerService.resolve | `(actor, treeId, inspectionId, lock = false, maintenanceId = null)` |
| EvidenceReadService.index | `(actor, treeId, inspectionId, input = [], maintenanceId = null)` |
| authorizeUpload / uploadForm | `(actor, treeId, inspectionId, maintenanceId = null)` |
| EvidenceRepository.forOwner / paginateForOwner | `(organizationId, treeId, inspectionId, filters = [], maintenanceId = null)` |
| summaryForOwner | `(organizationId, treeId, inspectionId, limit = 6, maintenanceId = null)` |
| forOwners | `(organizationId, treeIds = [], inspectionIds = [], filters = [], maintenanceIds = [])` |

Para una orden se envían treeId e inspectionId como NULL. gallery admite el Model Maintenance y proyecta rutas maintenances.evidences.*, con retorno a maintenances.show. maintenance_id enviado como atributo del formulario se rechaza: sólo el recurso de la ruta autorizada establece el propietario. Las rutas privadas de descarga y vista por UUID de Evidence permanecen iguales. Las pruebas de integración nuevas están en MaintenanceEvidenceTest; la verificación conjunta se registra en el informe de Fase 9.

## Publicación explícita: extensión de Fase 11

La incorporación del portal no cambia el propietario, la identidad técnica ni la entrega privada del original. La migración 000017 añade is_public=false, publication_version, public_id y public_caption a Evidence. Los registros existentes y las cargas nuevas permanecen privados; los formularios ordinarios rechazan esos atributos. PublicationService es el único flujo administrativo de publicación y exige publication.manage, tenant vigente y versión. OrganizationAdmin opera en su entidad; SuperAdmin selecciona entidad; Official y DepartmentAdmin no publican.

Public_id es un ULID independiente del UUID interno: se reserva al publicar por primera vez y no cambia al ocultar/restaurar. Public_caption es una descripción explícita distinta de caption; no se rellena automáticamente con texto privado. Las modificaciones de publicación se auditan completas en publication_revisions, con FK real, actor, tenant, fecha y before/after, además de un evento mínimo publication.changed. La auditoría privada evidence_revisions de Fase 8 mantiene sus eventos originales.

La foto solo aparece si ella y toda su cadena son publicables. Tree requiere organización publicada/activa, flag propio, ausencia de SoftDelete y participación en una campaña pública disponible fuera de Borrador. Una foto de inspección exige además la publicación de esa visita y de su campaña asociada, cuando existe. Una foto de mantenimiento exige orden ejecutada/verificada y no anulada. Publicar Tree o ejecutar una orden nunca publica automáticamente sus fotografías.

GET /portal/media/{public_id} entrega exclusivamente un WebP reencodeado desde píxeles: GD retira metadatos originales y aplica orientación EXIF JPEG. No entrega rutas físicas, nombre original, autores, SHA privado ni EXIF/XMP/IPTC. Original y checksum permanecen sin cambios. La fecha pública es la fecha declarada de captured_at; no se extrae de EXIF. PublicEvidenceResource incluye URL, tipo, public_caption, fecha y alt; width/height son null porque la orientación puede intercambiar las dimensiones originales.

El derivado se cachea en archivos privados del servidor, con namespace específico y caducidad por defecto de una hora. Cada GET revalida publicación antes de consultar caché y antes del 304. ETag deriva de los bytes públicos y Cache-Control usa public,max-age=0,must-revalidate. Ocultar la foto o cualquiera de sus propietarios provoca 404 en la siguiente solicitud, incluso si el navegador envía un ETag anterior. Los originales siguen teniendo private,no-store y autorización institucional; no se habilita storage público.

Cambiar active o anular oculta el recurso por la cadena; no borra la autorización guardada. Restaurar un propietario puede recuperar visibilidad de hijos aún autorizados, pero no restaura fotografías anuladas independientemente. Una autorización de publicación debe retirarse expresamente cuando se quiera revocarla más allá del estado temporal del propietario.

La política completa de campos y propietarios está en [public-data-policy.md](public-data-policy.md). La anulación formal de inspecciones sigue pendiente; el portal no inventa ese ciclo.

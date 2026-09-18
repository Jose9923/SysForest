# Evidencias fotográficas

## Alcance y relación con el instrumento

Una Evidence representa un archivo de fotografía y una clasificación nacional. Pertenece exactamente a Tree o TreeInspection, nunca a ambos ni a ninguno. Cada propietario admite cero o múltiples fotografías. No hay BLOB ni columnas de fotografías en árboles, inspecciones o fauna.

CN4 «FOTO FAUNA» corresponde a TreeInspection + EVD_FAUNA. CO4 «FOTO INDIVIDUO» corresponde a TreeInspection + EVD_WHOLE_TREE. Las dos evidencias documentan la visita del instrumento; Tree admite adicionalmente fotografías permanentes de perfil, independientes. El sistema no copia automáticamente una foto de una inspección al árbol. Fauna fotográfica coexiste con inspection_fauna sin inventar especies individuales ni enlaces entre una fotografía y una especie.

Se reutilizan los doce EvidenceTypes nacionales de Fase 5. Cada archivo exige un tipo independiente. Un tipo inactivo se muestra históricamente y puede conservarse al corregir la misma evidencia; no se admite en cargas nuevas ni al cambiar a otro tipo inactivo. La disponibilidad se valida en Service/Repository y mediante trigger PostgreSQL con FOR SHARE.

## Modelo y restricciones

evidences contiene UUID; organization_id; evidence_type_id; tree_id/tree_inspection_id; disk/path; original_name/mime_type/extension/size_bytes; width/height/checksum_sha256; caption/notes/captured_at; metadata JSONB; uploaded_by/updated_by; lock_version; timestamps; deleted_at/deleted_by/deletion_notes. uploaded_by es obligatorio. Captura desconocida, notas y dimensiones admiten NULL en el esquema; el flujo actual calcula dimensiones para todos sus formatos.

`CHECK (num_nonnulls(tree_id,tree_inspection_id)=1)` fija el propietario. Las FK `(organization_id,tree_id)` y `(organization_id,tree_inspection_id)` impiden cruces institucionales. `UNIQUE(disk,path)` conserva la identidad física incluso después de anular. La tabla prohíbe hard delete; trigger protege UUID, organización, propietario, uploaded_by/created_at y procedencia técnica del archivo. Una nueva imagen se carga como nueva Evidence: la edición no sustituye archivos ni cambia de propietario.

evidence_revisions registra una versión por cambio, actor, organización, momento y valores anteriores/nuevos. Lleva FK compuesta a Evidence y restricciones append-only. Se auditan propietario, tipo, descripciones, fecha y ciclo de vida; no contiene binarios, rutas, tokens ni EXIF. Los eventos son evidence.uploaded, evidence.updated, evidence.voided y evidence.restored; security_events conserva además el UUID del recurso.

## Arquitectura

EvidenceController coordina los Requests con EvidenceService, EvidenceReadService y EvidenceDeliveryService. EvidenceService valida y carga lotes; EvidenceWriteService organiza transacciones y versiones; EvidenceOwnerService resuelve propietarios; EvidenceAuditService registra revisiones. ImageMetadataService valida contenido y calcula datos técnicos. EvidenceStorageService/EvidenceStorageJournal encapsulan Filesystem. EvidenceRecoveryService reconcilia únicamente intentos pendientes registrados; también cierra journals cuyo archivo ya tiene una referencia confirmada.

EvidenceRepository concentra lecturas, filtros, escrituras, referencias físicas y locks. EvidenceTypeRepository prepara opciones actuales/históricas y valida disponibilidad. EvidenceRevisionRepository persiste y consulta revisiones. No hay consultas desde Blade, lógica forestal en JavaScript ni imágenes incrustadas en el código de la aplicación. Los fixtures visuales son imágenes técnicas de pruebas y no datos del piloto.

La lectura prepara DTOs con URLs de aplicación; no serializa Model directamente. Tree y TreeInspection tienen relaciones evidences. El repositorio permite lotes de propietarios, galerías de seis elementos y listas paginadas de veinte; carga tipos, autores y propietarios antes de Blade. Una página de galería comparte propietario y estado, por lo que las acciones se resuelven una vez por página, evitando consultas de permisos por tarjeta.

## Tenant y permisos

| Permiso | SUPER_ADMIN | DEPARTMENT_ADMIN | ORGANIZATION_ADMIN | OFFICIAL |
| --- | --- | --- | --- | --- |
| evidences.view | Organización seleccionada | Organización de su departamento | Su organización | Su organización |
| evidences.create | Sí | No | Sí | Sí |
| evidences.update | Sí | No | Sí | Sí |
| evidences.delete | Sí | No | Sí | Sí |
| evidences.restore | Sí | No | Sí | No |

Existen 36 permisos totales: cinco nuevos respecto de Fase 7. Tener permiso de evidencia nunca sustituye la autorización del propietario. El contexto se establece en servidor; un organization_id enviado por navegador se rechaza. Los UUID se buscan dentro del tenant antes de autorizar o validar contenido. SUPER_ADMIN selecciona tenant sin membresía artificial; DEPARTMENT_ADMIN mantiene lectura territorial. Una cuenta sin concesión/membresía vigente queda excluida.

Official puede corregir o anular la evidencia de un colega de la misma institución; uploaded_by no cambia y updated_by identifica al último actor. No se exige campaña activa para cargar o corregir evidencia. Tree raíz anulado impide cargas nuevas y correcciones, incluso si el propietario es una inspección histórica que un administrador puede leer. La lectura histórica conserva TreePolicy. Restaurar Tree no restaura evidencias anuladas independientemente.

## HTTP y formularios

Rutas privadas: GET `/{trees|inspections}/{uuid}/evidences`, GET la misma ruta con `/create`, POST la ruta de colección; GET/PUT/DELETE `/evidences/{uuid}`; POST `/evidences/{uuid}/restore`; GET `/evidences/{uuid}/view` y `/download`. Exigen JWT, sesión, correo verificado, tenant y Policy. La carga admite treinta solicitudes por minuto; no sustituye los límites de bytes/archivos.

StoreEvidenceRequest recibe `items[i][file]`, `items[i][evidence_type_id]` y caption/notes/captured_at opcionales. Cada fila tiene su Select2 sin tipo preseleccionado, preview, quitar y errores. XHR envía el lote y muestra progreso total real. Un 422 conserva los archivos seleccionados y sus descripciones. No hay reintento automático tras un timeout ambiguo: se indica consultar la galería. Sin JavaScript hay formulario de una imagen. La selección múltiple y el botón de cámara son opciones separadas; cámara solicita `capture=environment`, no obliga a usarla ni promete soporte idéntico en todos los dispositivos.

Caption admite 2000 caracteres; notes 20000; deletion_notes 2000. Captured_at es opcional, ingresado explícitamente en UTC con precisión de minuto (`YYYY-MM-DDTHH:mm`), validado como calendario y almacenado timestamptz. No se infiere desde fecha de modificación ni EXIF. La interfaz escapa nombres, captions y notas; conserva el valor textual «0».

UpdateEvidenceRequest, VoidEvidenceRequest y RestoreEvidenceRequest exigen lock_version entero positivo; se valida antes de convertirlo. Una versión obsoleta devuelve 409: «La evidencia fue modificada por otro usuario.» El orden de bloqueo es actor → organización → Tree → TreeInspection → Evidence. Todas las escrituras de metadata/ciclo y auditoría se confirman en una transacción.

## Anulación, restauración y archivos

Anular guarda deleted_at/deleted_by/deletion_notes y oculta la evidencia de la galería normal. El archivo no se borra. Solo quien puede restaurar accede a la lista de anuladas y a sus originales, además de autorización del propietario. Restore comprueba que el archivo exista y preserva UUID, ruta y cargador; si falta, devuelve un error en español y no cambia el registro. No se reutilizan deletion_reasons de Tree.

No hay miniaturas persistidas: las tarjetas solicitan el original autorizado con lazy loading. Descarga utiliza attachment con UUID seguro; vista utiliza inline con MIME calculado, nosniff, CSP restrictiva y Cache-Control private/no-store. No hay ETag/304 que evada autorización, URLs físicas en HTML ni symlink público. Los detalles del protocolo de almacenamiento, compensación, MIME, límites, memoria y persistencia están en [file-storage.md](file-storage.md).

## Evolución acotada

No hay is_public ni portal público en Fase 8: el estado seguro es privado. Una fase posterior deberá definir publicación explícita y variantes saneadas; su DTO público excluirá uploaded_by, email, disk/path, EXIF, auditoría y metadata privada. Cambiar el disk lógico evidences a S3-compatible implica configurar el adaptador y copiar las claves relativas; no reescribir Tree/Evidence. La dependencia S3 no está instalada todavía.

Fase 9 agregará maintenance_id nullable, UNIQUE(organization_id,id) en Maintenance y FK compuesta `(organization_id,maintenance_id)`. Reemplazará el CHECK por num_nonnulls(tree_id,tree_inspection_id,maintenance_id)=1 y ampliará owner resolver, políticas, repositorios, auditoría y galerías. Deberá ajustar el trigger de identidad para incluir maintenance_id. No se crea ninguna tabla Maintenance anticipada. La anulación formal de una inspección continúa pendiente de definición.

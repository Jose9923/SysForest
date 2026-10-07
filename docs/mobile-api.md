# API móvil de SysForest

Implementada y ampliada el 7 de octubre de 2026 con edición, visitas y fotografías privadas. Contrato versionado en `/api/mobile/v1`, separado de las cookies web y de la API pública. La guía para el integrador está en `docs/SysForest_API_movil.docx`.

## Operación

La instancia local sirve `http://127.0.0.1:8080/api/mobile/v1`. Docker publica Nginx solo en loopback: un teléfono físico requiere una URL de backend alcanzable y HTTPS. Este cambio no publica un servicio externo ni modifica puertos, DNS, certificados o secretos.

Las cuentas se administran con el módulo de usuarios existente. La aplicación móvil recibe un `organization_id` autorizado en su configuración; el inicio de sesión exige correo, contraseña y ese UUID. La cuenta debe estar activa, verificada y tener acceso institucional vigente. El inventario se elige entre los devueltos por la API, sin deducirlo del nombre de usuario.

## Rutas

| Método | Ruta bajo `/api/mobile/v1` | Autenticación |
| --- | --- | --- |
| POST | `/auth/login` | JSON email, password, organization_id |
| POST | `/auth/refresh` | JSON refresh_token |
| POST | `/auth/logout` | JSON refresh_token; 204 idempotente |
| GET | `/auth/me` | Authorization Bearer |
| GET | `/inventories` | Authorization Bearer |
| GET | `/parameterizations` | Authorization Bearer |
| GET | `/parameterizations/{catalog}` | Authorization Bearer |
| POST | `/inventories/{inventory}/trees/sync` | Authorization Bearer |
| GET | `/inventories/{inventory}/trees` | Authorization Bearer |
| GET | `/trees/{tree}` | Authorization Bearer |
| PATCH | `/trees/{tree}` | Authorization Bearer |
| GET | `/trees/{tree}/inspections` | Authorization Bearer |
| GET | `/inspections/{inspection}` | Authorization Bearer |
| POST | `/trees/{tree}/inspections` | Authorization Bearer |
| PATCH | `/inspections/{inspection}` | Authorization Bearer |
| POST | `/trees/{tree}/photos` | Authorization Bearer y multipart |
| GET | `/trees/{tree}/photos` | Authorization Bearer |
| GET | `/photos/{photo}/content` | Authorization Bearer |

El JWT incluye `sub`, `sid`, `org`, `client=mobile`, audiencia móvil y vencimiento. La firma pertenece al JWT transportado en Authorization; HTTPS protege la petición completa. La app no recibe el secreto de firma. El backend revalida sesión, usuario y acceso a la organización en cada petición. La audiencia y `client_type` impiden usar tokens/refresh móviles en la autenticación web o viceversa. Los refresh son opacos, se guardan con hash, rotan y revocan la sesión si se reutiliza uno consumido; no deben renovarse concurrentemente. Una pérdida de respuesta de refresh puede requerir login nuevo.

Los POST y PATCH exigen JSON objeto de hasta 1 MiB, excepto la carga de fotos, que usa multipart/form-data. Los límites por usuario son 90 lecturas/minuto, 20 lotes de sincronización/minuto y 30 escrituras/minuto compartidas entre edición, visitas y fotos. Login/refresh usan los límites de identidad configurados. Se responde JSON incluso sin Accept. Las respuestas son privadas y no se cachean.

## Sincronización

El sobre contiene `items`, una lista de 1 a 100 capturas. Cada item contiene `client_id` UUID permanente generado al capturar, `tree` y opcionalmente `inspection`. La aplicación debe conservar la cuenta, organización, inventario, UUID y contenido de cada pendiente.

`tree.current_status` es obligatorio. El código `active` se presenta como **Sano**; utiliza las etiquetas del manifiesto de parametrizaciones para mostrar los estados. Los códigos técnicos permanecen compatibles. Se reutilizan las reglas de TreeInput, catálogos activos, coordenadas y servicios forestales. La fecha GPS `captured_at` debe indicar segundos y zona ISO, por ejemplo `2026-10-07T09:30:00-05:00`. `inspection.inspection_date` es obligatorio si se envía inspección; los decimales de mediciones se envían como cadenas, con punto y hasta seis decimales. Los resultados derivados se calculan en el servidor.

El inventario se impone desde la ruta y se busca por la organización del JWT. Usuario, autoría, IDs de servidor, publicación, versión y vínculo de inspección no se pueden suministrar en el item, ni siquiera con null. La autorización actual es institucional: no existe una nueva asignación exclusiva de inventario por funcionario. `created_by` e `included_by` identifican al responsable autenticado.

Cada captura confirma árbol, participación, inspección opcional, auditoría y recibo en una sola transacción. Una captura fallida no deja árbol parcial. Capturas válidas de un lote mixto sí se conservan. Permisos exigidos: `inventories.view`, `trees.create` y, cuando se envía inspección, `inspections.create`.

`mobile_tree_receipts` conserva una clave única por organización y client_id, autor, inventario, hash canónico y confirmación. Bloqueos actor → organización → inventario serializan escrituras concurrentes. Un reenvío idéntico del mismo usuario y destino devuelve el recibo original; cambiar contenido, usuario o destino genera conflicto. La comparación ignora el orden de claves JSON, pero conserva tipos, valores y orden de listas. El UUID del destino se normaliza a minúsculas. Un recibo confirmado puede recuperarse aunque luego se cierre la campaña; capturas nuevas requieren inventario activo.

HTTP 200 en el sobre no implica que todas las capturas estén guardadas. `summary` contiene received, created, replayed y failed. Cada elemento de `results` incluye client_id, status y http_status: created/201, replayed/200, rejected/422, conflict/409 o forbidden/403. Solo created y replayed permiten retirar el pendiente. Sobre inválido 422, acceso inválido 401/403, inventario ajeno 404, exceso de tamaño 413, Content-Type incorrecto 415, límite 429. Una falla general después de confirmar items previos se resuelve reenviando los mismos UUID y contenido.

El endpoint de lotes conserva su contrato de altas nuevas. La edición y las fotografías usan las rutas específicas descritas a continuación. No se debe generar otro client_id para ocultar un conflicto, porque podría duplicar el individuo.

## Edición y visitas

El móvil consulta árboles de un inventario con paginación de 50 por defecto y máximo 100; la ficha devuelve campos explícitos, participaciones y `lock_version`. Los árboles anulados no se incluyen. Una identidad Tree puede participar en varios inventarios: editarla modifica la identidad compartida, no crea una copia de campaña.

PATCH `/trees/{tree}` recibe `operation_id` UUID, `lock_version` esperado y `changes` con campos editables. Omitir campos conserva valores; null solo vacía campos que lo admiten. Las coordenadas requieren tipo y motivo de cambio; los estados conservan las reglas comunes de motivos y auditoría. No se aceptan IDs internos, autores, publicación, resultados derivados o cambios de participación en inventario. Se reutiliza TreeService y TreePolicy, incluidas las restricciones de campaña activa para los roles que las necesitan.

Cada mutación confirma escritura, auditoría y `mobile_mutation_receipts` en la misma transacción. La clave única es organización + operation_id, ligada a acción, destino, autor y hash de versión/contenido. Un reintento idéntico devuelve status `replayed` sin aplicar otra escritura ni incrementar versión. Primero se revalidan los permisos actuales sobre el recurso. El recibo devuelve el snapshot confirmado: si hubo ediciones posteriores, la app consulta GET para cargar la versión vigente antes de preparar otra operación.

HTTP 409 distingue `code=version_conflict` (versión antigua), `code=idempotency_conflict` (UUID confirmado con otro contenido/autor/destino/acción) y el fallback `code=conflict`. Para una versión antigua, conservar el cambio pendiente, descargar la ficha vigente, resolver diferencias y preparar una nueva edición con su versión y un nuevo operation_id. Un error de idempotencia requiere revisar la operación original; no se arregla cambiando el UUID a ciegas. El lote original de altas agrega el mismo código de idempotencia a sus resultados 409.

Las mediciones pertenecen a TreeInspection. GET `/trees/{tree}/inspections` usa paginación 50/máximo 100 y GET `/inspections/{inspection}` devuelve sus entradas, derivados, inventario y versión. POST `/trees/{tree}/inspections` recibe `{operation_id, inventory_id, inspection}` y crea una visita con fecha obligatoria. El servidor resuelve la participación y exige que el árbol ya pertenezca al inventario autorizado. Conserva la política existente, que permite registrar visitas sin exigir que la campaña siga activa. Responde 201 created o 200 replayed. PATCH `/inspections/{inspection}` recibe `{operation_id, lock_version, changes}` para corregir una visita; no permite trasladarla a otro inventario. Los derivados se recalculan con las reglas originales y las revisiones se conservan.

Las capacidades `can_update_trees`, `can_update_inspections` y `can_upload_photos` se agregaron a meta de inventarios. Orientan la interfaz; cada solicitud revalida políticas sobre el recurso concreto.

## Fotografías privadas

Primero se confirma el árbol y se guarda su `tree_id`. Después se envía una fotografía por POST `/trees/{tree}/photos` con multipart/form-data y los campos `client_id`, `file`, `evidence_type_id`; son opcionales `caption`, `notes` y `captured_at`. El Content-Type debe incluir el boundary que construye la biblioteca HTTP. No se envía base64 ni se incrustan fotos en el lote JSON de árboles.

Se aceptan JPEG, PNG y WebP con contenido, extensión, decodificación y dimensiones verificados. El límite predeterminado es 10 MiB por archivo y 20 millones de píxeles; el sobre admite esos 10 MiB más 64 KiB. Los valores provienen de config/evidence.php. HEIC debe convertirse antes del primer envío. La app conserva exactamente el archivo convertido y sus metadatos para reintentar, sin recomprimirlo en cada intento. captured_at usa ISO 8601 con segundos y zona Z/offset, y se normaliza a UTC. No se aceptan campos o archivos adicionales, IDs internos, organización o autor enviados por el cliente.

Cada foto tiene un client_id UUID permanente propio. La clave única del recibo es organización + client_id; se comprueban árbol, autor, SHA256 de bytes y metadatos normalizados. Primera carga: HTTP 201 `{data:{client_id,status:created,photo:{...}}}`. Reenvío idéntico: 200 replayed con la misma foto; otro contenido o destino: 409 idempotency_conflict. Un simple cambio de nombre de archivo o representación equivalente de zona horaria conserva identidad. Se mantiene la fecha/autor de carga y la auditoría.

Archivo, evidencia y recibo se coordinan con la transacción existente y el journal de almacenamiento. No se reintenta automáticamente una transacción que escribió archivos. Ante fallo posterior, la reconciliación consulta referencias DB bajo lock para borrar únicamente archivos no confirmados. Un commit incierto no provoca borrar una foto confirmada. Se mantiene la reconciliación operativa existente.

GET `/trees/{tree}/photos` devuelve galería privada paginada de 20 elementos. Cada foto tiene content_url relativo; el GET de contenido exige Bearer y se devuelve no-store. La API no expone rutas internas de almacenamiento ni URLs públicas. Las fotos de este contrato pertenecen al árbol; no tienen una participación de inventario o visita propia. Las evidencias web existentes del árbol también aparecen si los permisos lo permiten.

El móvil conserva colas independientes para altas, ediciones, visitas y fotos, todas separadas por cuenta e institución. Solo retira un pendiente después de recibir created/updated/replayed según la operación. Una foto fallida no obliga a volver a crear el árbol.

## Parametrizaciones

El manifiesto enumera los 14 catálogos y los estados. Los catálogos y los inventarios usan `page`, `per_page` (máximo 500) y búsqueda `q`; se devuelven referencias activas y padres activos. Incluyen especies, familias, nombres comunes, emplazamientos, hábitos de crecimiento, orígenes, categorías/condiciones físicas y fitosanitarias, fauna y estados de madurez. El contrato de cada colección está en el Word.

Se descarga paginación completa para uso offline. No hay token de snapshot, delta ni garantía de instantánea entre páginas. Una referencia desactivada después de descargar se rechaza al sincronizar: actualizar catálogos y corregir el item sin cambiar su UUID mientras no se haya confirmado.

## Instalación y verificación

Las migraciones 000022, 000023, 000024 y 000025 son aditivas y se aplicaron en la base operativa local. Las dos últimas agregan recibos de mutaciones y fotografías. Sesiones preexistentes permanecen web. No se modificaron `.env`, JWT_SECRET, datos forestales históricos ni la publicación privada por defecto. En otro ambiente se debe aplicar código y migraciones coordinadamente:

```bash
docker compose run --rm --no-deps init php artisan migrate --force --no-interaction
docker compose exec -T app php artisan route:list --path=api/mobile/v1 --except-vendor
bash bin/check
```

Validación de la entrega inicial (antes de la ampliación):

- `bin/check` terminó en 0: PSR-12 en 504 archivos, sintaxis PHP en 505 y JavaScript en 24.
- 36 comprobaciones manuales de autenticación: login, firma/claims, separación web/móvil, renovación, reuso, revocación, cuenta/membresía y formato HTTP. Transacción revertida y conteos de sesiones/auditoría conservados.
- 18 comprobaciones manuales del flujo HTTP de sincronización: árbol/visita/GPS, autoría, reintento, UUID mayúsculas, referencias internas null, conflicto, rollback por visita inválida, lote parcial, aislamiento y campañas cerradas. Conteos de árboles, inspecciones, recibos y sesiones conservados después de rollback.
- Lectura de los 14 catálogos con paginación, permisos de funcionario, inventarios institucionales, búsqueda literal y errores 404/422.
- HTTP real por Nginx: ruta privada sin token devuelve 401 JSON/no-store; `/health/ready` devuelve estado ok.

No se recrearon suites automatizadas retiradas por el usuario. No se verificó desde un teléfono físico ni sobre un despliegue HTTPS externo. No se ejecutó una prueba de carga ni de concurrencia multiproceso; la exclusión de reintentos se apoya en bloqueos transaccionales y restricción única de PostgreSQL.

### Verificación de la ampliación

- bin/check finalizó en 0: PSR-12 en 528 archivos, sintaxis PHP en 529 y JavaScript en 24.
- 35 comprobaciones de servicios de edición y visitas, con rollback: versiones, recibos, GPS, cálculos, validadores, permisos y replay bloqueado sobre árbol anulado.
- 24 comprobaciones HTTP del framework para consulta, PATCH, control de versiones, replay con snapshot histórico, códigos de conflicto, GPS con motivo, visitas y aislamiento de institución. Rollback conservó árboles, visitas, recibos, sesiones y auditoría.
- 20 comprobaciones HTTP de fotos para multipart, reenvío sin duplicado, bytes íntegros, descarga privada, códigos 409, MIME, extensiones, campos/archivos adicionales, límites y aislamiento. Almacenamiento temporal quedó vacío y DB revertida.
- 20 comprobaciones de servicio de fotos, incluida inyección de fallo tras escribir archivo y antes de confirmar recibo: compensación correcta sin borrar archivos confirmados.
- 20 comprobaciones de compatibilidad de sincronización anterior, incluida corrección del validador anidado para conservar el rango BIGINT de número de fustes.

No se hizo una carrera multiproceso ni prueba de carga; la protección concurrente usa bloqueo institucional y unicidad PostgreSQL. Se conserva la limitación de validación desde teléfono físico y despliegue HTTPS externo. Los ejercicios fueron temporales, sin recrear suites automatizadas.

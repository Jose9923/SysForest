# API móvil de SysForest

Implementada y ampliada el 7 de octubre de 2026 con edición, visitas y fotografías privadas. Contrato versionado en `/api/mobile/v1`, separado de las cookies web y de la API pública. La guía para el integrador está en `docs/SysForest_API_movil.docx`.

## Operación

El origen de producción es `https://sysforest.varcor.tech` y API_BASE es `https://sysforest.varcor.tech/api/mobile/v1`. El dominio y el acceso HTTPS fueron verificados el 4 de noviembre de 2026. La instalación local conserva `http://127.0.0.1:8080/api/mobile/v1` exclusivamente para pruebas; no es accesible desde otro dispositivo sin la configuración de red de desarrollo.

Las cuentas se administran con el módulo de usuarios existente. Desde la ampliación del 3 de noviembre de 2026, la app recibe la URL del servidor desde su archivo de configuración de compilación; la pantalla solicita correo y contraseña. Consulta primero las instituciones autorizadas y muestra sus nombres cuando existe más de una; una sola se selecciona automáticamente. La cuenta debe estar activa, verificada y tener acceso institucional vigente. El inventario se elige entre los devueltos por la API, sin deducirlo del nombre de usuario.

El UUID sigue siendo la identidad interna de la institución: el cliente obtiene `organization_id` del listado autenticado y lo envía al login existente. No se escribe ni se deduce del nombre en la pantalla. El contrato anterior de `POST /auth/login` se conserva para otros clientes.

## Configuración del cliente para producción

config/production.json configura SYSFOREST_API_URL=https://sysforest.varcor.tech y ALLOW_LOCAL_HTTP=false para SysForest Mobile 0.2.1+3. config.example.json conserva una plantilla HTTPS y config.local.json permite una copia de configuración excluida de Git. El perfil config/development.json apunta a http://127.0.0.1:8080 con HTTP local permitido; un teléfono requiere el túnel de depuración para usar ese perfil. No incluya contraseñas, tokens ni secretos del servidor en estos JSON.

El cambio de origen exige un nuevo acceso. Las sesiones trasladadas se revocaron y las contraseñas comunes de desarrollo se retiraron en producción; no basta con sustituir la URL y reutilizar un JWT o refresh anterior. La cuenta y sus permisos se conservan en la copia, pero los pendientes offline permanecen asociados a su servidor original.

Antes de actualizar, revisa si tienes capturas, visitas o fotos pendientes de la plataforma local y coordina su envío y traslado con el administrador. El servidor local y producción son instalaciones independientes: los cambios posteriores a la copia no se transfieren automáticamente.

Si al abrir la versión nueva aparece el aviso de otra plataforma, los datos locales siguen conservados. Para entrar a producción, abre el menú de cuenta, pulsa Cerrar sesión y vuelve a escribir tu correo y contraseña. Si hay varias instituciones, elige la que corresponda. Cerrar sesión conserva los pendientes de la cuenta anterior, pero no los traslada ni los envía al servidor nuevo.

Los pendientes de la plataforma local solo pueden enviarse usando una configuración y un servidor compatibles con su cuenta original. Solicita asistencia para resolverlos; no vuelvas a crear esos árboles en producción como solución al aviso. La compilación del APK no acredita por sí sola una prueba en un teléfono físico o en iPhone.

El correo transaccional está aplazado: la autenticación por contraseña funciona con una cuenta habilitada, pero invitaciones, verificación y recuperación requieren configurar la entrega SMTP.

## Rutas

| Método | Ruta bajo `/api/mobile/v1` | Autenticación |
| --- | --- | --- |
| POST | `/auth/organizations` | JSON email, password; sin sesión previa |
| POST | `/auth/login` | JSON email, password, organization_id |
| POST | `/auth/refresh` | JSON refresh_token |
| POST | `/auth/logout` | JSON refresh_token; 204 idempotente |
| GET | `/auth/me` | Authorization Bearer |
| GET | `/inventories` | Authorization Bearer |
| POST | `/inventories` | Authorization Bearer; creación idempotente |
| GET | `/inventories/{inventory}/maintenances` | Authorization Bearer; consulta paginada |
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

## Descubrimiento institucional para el inicio de sesión

`POST /auth/organizations` recibe correo y contraseña:

```json
{"email":"usuario@entidad.example","password":"contraseña del usuario"}
```

Con credenciales válidas responde HTTP 200 con `{"data":[{"id":"UUID","name":"Nombre de la institución","code":"Código interno"}]}`. El arreglo está ordenado por nombre e identificador, contiene todas las instituciones activas y no archivadas a las que la cuenta tiene acceso vigente, y puede estar vacío. La selección contempla membresías organizacionales y alcances administrativos nacionales/departamentales ya autorizados; nunca fabrica membresías ni concede escritura por un alcance de lectura. Cada candidata se revalida con `TenantContextService`, y el contexto se limpia después.

El endpoint no devuelve usuarios, correos, roles, tokens ni sesiones, y no publica un directorio consultable solo por correo. Contraseña incorrecta, cuenta desconocida, inactiva o sin correo verificado reciben el mismo HTTP 401 genérico. JSON inválido, campos ausentes y formatos incorrectos usan las validaciones normales; `organization_id`, `user_id`, `access_token` y `refresh_token` están prohibidos en esta consulta. Conserva JSON objeto hasta 1 MiB, `Cache-Control: private, no-store` y el límite `identity.login`, compartido con el login; ambos pasos consumen el límite de identidad.

El cliente mantiene la contraseña únicamente en memoria durante la selección. Si recibe una institución, puede llamar inmediatamente a `POST /auth/login` con sus credenciales y el `id`; si recibe varias, presenta un selector por nombre antes de llamar. Si recibe cero, informa que la cuenta no tiene instituciones disponibles. Cambiar correo o contraseña invalida las opciones anteriores. El listado no es una concesión permanente: el login verifica nuevamente contraseña, cuenta, institución y autorización antes de emitir el JWT firmado con `org`. Revocar el acceso o cambiar la contraseña entre ambos pasos impide el ingreso. No hay migración de base de datos ni cambios en sesiones ya emitidas.

## Inventarios y mantenimientos móviles · 4 de noviembre de 2026

`GET /inventories` incorpora en `meta` las capacidades `can_create_inventories` y `can_view_maintenances`. Son capacidades vigentes de la cuenta e institución; no conceden permisos nuevos. La app debe actualizar referencias mediante la sincronización manual antes de ofrecer funciones nuevas a una cuenta que solo conserva referencias de una versión anterior.

### Crear inventario

`POST /inventories` recibe `operation_id` (UUID estable) e `inventory`, con `code`, `name` y opcionalmente `description`, `starts_at`, `ends_at`, `status` y `active`. El cliente de campo crea campañas activas. Las fechas usan `AAAA-MM-DD`; código y nombre cumplen las reglas del catálogo institucional. Usuario, institución e identificador definitivo provienen del servidor.

Una respuesta 201 contiene `{operation_id, status: "created", data: {...inventario}}`. Repetir la misma operación confirmada devuelve 200 y `status: "replayed"`; reutilizar su UUID con otro contenido o usuario produce 409. El recibo durable se confirma en la misma transacción que el inventario. Un rechazo no borra capturas locales. La aplicación sincroniza primero el inventario y después sus árboles, resolviendo el ID definitivo antes de congelar las peticiones dependientes.

### Consultar mantenimientos

`GET /inventories/{inventory}/maintenances` acepta `tree_id`, `status`, `priority`, `q`, `page` y `per_page`. Devuelve `data` y `meta` de paginación. Cada orden incluye `id`, `code`, `tree_id`, `tree_code`, `maintenance_type_id`, `type_name`, `status`, `status_label`, `priority`, `priority_label`, `scheduled_date`, `performed_date`, `assignee_name`, `notes`, `result` y `updated_at`.

La consulta exige acceso vigente a la institución, inventario, árboles y mantenimientos. No devuelve órdenes anuladas ni de árboles fuera del inventario autorizado. El teléfono conserva la copia descargada y su fecha para consulta sin internet; una copia antigua no acredita el estado actual. Esta ampliación permite consultar mantenimientos; sus transiciones y cierre continúan en la web.

### Primera inspección en la captura

La nueva app envía siempre `inspection` dentro del item de creación de árbol. No usa una segunda escritura para esa primera visita. El recibo devuelve `tree_id` e `inspection_id`; un reintento conserva UUID y contenido. Se mantiene el contrato compatible para capturas ya preparadas por versiones anteriores que no incluían inspección. Las inspecciones posteriores continúan por `POST /trees/{tree}/inspections`.

## Catálogo de tipos de cambio de ubicación

La ampliación del 9 de noviembre de 2026 añade `location_change_types` al arreglo `data.catalogs` de `GET /parameterizations`. `GET /parameterizations/location_change_types` devuelve las opciones activas paginadas mediante la misma proyección de referencias: `id`, `code`, `name`, `description`, `active`, `sort_order` y `updated_at`. Conserva autenticación, permisos, filtros y límites del contrato existente. Es un catálogo adicional; no sustituye los estados del árbol.

Los códigos iniciales son `correction`, `relocation`, `transplant` y `removal`. El administrador nacional puede incorporar opciones con otros códigos estables y editar sus nombres. El cliente debe mostrar `name` y enviar **`code`**, no el UUID ni el nombre, en `changes.location_change_type` de `PATCH /trees/{tree}`. `changes.location_reason` conserva el motivo; `operation_id` y `lock_version` siguen siendo obligatorios para la mutación.

El servidor admite solo tipos activos para operaciones nuevas. Un tipo desactivado continúa en los historiales, pero no aparece en la descarga de opciones activas. Un recibo ya confirmado conserva su respuesta original y las reglas de replay existentes; actualizar el catálogo no reescribe recibos ni autoriza a regenerar UUID.

Un tipo y motivo explícitos pueden registrar el cambio aunque se conserven las coordenadas. `removal` no cambia `current_status`, no borra ubicación y no anula el árbol. Si se requiere otro estado, el cliente debe solicitarlo explícitamente mediante el flujo autorizado correspondiente.

Los instantes de la API siguen usando ISO con zona y su normalización UTC. La presentación web en Bogotá y las migraciones `000033`/`000034` no modifican las fechas calendario, la semántica de sincronización ni los contratos de los pendientes móviles. Esta ampliación backend no acredita por sí misma un selector nuevo en las APK ya publicadas. La implementación fue verificada antes de publicación; el [informe conjunto](change-catalog-bogota-20261109.md) registra su activación.

## Contexto analítico offline · 9 de noviembre de 2026

Los GET privados existentes incorporan datos de lectura para Gráficas en Android; no se añade un módulo Reportes ni se modifican los recibos. `GET /parameterizations` declara `supports_analytics_context: true` y, con consulta de árboles e inspecciones, añade los nombres `analytics_condition_categories`, `analytics_physical_conditions` y `analytics_phytosanitary_conditions` a `catalogs` y `analytics_catalogs`. Esas tres referencias incluyen inactivos y devuelven `meta.active_only: false`, `analytics_only: true`; los nombres originales siguen restringidos a activos para captura. Cada GET reautoriza institución, catálogos y ambos dominios.

Los GET de árboles agregan `analytics_reference` con especie/familia, nombres comunes y referencias de emplazamiento, hábito y orígenes, incluidos antecedentes inactivos. Los GET de visitas agregan `analytics` con `schema_version: 1`, identidad/versión de visita y árbol, especie/hábito/estado actuales, clasificación `{id,code,label}`, madurez, condiciones con órgano y contexto de carbono. Las masas analíticas se incluyen solo para un resultado `estimated` compatible de un árbol vivo; de lo contrario son `null`. El `carbon` histórico y el `risk` superior conservan sus contratos, sin cálculo ni confirmación por consultar.

El cliente debe validar IDs/versiones/insumos antes de sumar, mantener resultados pendientes separados y agrupar clase y versión como dimensiones distintas. La última visita es global del árbol antes de aplicar filtros; el historial por inventario incluye todas sus campañas autorizadas. Un snapshot de lectura por petición no garantiza la misma generación entre páginas distintas. Detalle de campos, permisos, catálogos, compatibilidad y comprobaciones: [contexto móvil para gráficas](mobile-analytics-20261109.md). Backend publicado y HTTPS aprobado el 9 de noviembre de 2026: manifiesto y tres catálogos completos, muestras de dos árboles y dos visitas mediante listado/detalle, rechazo sin Bearer, salud y cierre/revocación de la sesión temporal. Las huellas de 17 tablas de negocio/recibos permanecieron idénticas. No se ejecutaron migraciones, seeders ni cálculos por consultar. La publicación del APK se registra aparte.

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

## Resultados privados de riesgo y carbono

Ampliación compatible del 26 de octubre de 2026. GET `/trees/{tree}/inspections` y GET `/inspections/{inspection}` agregan `risk` y `carbon` a cada visita. Son instantáneas de solo lectura calculadas en servidor, asociadas a `lock_version`; no son campos de captura ni se aceptan como confirmación profesional móvil. Las rutas, JWT y permisos institucionales existentes se mantienen.

Consultar visitas exige `inspections.view` y acceso al árbol. Además, `risk` será `null` si la cuenta no tiene `risk.view` vigente. Esto no cambia los permisos de escritura. La metodología es la usada en la evaluación de esa visita; no necesariamente la última publicada por la institución. No se entrega la configuración institucional completa ni datos personales del profesional en estos objetos.

### Objeto risk

| Campo | Tipo y significado |
| --- | --- |
| `scope` | `phytosanitary_management_priority`. Prioridad de gestión fitosanitaria, no probabilidad de caída. |
| `status`, `label` | Código `unassessed`, `inconsistent`, `pending_confirmation` o `confirmed`, y etiqueta en español. |
| `inspection_version` | Entero que coincide con la versión de la visita consultada. |
| `methodology` | Objeto `{id, version, name, code}` de la metodología aplicada, o `null` si no hay evaluación. |
| `suggested_priority`, `suggested_label` | Nivel automático 1–4 y etiqueta, o `null` cuando no existe sugerencia válida. |
| `confirmed_priority`, `confirmed_label` | Nivel 1–4 decidido por el profesional y etiqueta, o `null` mientras no exista decisión. |
| `confirmed_at`, `evaluated_at` | Fechas ISO 8601, o `null`. Publicar no renueva la fecha de la decisión ni del cálculo original. |
| `coverage_status` | `complete`, `partial`, `missing` o `null`. Describe si las condiciones registradas participan en la metodología; **no certifica que se hayan inspeccionado todos los órganos**. |
| `evaluated_condition_count` | Cantidad de condiciones registradas consideradas por la metodología, o `null` sin evaluación. |
| `warnings`, `errors` | Listas de mensajes en español. Conservar visibles los avisos de cobertura y de condiciones excluidas. |
| `explanation` | Explicación de la evaluación, o `null`. |
| `requires_web_confirmation` | Booleano. La confirmación pendiente se realiza en SysForest web por un usuario autorizado. |
| `notice` | Aclaración del alcance del resultado. |

Sin condiciones consideradas por la metodología, `suggested_priority` es `null`: ausencia de observaciones no equivale a árbol sano. Una visita capturada en móvil permanece pendiente de decisión profesional. Si se confirma en la web una prioridad sin sugerencia, se exigen observaciones justificadas; una confirmación previa se conserva como histórica, sin convertirla en una observación nueva.

### Objeto carbon

| Campo | Tipo y significado |
| --- | --- |
| `scope` | `aboveground_stock`: carbono aéreo almacenado. |
| `status`, `label` | Código del estado de cálculo y explicación en español. `estimated` identifica una estimación; los pendientes conservan el motivo. |
| `inspection_version` | Entero de la revisión vigente de la visita. |
| `method_version` | Identificador de la fórmula aplicada, o `null` cuando no hay cálculo. |
| `biomass_kg`, `carbon_kg`, `co2_kg` | Cadenas decimales con seis posiciones en kg, o `null`. **No convertir `null` en cero**: mostrar «Sin estimación». |
| `reference_status`, `reference_label` | Estado técnico de la referencia y etiqueta: bibliográfica provisional, revisada por profesional o motivo pendiente. |
| `reference` | Referencia bibliográfica o técnica registrada para el perfil, o `null`. |
| `calculated_at` | Fecha ISO 8601 del cálculo original, o `null`. |
| `notice` | Alcance de la estimación y diferencia frente al total institucional. |

Los estados incluyen `unprocessed`, `missing_species`, `missing_habit`, `unsupported_habit`, `missing_profile`, `missing_density_reference`, `unresolved_identification`, `not_applicable`, `missing_stems`, `multiple_stems`, `missing_measurements`, `small_diameter` y `outside_range`. Usar `label` para presentarlos y admitir estados futuros sin fallar.

Cada objeto describe una visita. No sumar todas las visitas para obtener el stock institucional: la plataforma selecciona la última visita compatible por árbol y excluye los estados que no representan stock vivo. Las cifras no representan emisiones institucionales, absorción anual, raíces, suelo ni créditos de carbono. Las referencias bibliográficas siguen siendo provisionales hasta su revisión profesional para el contexto local.

### Sincronización y consulta sin conexión

POST/PATCH mantienen sus respuestas y recibos congelados. Los recibos existentes no se reescriben para agregar resultados nuevos. Después de una creación o corrección confirmada, consultar GET `/inspections/{id}` para obtener resultados y permisos vigentes. Repetir GET es seguro. Si la descarga falla, conservar el recibo confirmado: no repetir la escritura con otro UUID ni volver a marcarla como fallida por ese motivo.

SysForest móvil consulta la visita tras confirmar su creación y guarda el resultado local. También actualiza visitas al descargar el detalle del árbol. Muestra fecha de última consulta, estado de confirmación, avisos y referencias. Sin internet presenta la copia descargada; no recalcula ni confirma riesgo en el dispositivo. Una confirmación web posterior requiere descargar de nuevo el detalle para verla. Los clientes anteriores pueden ignorar estos campos adicionales.

### Verificación de esta ampliación

El 26 de octubre de 2026 se aprobaron 29 comprobaciones de servicios en una base PostgreSQL/PostGIS temporal, con fixtures sintéticos y reversión completa: publicación/despublicación, historial inmutable, confirmador original, rollback ante fallo, consistencia de consultas de reportes, proyección GET, permiso `risk.view`, aislamiento institucional, replay móvil sin duplicados y conservación de valores nulos. Estas comprobaciones no sustituyen pruebas HTTP completas ni validación en teléfonos físicos.


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

Las migraciones 000022, 000023, 000024 y 000025 son aditivas y se aplicaron en la base operativa local. Las dos últimas agregan recibos de mutaciones y fotografías. Sesiones preexistentes permanecen web. En aquella ampliación no se modificaron `.env`, JWT_SECRET, datos forestales históricos ni la publicación privada por defecto. El traslado del 4 de noviembre de 2026 utilizó configuración de producción propia, conservó APP_KEY y revocó las sesiones restauradas; consulta `docker/production/README.md`. En otro ambiente se debe aplicar código y migraciones coordinadamente:

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

## Confirmación de riesgo desde Android · 0.3.2

La API añade preview autenticado y decisión explícita opcional con metodología vigente en las escrituras móviles. Conserva clientes anteriores sin decisión, recibos durables, autorización en replay y reglas institucionales. La app revisa visitas existentes mediante PATCH+lock_version y mantiene sincronización solo manual. Ver [contrato, comprobaciones y límites](mobile-risk-20261104.md).


## Gestión del riesgo desde su propio módulo · 04/11/2026

Ampliación implementada y verificada localmente; publicación pendiente al redactar este apartado. Conserva los contratos y antecedentes anteriores. La bandeja consulta `GET /api/mobile/v1/risk/trees` con JWT móvil y los filtros siguientes:

| Parámetro | Contrato |
| --- | --- |
| `inventory_id` | UUID obligatorio de un inventario autorizado de la institución activa. |
| `page` | Entero desde 1; predeterminado 1. |
| `per_page` | Entero desde 1 hasta 100; predeterminado 100. |
| `q` | Búsqueda opcional, máximo 200 caracteres. |
| `risk_priority` | Opcional: `1`, `2`, `3`, `4`, `pending`, `inconsistent` o `unevaluated`. |

No admite `scope`, organización, territorio ni filtros adicionales enviados por el cliente. El inventario filtra la participación del árbol; su evaluación proviene de la última visita global del árbol, aunque esa visita corresponda a otra campaña. La selección y el orden determinista se comparten con la bandeja web, sin ampliar TenantContext ni exigir `reports.view`.

Responde `{data:[...],meta:{current_page,last_page,per_page,total,inventory_id,organization_id,generated_at}}`. Cada fila contiene `id`, `tree_id`, `tree_code`, `common_names`, `scientific_name`, `location_description`, `tree_status_label`, `inspection_id`, `inspection_version`, `inspection_date`, `risk_group`, `risk_priority_label`, `risk_suggestion_label`, `risk_confirmed_at` y `can_change_risk`. Los nombres comunes conservan la representación textual con separadores de línea. La proyección no incluye evidencia, carbono, auditoría ni identidad del confirmador. `inspection_id`/versión/fecha son nulos cuando no existe visita; eso no se interpreta como riesgo bajo y `can_change_risk` es falso.

La consulta exige `inventories.view`, `trees.view`, `inspections.view` y `risk.view`, con acceso al inventario. La edición además exige `inspections.update`. El modo departamental conserva consulta y no ofrece edición. El manifiesto y meta de inventarios anuncian `supports_risk_inbox`; meta añade `can_view_risk_inbox`. Las capacidades orientan la interfaz y cada solicitud reautoriza el recurso.

Al abrir **Cambiar riesgo**, consultar GET `/inspections/{id}` para obtener entradas y `lock_version` vigentes. El preview existente admite `require_latest_inspection: true` cuando se envía `inspection_id`; junto con `lock_version` rechaza una visita que ya no es la última. El campo se omite en previews de una visita nueva.

Para confirmar desde el módulo, PATCH `/inspections/{id}` conserva la misma inspección y recibe:

```json
{
  "operation_id": "UUID durable de esta operación",
  "lock_version": 3,
  "require_latest_inspection": true,
  "changes": {
    "risk_priority": 2,
    "risk_confirmed": true,
    "risk_methodology_id": "UUID de la metodología consultada",
    "general_observations": "Justificación profesional y observaciones de la misma visita."
  }
}
```

Los textos UUID del ejemplo son marcadores y deben sustituirse por referencias reales. La guardia de última visita se comprueba bajo los mismos bloqueos de escritura. Una operación nueva con última visita o versión obsoleta recibe 409 `version_conflict`; metodología obsoleta o decisión inválida mantiene el rechazo 422 existente. Sin sugerencia o al cambiarla se exige justificación. Las contradicciones siguen bloqueando la confirmación. `require_latest_inspection` es opcional para compatibilidad: las correcciones históricas anteriores siguen admitidas sin esta guardia. Un replay ya confirmado devuelve su recibo original aunque después haya otra visita; no vuelve a aplicar la decisión ni cambia el UUID.

La variante mayúscula/minúscula de un UUID válido identifica el mismo recurso, también con la guardia de última visita y al recuperar el recibo. HTTP de consulta sin JWT devuelve 401, filtros no admitidos 422 e inventario ajeno 404. Las pruebas locales aprobaron 60 comprobaciones de API, incluidas rutas HTTP reales, permisos, filtros, paginación, última visita, UUID y replay, con rollback íntegro y huellas idénticas de 16 tablas. Ver [alcance web, evidencia y límites](risk-module-20261104.md).


## Descarga completa por inventario · 05/11/2026

Ampliación activada y verificada por HTTPS el 05/11/2026; la publicación de la APK corresponde al paso posterior de la entrega. Permite que una sincronización manual descargue los inventarios autorizados de la institución activa, sus árboles, todas las visitas de esos árboles y sus fotografías autorizadas, sin consultar cada ficha individual. Conserva las rutas anteriores y no modifica permisos ni reglas de captura.

### Inventarios y capacidades

`GET /api/mobile/v1/inventories?include_all=1` incluye campañas activas, cerradas, archivadas y en borrador, también con `active=false`, siempre dentro del tenant autorizado. El parámetro booleano es opcional; omitido o falso mantiene el comportamiento anterior de campañas activas y disponibles. La paginación existente y búsqueda `q` se conservan. Leer una campaña cerrada no concede permiso de captura: cada escritura mantiene sus validaciones y políticas actuales.

El manifiesto de parametrizaciones (`data`) y la lista de inventarios (`meta`) anuncian `supports_inventory_history_reads: true`. La lista añade estas capacidades calculadas con los permisos vigentes:

| Campo en `meta` | Significado |
| --- | --- |
| `can_view_inventory_trees` | Puede consultar los árboles del inventario. |
| `can_view_inventory_inspections` | Puede consultar también sus visitas completas. |
| `can_view_inventory_photos` | Puede consultar fotografías privadas del árbol. |
| `inventory_photo_sources` | Orígenes autorizados: `tree`, y opcionalmente `inspection` y `maintenance`. Lista vacía si no puede consultar fotografías. |

Las capacidades orientan al cliente, pero no sustituyen la autorización de cada petición. Si el servidor no anuncia la nueva capacidad, el cliente no debe declarar descargado el historial completo usando solamente el resumen de árboles.

`GET /inventories/{inventory}/trees` ya devuelve la misma proyección completa que `GET /trees/{tree}`: campos de identidad, ubicación, estado, versión y participaciones. Añade a su `meta` `organization_id`, `inventory_id`, `has_more` y `generated_at`, conservando los campos existentes y el `inventory_id` superior. No es necesario hacer una petición adicional por árbol para completar su ficha.

### Visitas y fotos por páginas

Las dos rutas nuevas son privadas, requieren JWT móvil y aceptan exclusivamente `page` (1–1.000.000) y `per_page` (1–100, predeterminado 100):

| Ruta GET relativa a `/api/mobile/v1` | Contenido |
| --- | --- |
| `/inventories/{inventory}/inspections` | Todas las visitas vigentes de los árboles participantes, aunque pertenezcan a otra campaña o no tengan campaña asociada. |
| `/inventories/{inventory}/photos` | Fotografías vigentes autorizadas del árbol, sus visitas históricas y sus mantenimientos. |

Ambas responden `{data:[...],meta:{organization_id,inventory_id,current_page,last_page,per_page,total,has_more,generated_at}}`. El ámbito procede de la sesión y del inventario autorizado, no de filtros aportados por el cliente. `scope`, `organization_id`, `tree_id` y otros filtros adicionales reciben 422; inventario ajeno o inexistente, 404; falta de autenticación, 401. Se excluyen árboles anulados. El orden de visitas es `inspection_date DESC, created_at DESC, id DESC`; el de fotos, `created_at DESC, id DESC`.

La consulta de visitas exige `inventories.view`, `trees.view` e `inspections.view`. Cada fila conserva exactamente la proyección de la ruta histórica por árbol, con mediciones, observaciones, fauna, condiciones, fechas, `lock_version`, `inventory_tree_id`, `inventory_id`, riesgo y carbono. `inventory_id` sigue identificando la campaña de esa visita; no se sustituye por la campaña usada para descargar. Las selecciones históricas de catálogos inactivos se conservan. Sin `risk.view`, `risk` es `null`. No se recalcula ni se confirma riesgo al descargar. El historial comprende todas las visitas y sus versiones vigentes; las revisiones internas de auditoría no se serializan.

### Fotografías privadas y contenido

La consulta base exige `inventories.view`, `trees.view` y `evidences.view`. Incluye fotos de visitas solo con `inspections.view` y fotos de mantenimientos solo con `maintenances.view`. Si falta uno de esos permisos, se omite su rama y se conservan las fotos de los demás propietarios autorizados. No se incluyen evidencias ni mantenimientos anulados. La marca de publicación no restringe esta lectura privada: una foto publicada también puede descargarse mediante su ruta privada autorizada.

La proyección bulk conserva `id`, `tree_id`, `evidence_type_id`, `caption`, `notes`, `captured_at`, `mime_type`, `size_bytes`, `width`, `height`, `checksum_sha256`, `created_at`, `uploaded_by` y `content_url`. Añade `tree_inspection_id`, `maintenance_id` (nulos cuando no aplican) y `source_kind` (`tree`, `inspection` o `maintenance`). En esta colección, `tree_id` siempre es el árbol efectivo del propietario, para vincular la foto localmente. No se exponen rutas de almacenamiento, disco ni metadata interna.

`content_url` conserva `/api/mobile/v1/photos/{id}/content`. Esta ruta admite los tres propietarios y vuelve a autorizar el propietario actual mediante las políticas y la entrega de evidencias existentes; el contenido sigue siendo privado y `no-store`. Un permiso revocado después de listar puede impedir su descarga. La galería histórica `GET /trees/{tree}/photos` conserva su proyección y sus fotos de propietario directo; las cargas de fotos no cambian.

### Persistencia del cliente y límites

Descargar todas las páginas de cada colección y comprobar su institución e inventario antes de reemplazar su copia local. Solo después de terminar las colecciones y los bytes de todas las fotos autorizadas puede anunciarse una descarga completa. Cada árbol, visita y foto conserva su ID estable; un árbol compartido entre campañas no crea nuevas identidades. Ante fallo o cancelación, conservar los datos ya confirmados y no declarar completo un lote parcial. Las capacidades deben renovarse en cada sincronización manual.

No existe token de snapshot ni garantía de instantánea entre páginas. Una modificación concurrente puede exigir repetir la descarga; este contrato no añade sincronización en segundo plano. Tampoco amplía acceso a otras instituciones ni permite suplir un permiso faltante mediante la caché. Se conserva el límite vigente de 90 solicitudes de lectura por minuto y usuario, compartido por páginas y contenido de fotos. Ante 429, respetar la espera indicada por el servidor y permitir reintento/cancelación sin declarar terminado el lote.

Ver [evidencia del backend y límites de verificación](inventory-history-20261105.md).

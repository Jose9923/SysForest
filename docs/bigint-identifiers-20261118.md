# Identificadores internos BIGINT y compatibilidad externa

Estado al 18/11/2026: migración incremental `000035` activada en producción y verificada por HTTPS. Los datos, relaciones, historiales y archivos existentes se conservaron. La fuente canónica local `/home/josev/SYSFOREST` también quedó actualizada y verificada; el checkout de revisión permanece separado. Esta autorización cambió la elección inicial de UUID como clave SQL, sin reiniciar la base ni eliminar antecedentes.

## Contrato de identidad

Las claves primarias y referencias internas de las entidades convertidas pasan a BIGINT positivo. Cada entidad conserva `external_id UUID UNIQUE`: en registros anteriores contiene exactamente su UUID original y las altas nuevas reciben uno estable. Las tablas que ya tenían BIGINT conservan su identidad. Las claves compuestas mantienen sus componentes y restricciones.

La API móvil v1 conserva UUID externos; no obliga a reinterpretar la identidad de las copias descargadas ni de operaciones pendientes. `operation_id`, `client_id` y la correlación `request_id` siguen siendo UUID. Los códigos públicos de instituciones/árboles/campañas y `evidence.public_id` no se sustituyen por PK numéricas. La traducción se hace por tabla o tipo explícito y no concede autorización: siguen siendo obligatorios tenant, permisos y comprobaciones actuales del recurso.

Los hashes de idempotencia se calculan sobre el contrato de transporte original antes de resolver referencias para consultar SQL. Los recibos confirmados conservan `payload_hash` y `result` exactos, incluso al repetir una operación tras la migración. El destino interno del recibo sí debe apuntar a la misma entidad convertida. No convertir recursivamente cualquier cadena que parezca UUID dentro de JSON.

## Auditoría e historial

PostgreSQL sigue siendo la fuente transaccional. El lector distingue `internal_id`/`internal_organization_id` para relaciones y alcance de los UUID externos `id`/`organization_id`/`resource_id` usados por documentos, enlaces y filtros. Los recibos `audit_deliveries.entry_id` usan el BIGINT interno de su origen; `_id=source:UUID`, el documento y su `payload_hash` en MongoDB permanecen iguales. El orden y cursor de reproducción conservan la identidad externa.

| Referencia sin FK simple | Discriminador | Destino interno |
| --- | --- | --- |
| `audit_deliveries.entry_id` | `events` | `security_events.id` |
| Mismo campo | `inspections` | `inspection_revisions.id` |
| Mismo campo | `evidences` | `evidence_revisions.id` |
| Mismo campo | `maintenances` | `maintenance_revisions.id` |
| Mismo campo | `publications` | `publication_revisions.id` |
| Mismo campo | `statuses` | `tree_status_histories.id` |
| Mismo campo | `locations` | `tree_location_histories.id` |
| `mobile_mutation_receipts.target_id` | `tree.update`, `inspection.create` | `trees.id` |
| Mismo campo | `inspection.update` | `tree_inspections.id` |
| `carbon_refresh_runs.last_inspection_id` | Cursor de inspección | `tree_inspections.id` |

La deduplicación entre revisiones y eventos compara `metadata.revision_id` contra `external_id`, conservando la relación histórica. `security_events.resource_id`, snapshots, metadata/contexto y documentos Mongo anteriores no se reescriben. Las capturas nuevas convierten solo referencias conocidas: recurso y catálogo explícitos, revisiones según acción, campos relacionados de árbol/evidencia/mantenimiento, contexto/clasificación/riesgo de inspección y arrays de condiciones/fauna. Los identificadores que ya eran BIGINT, los ceros, nulos y configuraciones JSON opacas no se reinterpretan. La proyección pura `AuditSnapshot`/`AuditRecordProjection` conserva su contrato anterior.

Los scopes siguen autorizándose con claves internas actuales. Las opciones de instituciones, filtros y documentos Mongo utilizan UUID externos, resueltos antes de comprobar pertenencia; un ámbito departamental vacío sigue devolviendo cero instituciones. Los nombres actuales se consultan usando `organizations.external_id`, sin alterar lo registrado históricamente.

## Carbono, riesgo y tareas pendientes

Los nuevos `carbon_estimates.inputs` conservan UUID externos de especie/hábito. La referencia de perfil guarda también UUID externos de organización/especie; el ID del perfil ya era BIGINT y se mantiene. El orden y tipos del contrato usado por `input_key` no cambian. Las columnas relacionales usan las nuevas claves internas, mientras las lecturas de compatibilidad comparan el UUID histórico del hábito con `growth_habits.external_id`. Esto evita invalidar estimaciones por la sola migración de identidad.

Las tareas de carbono que ya estaban en Redis pueden seguir resolviendo su UUID de ejecución. Los cursores convertidos a BIGINT se traducen al UUID externo para mantener el orden anterior del recorrido. No se descartan tareas ni se reinicia progreso. Los resultados y configuraciones de riesgo utilizan códigos estables de condiciones; no se reescriben ni se cambia su interpretación, confirmador o método.

## Verificación y límites actuales

El delta se preparó únicamente en `/home/josev/SysForest-Bigint-Review`, con copias previas fuera del repositorio. PSR-12, sintaxis PHP y diff-check aprobados en doce archivos de auditoría y nueve de referencias carbono/riesgo. Las proyecciones puras `AuditSnapshot`/`AuditRecordProjection` conservan exactamente sus bytes anteriores. No se creó una suite permanente.

Se realizaron estas observaciones manuales desechables:

- **37 comprobaciones aisladas sin red ni DB**: referencias tipadas e idempotencia de captura, condiciones/fauna, catálogos, revisiones, filtros autorizados/ajenos, alcance vacío, estructura SQL y hashes de fixtures de las siete fuentes.
- **23 comprobaciones integradas de auditoría**: dos copias independientes de PostgreSQL, anterior y posterior a `000035`, produjeron exactamente los mismos 16.802 IDs/documentos/hash, sin diferencias ni entradas adicionales. Deduplicación y conteos por fuente coinciden; los recibos existentes enlazan por BIGINT y no aparecen pendientes. Alcances, opciones UUID, nuevos recibos e idempotencia aprobados. Las nuevas filas SQL se revirtieron.
- **MongoDB efímero** con cuenta real limitada a find/insert: siete documentos ficticios, uno por fuente. Repetir la entrega tras agregar referencias internas conserva el documento sin duplicarlo; otro contenido bajo la misma identidad se rechaza y no sustituye el original. Ámbito vacío rechazado. Ese ensayo aislado no consultó ni modificó MongoDB productivo.
- **14 comprobaciones integradas de carbono/riesgo**: las 2.855 huellas de entrada históricas se reconstruyen sin diferencias; la consulta mantiene carbono en 2.855 árboles del clon. Una muestra compatible conserva el resultado científico y repetir su cálculo reutiliza la misma estimación. Se verificaron historia, perfil, metodología vigente, UUID de tareas anteriores y continuidad del cursor. Las operaciones temporales se revirtieron y los conteos de siete tablas permanecieron iguales.

Evidencias en el workspace de la entrega: `.bigint-identifiers-work/audit/manual-observations.json`, `audit-database-observations.json`, `carbon-database-observations.json` y manifiestos/huellas asociados. Los fixtures se limitaron al entorno aislado; las cifras describen esas copias y no una lectura de producción.

La integración paralela también registra **12 comprobaciones DDL aprobadas** sobre 90.105 filas/72 tablas, con 131 FK y 49 triggers; **74 observaciones móviles/auth aprobadas** con rollback; **16 comprobaciones web aprobadas** en la copia aislada; y sintaxis PHP final aprobada en **621 archivos**. Evidencias: `.bigint-migration-work/migration-manual-observations.json`, `mobile-integration-observation.json`, `web-observation-result.json` y `backend-syntax.json`. El ensayo de instalación limpia con **BaseSeeder pasó 12 comprobaciones** en una base nueva desechable: migraciones completas hasta `000035`, seeder ejecutado dos veces, 143 especies, 45 familias, 145 nombres comunes, 16 emplazamientos, cuatro roles, 52 permisos y un administrador ficticio cuya contraseña se conserva. No produjo organizaciones, inventarios, árboles, visitas ni importaciones. Evidencia: `.bigint-migration-work/fresh-base-observations.json`. Esto no autoriza reinstalar una base existente; su actualización sigue siendo incremental. La revisión integrada de **importaciones pasó 19 comprobaciones** con rollback: un lote preparado con UUID anteriores se confirma en el primer intento conservando `raw_values`, `normalized_values` y su hash, y repetirlo omite las filas completadas sin duplicar registros. Evidencias: `.bigint-migration-work/import-observations.json` e `imports-delivery-manifest.json`.

La revisión final de web corrigió los guards del mapa privado: «Ver ficha» y los contenidos de fotografías aceptan IDs BIGINT como texto, incluido `9223372036854775807`, sin pasar por Number/parseInt. **23 observaciones de JavaScript real con DOM/fetch ficticios** verificaron aceptación de 1/máximo y rechazo de UUID, cero, ceros iniciales, negativos, overflow y IDs codificados, conservando origen, scope, query, hash, correspondencia de metadata y permiso de foto. No sustituyen revisión visual de navegador. Evidencia: `.bigint-identifiers-work/audit/map-id-observations.json`. Los guards de organización/membresía también pasaron **10 comprobaciones**, rechazando referencias no canónicas antes de SQL, con cero consultas en los rechazos (`.bigint-migration-work/route-id-observations.json`).

El cierre de formato pasó **169 archivos en Pint**, y la sintaxis final pasó **621 archivos PHP**. El build del target de producción del Dockerfile terminó correctamente, con dependencias `--no-dev` y autoload de **6.398 clases**. La imagen verificada mediante `docker image inspect` es `sha256:7d6fa07aa5aaaa9c927251ac9e868dc3b61ab0a3d667efebb5e25e93605a56a5`. Su prueba de arranque pasó **10 comprobaciones de contenedor y 21 de runtime**, incluidas cachés y lecturas SQL, con **cero escrituras SQL**. Evidencia: `.bigint-migration-work/production-image-observations.json`. La integración final queda aprobada en el entorno aislado. La limpieza de QA también terminó: se retiraron cinco contenedores, la red interna, tres bases en tmpfs y siete archivos privados. La imagen, el checkout y las evidencias se conservaron; al terminar esa revisión aislada, la fuente canónica y producción todavía permanecían intactas. Evidencia: `.bigint-migration-work/integrated-qa-cleanup.json`.

## Corte productivo completado

El 18/11/2026 se actualizó el checkout fuente `/opt/sysforest/source` al commit `4e3eceb84abde002055ac813e7dea2fb117486f5` y se activó la imagen `sha256:7d6fa07aa5aaaa9c927251ac9e868dc3b61ab0a3d667efebb5e25e93605a56a5`. El release conserva sus 171 archivos de runtime aprobados y los dos módulos JavaScript del mapa con sus hashes exactos. Se recrearon únicamente aplicación, cola y scheduler; Nginx volvió a iniciarse en su contenedor existente. Los siete servicios quedaron saludables. PostgreSQL, MongoDB, Redis, los visores y los demás contenedores conservaron sus identidades, imágenes y tiempos de inicio; `production.env` permaneció idéntico. No se ejecutaron `fresh`, seeders, recálculos ni limpiezas globales de caché.

Antes del corte, un ensayo restauró una **copia previa de producción** en servicios aislados y aprobó la migración y comparación. Durante la pausa de los escritores se obtuvo un respaldo final independiente en:

`/opt/sysforest/backups/bigint-20261118-4e3eceb/backup-20261118T213905Z-REc7R8`

El respaldo final tiene `COMPLETE` y sus archivos pasaron la verificación de `SHA256SUMS`; la huella del manifiesto es `91936f2fcbd279af5e17a5f237dce2630885dfc5846a47f079deab1c8038e578`. Se preservaron también el entorno privado, las preimágenes del código y la imagen anterior `sysforest-app:before-bigint-20261118-4e3eceb`. **No se afirma que se haya restaurado este respaldo final**: la restauración comprobada corresponde a la copia previa del ensayo. Redis permaneció en ejecución con su volumen original, sin recreación, vaciado ni nuevo respaldo RDB.

La captura PDO de solo lectura anterior y posterior al cambio confirmó 55 entidades convertidas, **71 tablas y 2.008 filas lógicas idénticas**, normalizando los nuevos IDs internos a sus UUID externos para comparar. La única migración añadida fue `000035`. Se conservaron exactamente:

| Datos existentes | Antes y después |
| --- | ---: |
| Árboles | 2 |
| Inventarios | 1 |
| Participaciones árbol–inventario | 2 |
| Inspecciones | 2 |
| Fotografías/evidencias | 7 |
| Condiciones de inspección | 18 |
| Registros de fauna de inspección | 2 |

`storage/app` conservó sus **24 archivos y 43.173.340 bytes**, con huella idéntica `9309cd5e01e00014347e4e1443a78eeac5c238d7f8c9577d8a1daa805eaf76a2`. La colección de auditoría Mongo conservó durante el corte **17.040 documentos** y la misma huella `d30c9d8a25e44071f7ed39e552f87bc7071e5b02ed568d17ae2bd3f56b530045`. Después de reabrir la aplicación, la auditoría puede crecer por accesos y uso normal; ese crecimiento no se confunde con pérdida o reescritura del historial.

La verificación HTTPS posterior pasó **96 de 96 comprobaciones**, con inicio de sesión normal web y móvil, consultas privadas autorizadas, IDs numéricos en la web y UUID externos en la API móvil, rechazos de identidades/rutas no admitidas y verificación de los dos JavaScript publicados. Las siete fotografías se consultaron por ambos contratos y conservaron tamaño, bytes y SHA. Se cerraron las dos sesiones propias. La comprobación hizo **cero escrituras de negocio**, sin cambiar contraseñas/roles ni enviar mensajes; el inicio y cierre de sesión sí producen el estado y la auditoría normales de autenticación.

Evidencia: `.bigint-migration-work/production-rehearsal-result.json`, `production-cutover-result.json` y `production-http-verification.json`. No se añadieron suites permanentes. HTTPS no sustituye una comprobación física de Android. La actualización local se completó por separado, con evidencia propia descrita a continuación.

## Activación canónica local completada

La fuente `/home/josev/SYSFOREST` avanzó por fast-forward al mismo commit `4e3eceb`. En la base **local**, que contiene datos distintos de producción, se aplicaron `000033`, `000034` y `000035` sin `fresh` ni seeders. Al comparar inmediatamente antes y después de `000035`, sus **71 tablas y 90.070 filas lógicas** permanecieron idénticas: **2.855 árboles, 2.855 inspecciones, dos inventarios y cuatro evidencias**, entre otros antecedentes. Estas cifras pertenecen exclusivamente al entorno local; no reemplazan los dos árboles y el inventario conservados en producción.

Los tres servicios PHP locales usan la imagen `sha256:808c79e9167e588aa06803b7c6cc5261a5ae1ebe40b69a322040af16866328fe`. Los ocho servicios locales están saludables, los otros cinco conservan sus IDs e imágenes, y no quedan migraciones pendientes. `DB_TEST` y `.env` permanecen intactos. El respaldo local está fuera de OneDrive, en `/home/josev/.local/state/sysforest/backups/bigint-20261118-4e3eceb`. Evidencia: `.bigint-migration-work/local-activation-result.json`.

## Recuperación y actualizaciones posteriores

Conservar el respaldo verificado, el entorno privado y la imagen anterior como un conjunto. Si fuera necesaria una recuperación, pausar los escritores y restaurar los datos y la versión compatibles mediante un procedimiento revisado. El rollback no consiste en ejecutar `down()` ni en reconvertir IDs sobre la base modificada. No ejecutar seeders ni eliminar recibos/documentos para resolver incompatibilidades.

Las próximas actualizaciones deben volver a coordinar respaldo, versión de código, migraciones incrementales y procesos. No reutilizar el respaldo de este corte como si fuera una copia actual de los datos posteriores.

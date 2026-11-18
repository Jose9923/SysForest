# Identificadores internos BIGINT y compatibilidad externa

Estado al 18/11/2026: implementación y revisión en un checkout aislado. No se ha activado esta migración ni modificado datos productivos. Esta autorización cambia la elección inicial de UUID como clave SQL; no autoriza reiniciar bases ni perder antecedentes.

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
- **MongoDB efímero** con cuenta real limitada a find/insert: siete documentos ficticios, uno por fuente. Repetir la entrega tras agregar referencias internas conserva el documento sin duplicarlo; otro contenido bajo la misma identidad se rechaza y no sustituye el original. Ámbito vacío rechazado. No se consultó ni modificó MongoDB productivo.
- **14 comprobaciones integradas de carbono/riesgo**: las 2.855 huellas de entrada históricas se reconstruyen sin diferencias; la consulta mantiene carbono en 2.855 árboles del clon. Una muestra compatible conserva el resultado científico y repetir su cálculo reutiliza la misma estimación. Se verificaron historia, perfil, metodología vigente, UUID de tareas anteriores y continuidad del cursor. Las operaciones temporales se revirtieron y los conteos de siete tablas permanecieron iguales.

Evidencias en el workspace de la entrega: `.bigint-identifiers-work/audit/manual-observations.json`, `audit-database-observations.json`, `carbon-database-observations.json` y manifiestos/huellas asociados. Los fixtures se limitaron al entorno aislado; las cifras describen esas copias y no una lectura de producción.

La integración paralela también registra **12 comprobaciones DDL aprobadas** sobre 90.105 filas/72 tablas, con 131 FK y 49 triggers; **74 observaciones móviles/auth aprobadas** con rollback; **16 comprobaciones web aprobadas** en la copia aislada; y sintaxis PHP final aprobada en **621 archivos**. Evidencias: `.bigint-migration-work/migration-manual-observations.json`, `mobile-integration-observation.json`, `web-observation-result.json` y `backend-syntax.json`. El ensayo de instalación limpia con **BaseSeeder pasó 12 comprobaciones** en una base nueva desechable: migraciones completas hasta `000035`, seeder ejecutado dos veces, 143 especies, 45 familias, 145 nombres comunes, 16 emplazamientos, cuatro roles, 52 permisos y un administrador ficticio cuya contraseña se conserva. No produjo organizaciones, inventarios, árboles, visitas ni importaciones. Evidencia: `.bigint-migration-work/fresh-base-observations.json`. Esto no autoriza reinstalar una base existente; su actualización sigue siendo incremental. La revisión integrada de **importaciones pasó 19 comprobaciones** con rollback: un lote preparado con UUID anteriores se confirma en el primer intento conservando `raw_values`, `normalized_values` y su hash, y repetirlo omite las filas completadas sin duplicar registros. Evidencias: `.bigint-migration-work/import-observations.json` e `imports-delivery-manifest.json`.

La revisión final de web corrigió los guards del mapa privado: «Ver ficha» y los contenidos de fotografías aceptan IDs BIGINT como texto, incluido `9223372036854775807`, sin pasar por Number/parseInt. **23 observaciones de JavaScript real con DOM/fetch ficticios** verificaron aceptación de 1/máximo y rechazo de UUID, cero, ceros iniciales, negativos, overflow y IDs codificados, conservando origen, scope, query, hash, correspondencia de metadata y permiso de foto. No sustituyen revisión visual de navegador. Evidencia: `.bigint-identifiers-work/audit/map-id-observations.json`. Los guards de organización/membresía también pasaron **10 comprobaciones**, rechazando referencias no canónicas antes de SQL, con cero consultas en los rechazos (`.bigint-migration-work/route-id-observations.json`).

El cierre de formato pasó **169 archivos en Pint**, y la sintaxis final pasó **621 archivos PHP**. El build del target de producción del Dockerfile terminó correctamente, con dependencias `--no-dev` y autoload de **6.398 clases**. La imagen verificada mediante `docker image inspect` es `sha256:7d6fa07aa5aaaa9c927251ac9e868dc3b61ab0a3d667efebb5e25e93605a56a5`. Su prueba de arranque pasó **10 comprobaciones de contenedor y 21 de runtime**, incluidas cachés y lecturas SQL, con **cero escrituras SQL**. Evidencia: `.bigint-migration-work/production-image-observations.json`. La integración final queda aprobada en el entorno aislado. La limpieza de QA también terminó: se retiraron cinco contenedores, la red interna, tres bases en tmpfs y siete archivos privados. La imagen, el checkout y las evidencias se conservaron; la fuente canónica y producción permanecen intactas. Evidencia: `.bigint-migration-work/integrated-qa-cleanup.json`.

No se ha hecho push ni activado el código en la fuente canónica o producción. La integración global está aprobada en el entorno aislado; el corte coordinado y la activación siguen pendientes al redactar. Estas comprobaciones no acreditan compatibilidad física de Android ni publicación. La activación requiere respaldo verificado y cierre de los demás contratos de la entrega, conservando relaciones, historiales, recibos y archivos. No ejecutar seeders ni eliminar recibos/documentos para resolver incompatibilidades.

## Corte futuro y recuperación

La activación debe prepararse como una operación coordinada, todavía no ejecutada:

1. Obtener un respaldo nuevo y verificar su recuperación, incluyendo PostgreSQL, las referencias externas y los archivos/estado necesarios para la versión anterior.
2. Pausar la aplicación, cola y scheduler para impedir que código anterior y nuevo escriban simultáneamente durante el cambio de esquema.
3. Ejecutar la migración incremental `000035` con la identidad migradora y el respaldo confirmado. No usar `migrate:fresh`, seeders ni recálculos de resultados para actualizar una instalación existente.
4. Activar la imagen compatible con BIGINT, preparar sus cachés y comprobar salud, permisos, relaciones, recibos y lecturas antes de reanudar los procesos.

Si se requiere volver atrás, recuperar el respaldo verificado y la versión anterior como un conjunto coherente. El rollback no consiste en ejecutar `down()` ni en reconvertir IDs en la base ya modificada. Conservar los respaldos y registrar el resultado del corte antes de dar la activación por terminada.

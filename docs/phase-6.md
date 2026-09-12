# Fase 6 — Inventarios, individuos forestales y georreferenciación

Entrega verificada el **12 de septiembre de 2026**, en `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Se conserva Laravel 13 / PHP 8.4 y el flujo Controller → Service → Repository → Model. La interfaz es Blade. **546 pruebas y 2.484 aserciones aprobadas; PSR-12 correcto en 297 archivos PHP.**

La Fase 5 se cerró en **28c2bf7**, con el mensaje solicitado `feat: complete phase 5 national forestry catalogs`, después de repetir `docker compose up -d`, las 352 pruebas anteriores (1.438 aserciones, 37,23 s) y PSR-12 en 232 archivos. Se comprobó que `.phase5-work/` no existe dentro del proyecto definitivo; `.gitignore` ya excluye `/.phase*-work/`. No se borró contenido preexistente. La Fase 6 permanece sin commit en **codex/phase-6** para revisión. No se inició Fase 7.

## A. Tablas nuevas

| Tabla | Propósito |
| --- | --- |
| inventories | Campañas de una organización, con disponibilidad, estado, fechas, autoría y versión. |
| organization_sequences | Contador atómico por organización y nombre de secuencia. |
| trees | Individuo permanente, taxonomía, ubicación, estado, autoría, versión y anulación. |
| inventory_trees | Participación del mismo individuo en varias campañas. |
| tree_status_histories | Estado inicial y transiciones inmutables. |
| tree_location_histories | Punto, texto y metadatos anteriores/nuevos, causa y actor. |

Los catálogos nacionales de Fase 5 siguen compartidos, sin organization_id.

## B. Migraciones

- `2026_09_12_000010_create_inventory_and_tree_tables.php`: seis tablas, FK, CHECK, unicidades, índices y protección de identidad/historial.
- `2026_09_12_000011_preserve_capture_precision_and_inventory_codes.php`: precisión de microsegundos en captured_at y código de Inventory inmutable.

Cada migración se aplicó primero en `sysforest_testing`, se revirtió individualmente con salida 0 y se reaplicó antes de habilitar las pruebas. Se comprobó la última migración antes de cada `--step=1`. La base operativa nunca fue revertida. La segunda migración evita reescribir una primera migración que ya se había aplicado.

~~~bash
docker compose run --rm --no-deps --env DB_DATABASE=sysforest_testing init php artisan migrate --force --no-interaction
docker compose run --rm --no-deps --env DB_DATABASE=sysforest_testing init php artisan migrate:rollback --step=1 --force --no-interaction
docker compose run --rm --no-deps init
~~~

Los procedimientos de rollback anteriores corresponden al último lote verificado en esta ejecución; no deben repetirse sin revisar `migrate:status`.

## C. Relaciones

Organization → Inventory y Organization → Tree; Inventory ↔ Tree mediante inventory_trees. Tree → Species → Family y SpeciesCommonName; Tree referencia Placement, GrowthHabit y ambas dimensiones de origen. Tree tiene historiales de estado y ubicación, creador/editor, motivo de anulación y autor de restauración. Las referencias de actores conservan el BIGINT de users; los recursos forestales usan UUID.

Los padres exponen `UNIQUE(organization_id,id)`. El pivote referencia tanto `(organization_id,inventory_id)` como `(organization_id,tree_id)`; ambos historiales también usan FK compuesta. `UNIQUE(inventory_id,tree_id)` impide duplicar la participación.

## D. Models

Inventory, InventoryTree, OrganizationSequence, Tree, TreeStatusHistory y TreeLocationHistory. Enums InventoryStatus, TreeStatus y LocationChangeType. Los atributos operativos internos se preparan en servicios y repositorios; los modelos no aceptan asignación masiva abierta desde solicitudes.

## E. Inventory

UUID, organización obligatoria, código estable y único por institución, nombre, descripción/fechas opcionales, estados draft/active/closed/archived, active, autores y lock_version. Las fechas se validan entre sí. No usa SoftDeletes: cerrar/archivar y desactivar conservan las campañas. El código también quedó inmutable para conservar identidad y evitar que renombrar el código del piloto produzca otro seed.

ORGANIZATION_ADMIN administra; OFFICIAL consulta y trabaja con campañas activas. SUPER_ADMIN opera en el tenant seleccionado; DEPARTMENT_ADMIN conserva lectura territorial.

## F. InventoryTree

UUID, organization_id, inventory_id, tree_id, included_at, included_by, nota opcional y timestamps. Una inclusión repetida es idempotente y conserva el primer autor/fecha/nota. La edición agrega campañas activas sin retirar participaciones anteriores. El filtro por campaña consulta esta relación; no existe trees.inventory_id.

## G. Tree

Identidad permanente con código generado, external_number opcional para conservar el N. ÁRBOL original, taxonomía derivada, emplazamiento/hábito/orígenes opcionales, location_description separado de geometry, dos indicadores protegido/patrimonial, estado, autores y versión. No almacena medidas, madurez, condiciones, fenología ni fauna observada.

No existe family_id ni nombre científico duplicado en trees. La descripción de ubicación se añadió por la petición de Fase 6; la columna C de la matriz aprobada continúa representando coordenadas. No se reinterpretó como texto ni se movieron variables de inspección.

## H. Estado e historial

active, under_observation, dead, felled, transplanted, removed y replaced, con etiquetas españolas. Se conserva la fila inicial y cada transición efectiva. Reenviar el mismo estado no inventa una transición. El motivo es opcional. Protegido y patrimonial son indicadores independientes; no constituyen estados ni una calificación jurídica automática.

## I. Ubicación e historial

La ubicación actual permanece en Tree. El historial conserva geometría/texto/metadatos anteriores y nuevos, actor y fecha. La primera captura utiliza change_type=NULL; cambios posteriores usan correction, relocation o transplant, con tipo y motivo requeridos. También se historizan cambios de metadatos. Omitir conserva; NULL explícito limpia. No-op no añade historial. Historial y evento `tree.location_changed` son atómicos.

## J. Código humano

El piloto produce `COL-MET-VVC-INEM-000001`. UUID sigue siendo la PK. El ancho de seis dígitos es mínimo y puede crecer. Tree.code, organization_id y created_by son inmutables también por trigger. El número anterior se conserva en external_number, sin inferir unicidad del Excel. Un código confirmado no se recicla tras anulación.

## K. Secuencia y concurrencia

OrganizationSequenceRepository ejecuta `INSERT ... ON CONFLICT ... DO UPDATE ... RETURNING` con bindings y bloqueo de fila. Requiere transacción; la reserva, Tree, campañas e historiales iniciales se confirman juntos. Una operación fallida revierte una reserva que nunca produjo un individuo confirmado.

TreeCodeConcurrencyTest ejecutó dos procesos PHP con conexiones independientes. PostgreSQL confirmó que ambos esperaban por el mismo bloqueo antes de liberarlo; obtuvieron 000001 y 000002. Otra organización obtuvo su propio 000001. Los fixtures confirmados de esta prueba solo contienen territorio/organizaciones/secuencias sintéticas y se limpian por sus UUID exactos en testing.

## L. PostGIS

`geometry(Point,4326)` nullable, X=longitud/Y=latitud, límites válidos, geometría no vacía y GiST. Índice adicional de geography para búsquedas métricas. Bbox usa ST_Intersects/ST_MakeEnvelope; radio y proximidad usan ST_DWithin/ST_Distance sobre geography, en metros. Todas las consultas llevan organización y pueden filtrar campaña. No se calculan distancias en PHP ni se concatena entrada en SQL.

Índices institucionales de Tree: código único, especie, emplazamiento, estado, created_at y deleted_at; el índice compuesto inicia por organization_id. El pivote indexa organización/campaña y organización/individuo; no se añadió un índice independiente redundante por organización.

## M. Parser decimal y DMS

CoordinateParserService valida toda la cadena, hemisferios y rangos y conserva raw. Ejemplo requerido: `4°15'19.2" N, 73°34'19.1" W` → aproximadamente **4.255333333333333, -73.57197222222223**. Rechaza grados fuera de rango, minutos/segundos ≥60, ejes intercambiados, signos ambiguos, NaN/INF, notación científica y texto adicional. Los errores son españoles. La previsualización usa el mismo parser y el guardado vuelve a validar.

## N. Metadatos GPS

coordinate_raw, coordinate_format, datum, accuracy_m, gps_brand, gps_model y captured_at. Todos los datos desconocidos permanecen NULL; no se inventan marca/modelo/precisión/fecha. WGS84 se guarda explícitamente cuando se informa. Con datum desconocido, el punto usa SRID 4326 como referencia de trabajo, sin afirmar cuál era el datum del equipo. Otros datums requieren una transformación futura y se rechazan en esta fase. La captura ISO conserva el instante y microsegundos; las fechas sin offset usan la zona configurada de la aplicación (UTC).

El middleware exceptúa coordinate_raw del recorte automático de cadenas. Hay pruebas HTTP de conservación exacta del texto con espacios exteriores.

## O. Controllers

InventoryController, TreeController, TreeInventoryController, TreeStatusController, TreeLocationController, TreeVoidController, TreeRestoreController y CoordinatePreviewController. Coordinan Requests, Services, Blade/JSON y redirects; no consultan ni contienen fórmulas o SQL espacial.

## P. Services

InventoryService, TreeService, TreeReadService, TreeWriteService, TreeRelationService, TreeCodeService, TreeStatusService, TreeLocationService, TreeLifecycleService, CoordinateParserService, CoordinatePreviewService y ForestryAccessService. Se reutilizan TenantContextService, PermissionService y la infraestructura de auditoría. La división evita concentrar lectura, autorización, ubicación, estado y secuencia en un único servicio.

## Q. Repositories

InventoryRepository, TreeRepository, TreeCatalogRepository, OrganizationSequenceRepository, TreeStatusHistoryRepository y TreeLocationRepository. Reutilizan modelos y catálogos existentes. SecurityEventRepository registra inventory.created/updated y tree.created/updated/inventory_included/status_changed/location_changed/voided/restored con actor, organización y metadatos permitidos, sin tokens ni formularios completos.

## R. Requests

StoreInventoryRequest, UpdateInventoryRequest, StoreTreeRequest, UpdateTreeRequest, TreeFilterRequest, IncludeTreeInventoryRequest, UpdateTreeStatusRequest, UpdateTreeLocationRequest, VoidTreeRequest, RestoreTreeRequest y CoordinatePreviewRequest. InventoryInput y TreeInput comparten validación con los servicios. UUID ajenos no son selecciones válidas del tenant. Los campos internos están protegidos y la versión se valida antes de convertirla también en llamadas directas.

## S. Policies

InventoryPolicy y TreePolicy, registradas explícitamente. No hay Gate::before universal. ForestryAccessService reconsulta autoridad vigente y diferencia organization, national y department_read. Las rutas reciben UUID como texto; el repositorio resuelve primero por organización y después se aplica Policy. Un UUID ajeno devuelve 404 en las rutas de recurso, antes de revelar su ficha.

## T. Permisos

Ocho nuevos, **28 totales**: inventories.view/create/update y trees.view/create/update/void/restore. La matriz completa está en [multitenancy](multitenancy.md#operaciones-forestales-de-fase-6). La organización es dueña del dato: un funcionario autorizado puede editar el árbol de otro funcionario de la misma institución, sin cambiar created_by. OFFICIAL requiere una campaña activa vinculada para editar. DEPARTMENT_ADMIN no modifica árboles ni inventarios; SUPER_ADMIN no necesita membresías artificiales, pero sí contexto institucional seleccionado.

## U. Blade

Listados, formularios y detalles de árboles e inventarios, búsqueda/paginación/orden/filtros, identidad y campañas, taxonomía, GPS, historiales, anulación/restauración y página 409. Los filtros incluyen campañas históricas; las nuevas asociaciones solo ofrecen campañas activas. Select2 busca por nombres comunes y científicos y muestra la familia derivada.

Se verificaron las páginas mediante HTTP con JWT real de fixtures, además de login/selección/logout con las cuatro cuentas locales existentes. En el entorno operativo: los cuatro roles consultaron listas y piloto con 200; DEPARTMENT_ADMIN recibió 403 en formularios/escritura; OFFICIAL pudo abrir nuevo Tree y recibió 403 para crear Inventory. No se crearon árboles operativos durante la revisión.

La inspección visual utilizó HTML sanitizado de esas respuestas y de fixtures de testing, sin cookies, credenciales ni acciones ejecutables. Se revisaron **390×844** y **1440×1000**, sin desbordamiento final. Se corrigió el ancho mínimo de Select2 múltiple. La ficha móvil mostró mapa, marcador, latitud/longitud y datos GPS legibles. Buscar Oití seleccionó Licania tomentosa y mostró CHRYSOBALANACEAE. Esta revisión de presentación se distingue de las pruebas HTTP de comportamiento.

## V. Leaflet individual

Leaflet **1.9.4** local, con licencia y SOURCE.json/SRI verificado por la fuente oficial. Marcador actual, centrado, zoom y coordenadas visibles. Captura autorizada permite clic/arrastre y llama al endpoint de previsualización; backend valida al guardar. Las respuestas antiguas de previsualización se descartan al modificar la entrada. Los popups usan texto seguro. Un mapa base no disponible se informa y no borra la captura.

Teselas OpenStreetMap con atribución; las peticiones de teselas solo envían el origen como referrer. La política global de las páginas privadas sigue siendo no-referrer. No se implementó mapa general ni calor.

## W. Inventario piloto

DatabaseSeeder incorpora PilotInventorySeeder de forma idempotente: **PILOT-SYSFOREST**, «Inventario Forestal Institucional - Piloto SysForest», INEM Luis Lopez de Mesa, Villavicencio, Meta, Colombia. active=true/status=active, sin fechas inventadas ni funcionario ficticio. Se conservan ajustes al resembrar. Verificación operativa final: **0 árboles**, **28 permisos**, piloto disponible.

## X. Tests añadidos

**194 casos nuevos**, conservando intactas las 352 pruebas anteriores (`git diff --exit-code 28c2bf7 -- tests` devolvió 0 para los archivos existentes).

| Archivo nuevo | Casos | Aserciones en suite completa |
| --- | ---: | ---: |
| Unit/CoordinateParserTest.php | 55 | 151 |
| Feature/InventoryTest.php | 29 | 95 |
| Feature/ForestryAccessTest.php | 17 | 71 |
| Feature/TreeDomainTest.php | 35 | 224 |
| Feature/TreeSpatialTest.php | 19 | 120 |
| Feature/TreeCodeConcurrencyTest.php | 1 | 8 |
| Feature/TreeHttpSecurityTest.php | 25 | 242 |
| Feature/TreePresentationTest.php | 9 | 114 |
| Feature/TreeCaptureHttpTest.php | 4 | 21 |
| Total nuevo | 194 | 1.046 |

Las capturas optativas de TreePresentationTest hicieron seis aserciones adicionales en su ejecución de revisión visual; no se suman al cierre global.

## Y. Total de pruebas y aserciones

**546 PASS, 2.484 aserciones, 0 fallos, 0 errores, 0 omitidas. Salida 0; duración de Artisan 101,49 segundos.** El JUnit conservado registra 101,247789 segundos de ejecución interna.

~~~bash
docker compose exec -T app php artisan test --compact --log-junit=/tmp/sysforest-phase6-tests.xml
~~~

La suite se ejecutó serialmente sobre sysforest_testing. Las reservas simultáneas de la prueba específica se coordinan intencionalmente dentro de esa prueba.

## Z. PSR-12

`composer format` aplicado y `docker compose exec -T app composer lint`: **297 archivos PHP PASS**, salida 0. Comentarios/PHPDoc útiles en español; nombres técnicos en inglés. `git diff --check` terminó sin incidencias.

## AA. Pruebas PostGIS

19 casos reales: Point4326 y orden de ejes, ambos GiST, bbox, radio/distancia/proximidad en metros, límites, ámbito institucional y de campaña, exclusión de anulados, captura/limpieza/historial, no-op DMS, fecha con microsegundos y auditoría/rollback. Los 55 casos del parser incluyen los cuatro hemisferios y longitudes occidentales negativas.

## AB. Prueba crítica de tenant

Fixtures A/B demuestran que B no obtiene Tree A por GET, edición, estado, ubicación, anulación, restauración ni inclusión. UUID malformados o ajenos no resuelven la ficha. Asociar Tree A a Inventory B falla en el servicio y en la FK compuesta PostgreSQL. Se cubren los cuatro roles, membresía/cuenta/rol revocados, selección manipulada, escalada de permisos y CSRF real.

## AC. Optimistic locking

La versión obsoleta devuelve **409** con: «Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.» Se conservan los datos del primer editor. Hay pruebas Service y HTTP, además de siete entradas de versión malformada contra seis operaciones directas; ninguna escribe estado, ubicación, relaciones ni restauración. La validación ocurre después de resolver y autorizar el recurso institucional.

## AD. Anulación y restauración

Pruebas de motivo obligatorio/disponible, anulación visible solo con permiso, ausencia en bandeja normal, restauración del mismo UUID/código/autor/campañas, versión y auditoría, y código siguiente no reciclado. dead/felled/removed mantienen deleted_at=NULL. El trigger impide borrar físicamente Tree o reescribir historiales.

## AE. Matriz de 94 campos

[excel-field-mapping.md](excel-field-mapping.md) conserva exactamente **94 variables y 7 derivadas**. A:H: **8 campos implementados en Tree y sus relaciones**. Las **86 capturas restantes** siguen pendientes; 71 ya tienen catálogo/regla de Fase 5 y 15 no. Las mediciones/observaciones no se marcaron como implementadas por existir un catálogo. Los orígenes continúan separados y la captura monovalente del instrumento sigue documentada como ambigua para futuras importaciones.

## AF. Docker Compose y entorno

**`docker compose up -d` estándar terminó con salida 0.** No se cambió la estructura Docker ni las dependencias PHP aprobadas; el código actualizado se monta desde el proyecto definitivo.

~~~text
NAME                    SERVICE     STATUS                        PORTS
sysforest-app-1          app         Up About an hour (healthy)    9000/tcp
sysforest-db-init-1      db-init     Exited (0)
sysforest-init-1         init        Exited (0)
sysforest-mailpit-1      mailpit     Up 3 hours (healthy)          127.0.0.1:8025->8025/tcp
sysforest-nginx-1        nginx       Up 6 hours (healthy)          127.0.0.1:8080->80/tcp
sysforest-postgres-1     postgres    Up 6 hours (healthy)          5432/tcp
sysforest-queue-1        queue       Up 26 minutes (healthy)       9000/tcp
sysforest-redis-1        redis       Up 6 hours (healthy)          6379/tcp
sysforest-scheduler-1    scheduler   Up About an hour (healthy)    9000/tcp
~~~

Siete servicios saludables y dos inicializadores completados. Nginx expone exclusivamente public. Solo aplicación 8080 y Mailpit 8025 se publican en loopback; PostgreSQL, Redis y FPM permanecen internos.

PHP **8.4.25**, Laravel **13.31.0**, PostgreSQL **17.5**, PostGIS **3.5.2**. Redis respondió **PONG** y Laravel confirmó conexión. `/health/ready` devolvió `{"status":"ok"}`. InfrastructureProbeJob fue procesado por queue a **20:24:04 UTC**, DONE; scheduler ejecutó su comprobación a **20:24:00 UTC**, DONE, con frecuencia cada minuto confirmada mediante schedule:list. El worker se reinició previamente por su límite configurado de duración y continuó saludable.

## AG. Git status

Rama **codex/phase-6**, HEAD **28c2bf7**. No hay commit final de Fase 6, merge ni publicación. El estado completo por archivo se conserva en [phase-6-files.txt](phase-6-files.txt). No se versionaron `.env`, secretos, vendor de Composer, datos de almacenamiento ni staging.

## AH. Archivos creados y modificados

[phase-6-files.txt](phase-6-files.txt) enumera **101 archivos (90 nuevos y 11 modificados)**: dos migraciones, seis modelos, enums/DTO/validadores, ocho controllers, once Requests, servicios/repositorios/policies, seeder piloto, rutas, Blade/CSS/JS, Leaflet con licencia, nueve archivos de pruebas y documentación. Se actualizaron AppServiceProvider, bootstrap/app.php, autorización/DatabaseSeeder, navegación, README, AGENTS/CLAUDE y multitenancy/matriz Excel. Los reportes de fases anteriores permanecen históricos.

## AI. Deuda técnica e incidencias

- Se conserva `php-open-source-saver/jwt-auth:2.9.3` exactamente fijado. La advertencia estricta de Composer sobre ese pin ya documentada en Fase 5 no bloquea instalación ni pruebas; Composer/lock no se modificaron en Fase 6.
- Durante integración se corrigieron: diferencias entre límites de formulario y columnas, nombre de familia derivada, un error de sintaxis en el Request de previsualización, recorte del raw GPS, Select2 móvil, preservación de microsegundos y conversión prematura de lock_version en llamadas directas. La suite global posterior pasó.
- La cartografía base requiere red. No se incorporó almacenamiento de mapas sin conexión ni transformación de datums distintos a WGS84. Las cajas que cruzan el antimeridiano requieren dividir la consulta, como explica georeferencing.md.
- Los catálogos taxonómicos son los ejemplos nacionales aprobados en Fase 5; no se presentan como una revisión botánica externa ni una lista exhaustiva. No se añadió una caché de opciones prematura.

## AJ. Decisiones pendientes y límite de fase

Continúa pendiente la semántica de captura única/simultánea de origen del instrumento y la equivalencia de «Introducido»; no se inventaron correspondencias. Datum/precisión/modelo/fecha desconocidos siguen sin dato. Los filtros actuales solo usan información permanente implementada.

TreeInspection, mediciones y fórmulas aplicadas, condiciones observadas, fenología, fauna, fotos, mantenimiento, importación, reportes finales, portal público y mapas generales/calor siguen fuera del alcance. **La Fase 6 queda lista para revisión; no se continuará a Fase 7 sin aprobación.**

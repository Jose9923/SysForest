# Motor compartido de filtros y mapa interno

## Alcance de Fase 10

El listado de árboles, el listado de árboles con su última inspección y el mapa consultan un único motor forestal. PostGIS resuelve la selección espacial y las agregaciones. No se reconstruyen coordenadas desde `coordinate_raw`, no se cargan todos los árboles para filtrarlos en PHP o JavaScript y no se crean puntos para registros sin geometría.

El mapa es interno, con autenticación JWT, sesión vigente, correo verificado y autorización actual. Esta fase no introduce un portal público, exportaciones ni reportes finales. Los futuros consumidores podrán reutilizar los contratos descritos aquí, pero deberán autorizar su propio alcance y proyección.

## Contratos y responsabilidades

| Componente | Contrato y responsabilidad |
| --- | --- |
| `TreeFilterInput` | Reglas comunes de tipos y límites para las entradas forestales. `TreeInput::filters()` conserva su API delegando en estas reglas. |
| `TreeFilterService::validate(array): TreeFilterDTO` | Valida grupos de condiciones, fechas y rangos; devuelve únicamente los campos reconocidos. No decide permisos ni amplía un alcance. |
| `TreeFilterDTO::fromValidated(array)` / `values()` | DTO inmutable de filtros previamente validados. No es una credencial de autorización. |
| `App\DTOs\TreeReadScope` | Alcance obligatorio y explícito mediante `organization(UUID)`, `department(UUID[])` o `national()`. No contiene actor ni acepta que `NULL` signifique acceso nacional. |
| `MapAccessService` | Autoriza el actor y el alcance en cada petición; exige permisos actuales de árboles y organizaciones. Los permisos adicionales se comprueban para inspecciones y mantenimientos. |
| `MapScopeRepository::applyTo(Builder, TreeReadScope, alias='trees')` | Añade `EXISTS` sobre organizaciones y jerarquía territorial activas, sin multiplicar las filas del árbol. |
| `TreeQueryRepository::query(TreeReadScope, TreeFilterDTO): Builder` | Selecciona `trees.*`, aplica alcance y filtros y expone el alias `latest_inspection`. No pagina ni establece el orden de presentación. |
| `TreeQueryRepository::ordered(Builder, TreeFilterDTO): Builder` | Comparte el orden de las bandejas, con desempate por UUID del árbol. |
| `InspectionQueryRepository` | Centraliza el orden determinista y los predicados de inspección; distingue la visita histórica del árbol con su última visita. |
| `TreeRepository::paginate` / `TreeInspectionRepository::latestTrees` | Conservan las APIs institucionales, paginación y relaciones de las bandejas existentes; delegan su selección al motor común. |
| `MapFilterService` / `MapTreeFilterDTO` | Componen el filtro forestal con alcance autorizado, encuadre, zoom y radio; validan referencias territoriales y de campaña dentro de ese alcance. |
| `MapTreeRepository` / `MapReadService` | Proyectan puntos, densidad, clusters, conteos, búsqueda y extensión sobre el motor compartido. Los repositorios reciben alcances ya autorizados. |

Los filtros distintos se combinan con AND. Las condiciones ANY/ALL son la excepción explícita descrita más adelante. La ausencia de un campo, `NULL`, texto vacío o una lista vacía no añade un criterio. El DTO conserva `false`, `0` y el texto `"0"`: una búsqueda `q=0` es real, un mínimo cero se aplica y `is_protected=0` selecciona árboles no protegidos. Los booleanos admitidos por validación son `true`, `false`, `1`, `0`, `"1"` y `"0"`; no se interpretan palabras arbitrarias como booleanos.

## Claves de selección forestal

Todas son opcionales. Los UUID de catálogo sirven para consultar datos históricos y no se reemplazan por nombres o códigos visibles.

| Clave | Tipo / valores | Criterio |
| --- | --- | --- |
| `q` | Texto, hasta 150 caracteres | Coincidencia parcial sin distinguir mayúsculas en código, `external_number`, descripción de ubicación, nombre científico o cualquier nombre común de la especie. |
| `code` | Texto, hasta 150 | Coincidencia parcial en el código automático de Tree. |
| `scientific_name` | Texto, hasta 150 | Coincidencia parcial en `Species.scientific_name`. |
| `common_name` | Texto, hasta 150 | Coincidencia parcial en cualquiera de los nombres comunes de Species; no solo en el principal. |
| `tree_id` | UUID | Individuo exacto, incluso si aún no tiene una inspección. |
| `species_id`, `family_id` | UUID | Especie exacta o familia de la especie. Family permanece normalizada; no se agrega una columna familiar a Tree. |
| `placement_id`, `growth_habit_id` | UUID | Emplazamiento o hábito del individuo. |
| `establishment_origin_id` | UUID | Origen de establecimiento, independiente del origen biogeográfico. |
| `biogeographic_origin_id` | UUID | Origen biogeográfico, sin inferir cómo se estableció el árbol. |
| `is_protected`, `is_heritage` | Booleanos | Igualdad exacta; ambos indicadores son independientes entre sí y de `current_status`. |
| `current_status` | `active`, `under_observation`, `dead`, `felled`, `transplanted`, `removed`, `replaced` | Estado del individuo; no equivale a anulación administrativa. |
| `department_id`, `municipality_id`, `organization_id` | UUID | Jerarquía del propietario institucional. No representan pertenencia geográfica calculada con el punto. |
| `inventory_id` | UUID | Participación del árbol en `inventory_trees`, correlacionando organización e individuo. |

`%`, `_` y la barra inversa se escapan en las búsquedas parciales para tratarlos literalmente. Todos los valores SQL se enlazan; los nombres de columna y alias proceden de listas internas.

La participación en un inventario usa `EXISTS`; pertenecer a dos campañas no duplica el árbol ni infla conteos. En estas consultas no se exige que la campaña permanezca activa: una campaña cerrada conserva sus participaciones. `inventory_id` en el listado de árboles y el mapa **no** exige que la última inspección se haya registrado dentro de esa campaña.

Las referencias taxonómicas y de catálogo inactivas conservan su capacidad de búsqueda histórica. Las opciones AJAX las identifican como inactivas. Esto no habilita su selección en una nueva captura: las reglas de escritura de cada dominio permanecen vigentes. Las organizaciones y los territorios inactivos se excluyen del alcance de lectura del motor.

## Última inspección y ANY/ALL

El motor incorpora siempre `LEFT JOIN LATERAL (...) AS latest_inspection`. La subconsulta correlaciona `organization_id` y `tree_id`, ordena por `inspection_date DESC, created_at DESC, id DESC` y toma una sola fila. Primero elige la última visita global del individuo y después aplica los filtros; nunca escoge una visita anterior porque esa visita sí coincida con la búsqueda.

El mismo orden se conserva en la consulta histórica y en la carga por lote de `latestInspection`. Una corrección no altera `created_at`; el UUID deshace el último empate. Un árbol sin inspecciones permanece en los listados sin criterios de inspección. Si se pide una condición, fecha o medición, debe existir una última visita que la satisfaga.

| Clave | Tipo / límite | Criterio sobre la última visita |
| --- | --- | --- |
| `date_from`, `date_to` | Fecha `AAAA-MM-DD` | Límites inclusivos de `inspection_date`; el final no puede preceder al inicial. |
| `maturity_state_id` | UUID | Estado de madurez almacenado. |
| `diameter_class_number`, `height_class_number` | Entero de 1 a 9223372036854775807 | Número de clase almacenado; la consulta no recalcula ni reemplaza la versión histórica. |
| `cap_cm_min`, `cap_cm_max` | Decimal no negativo | CAP en centímetros, límites inclusivos. |
| `dap_m_min`, `dap_m_max` | Decimal no negativo | DAP en metros, límites inclusivos. |
| `total_height_m_min`, `total_height_m_max` | Decimal no negativo | Altura total en metros, límites inclusivos. |
| `physical_condition_ids[]` | Hasta 100 UUID distintos | Condiciones existentes de `physical_mechanical`. |
| `phytosanitary_condition_ids[]` | Hasta 100 UUID distintos | Condiciones existentes de `phytosanitary`. |
| `condition_mode` | `any` o `all`; predeterminado `any` | Combina la unión de ambas listas de condiciones. |

Los límites de medición admiten hasta 12 cifras enteras y 12 decimales, sin notación científica; mínimo y máximo se comparan con BCMath. Un máximo inferior al mínimo se rechaza. Son parámetros de búsqueda y no cambian la precisión ni las reglas de captura dasométrica.

- **ANY**: la visita tiene al menos una de todas las condiciones seleccionadas, mediante `EXISTS`.
- **ALL**: esa misma visita tiene todas las condiciones seleccionadas, mediante `COUNT(DISTINCT condition_id)` igual al número de UUID seleccionados.
- Con ambas listas vacías no se exige ninguna condición, cualquiera que sea el modo.

No se juntan hallazgos de varias visitas para completar ALL. Una condición enviada en el grupo incorrecto o inexistente produce error de validación. Una condición o categoría inactiva se admite para consultar observaciones conservadas; no desaparece la historia al desactivar un catálogo.

La bandeja de **inspecciones históricas** conserva su semántica propia: filtra visitas individuales y su `inventory_id` exige que `inventory_tree_id` de esa visita corresponda a la campaña. Comparte los predicados y el orden mediante `InspectionQueryRepository`, pero no transforma esa bandeja en una consulta de última inspección. Véase [inspections.md](inspections.md).

## Mantenimientos

| Clave | Tipo / valores | Criterio |
| --- | --- | --- |
| `maintenance_type_id` | UUID | Tipo de intervención nacional. |
| `maintenance_status` | `pending`, `scheduled`, `assigned`, `in_progress`, `executed`, `verified`, `cancelled` | Estado exacto de la orden. |
| `maintenance_priority` | `low`, `normal`, `high`, `urgent` | Prioridad exacta. |
| `has_pending_maintenance` | Booleano | `true` exige una orden en `pending` o `scheduled`; `false` no añade filtro. |

Los criterios se aplican dentro de **un único EXISTS** de mantenimiento, correlacionado por `organization_id` y `tree_id`, con `deleted_at IS NULL`. Tipo, estado, prioridad y condición de pendiente deben coincidir en una misma orden. Tener varias órdenes coincidentes no multiplica el árbol.

Al aplicar un filtro de mantenimiento sin estado explícito se excluye `cancelled`. Las órdenes canceladas solo participan cuando se solicita exactamente `maintenance_status=cancelled`; las anuladas administrativamente siempre quedan fuera. Combinar ese estado con `has_pending_maintenance=true` devuelve vacío porque ambos criterios son incompatibles. `assigned` e `in_progress` no se consideran pendientes/programados para esta casilla. Sin filtros de mantenimiento no se exige que el árbol tenga alguna orden.

## Alcances, roles y exclusiones

| Alcance | Concesión vigente requerida | Límite de consulta |
| --- | --- | --- |
| `organization` | Cuenta con acceso al contexto institucional y `organizations.view` / `trees.view` | Solo la organización seleccionada. Es el alcance habitual de ORGANIZATION_ADMIN y OFFICIAL. |
| `department` | Permisos departamentales de lectura | Organizaciones de los departamentos efectivamente autorizados para el actor, normalmente DEPARTMENT_ADMIN. |
| `national` | Permisos nacionales explícitos | Organizaciones disponibles del país o países registrados, normalmente SUPER_ADMIN; no requiere membresías ficticias. |

Los nombres de rol describen el catálogo actual; el código consulta permisos y concesiones vigentes. Sin `scope` solicitado, el servicio prioriza el nacional autorizado, después los departamentos autorizados y finalmente la organización seleccionada. Solicitar un alcance no autorizado se rechaza. Los filtros territoriales solo estrechan un alcance y sus referencias se verifican dentro de él.

Los alcances amplios del mapa no amplían `TenantContext` ni conceden escritura. Abrir una ficha desde el mapa utiliza `POST /map/trees/{tree}/open` con CSRF, revalida el árbol y el alcance y selecciona explícitamente su organización. Las operaciones posteriores de Tree conservan sus Policies y controles institucionales.

Se requiere `inspections.view` para filtros de inspección y `maintenances.view` para filtros de mantenimiento en el mismo alcance. El resumen de última visita solo se serializa si el actor puede consultar inspecciones. Las opciones de esos dominios también comprueban sus permisos.

El mapa excluye siempre Tree con `deleted_at`, incluso para administradores. Rechaza `trashed`, `include_deleted` e `include_voided_trees` cuando solicitan incluir anulados. Las bandejas institucionales conservan `trashed=only` para la papelera, sujeto a `trees.restore`; no existe un modo compartido de mezclar activos administrativos y anulados. Un árbol muerto, talado o retirado que no fue anulado sigue siendo un registro consultable y puede aparecer en el mapa.

## Presentación y consulta espacial

| Clave | Tipo / límite | Uso |
| --- | --- | --- |
| `sort` | `code`, `created_at`, `scientific_name` | Orden de las bandejas; predeterminado `code`. |
| `direction` | `asc`, `desc` | Dirección de las bandejas; predeterminado `asc`; desempate por `trees.id`. |
| `page` | Entero de 1 a 1000000 | Paginación de bandejas. Búsqueda/opciones AJAX del mapa restringen a 1..1000. |
| `west`, `south`, `east`, `north` | Números en límites geográficos | Caja completa requerida en puntos, clusters y calor; oeste < este y sur < norte. |
| `radius_latitude`, `radius_longitude`, `radius_meters` | Centro válido y metros de 0 a 20000000 | Los tres valores deben enviarse juntos. |
| `zoom` | Entero de 0 a 22 | Nivel de agregación, predeterminado 8 en el DTO. |
| `render_mode` | `points`, `clusters`, `heat` | Modo visual; no modifica los filtros forestales. |

Los puntos del mapa se ordenan de forma estable por código e id y se limitan a `maps.max_points` (2000 por defecto), sin aplicar paginación de bandeja. El límite no reduce los conteos. `truncated=true` informa que la respuesta de puntos no contiene todos los individuos del encuadre. El modo clusters utiliza malla agregada cuando el zoom es menor que 10 o la cantidad supera el límite; el calor siempre agrega densidad. No representa riesgo, mortalidad ni diagnóstico sanitario.

La caja usa `ST_Intersects(geometry, ST_MakeEnvelope(..., 4326))`, con bordes incluidos. El radio usa `ST_DWithin(geometry::geography, centro::geography, metros)` y se combina con la caja cuando ambos existen. Longitud corresponde a X y latitud a Y. Los índices son `trees_geometry_gist` y `trees_geography_gist`; ningún radio se convierte a grados por una aproximación del navegador. Una caja que cruza el antimeridiano no se acepta como una caja invertida: esa composición permanece fuera del contrato HTTP actual.

Los metadatos `total`, `georeferenced` y `without_geometry` describen el conjunto forestal autorizado antes del encuadre; si hay radio, incluyen ese criterio métrico. `in_view` cuenta los puntos dentro del encuadre y `returned` describe los elementos enviados. Con radio, un Tree sin punto no puede coincidir. Sin radio, los árboles sin geometría pueden figurar en el total, pero nunca aparecen en 0,0 ni en los marcadores.

La extensión usa las geometrías reales filtradas. Si no existen puntos, puede recurrir a geometrías territoriales reales autorizadas; si tampoco existen, conserva el estado vacío y la vista general configurada. No se obtienen coordenadas desde nombres de municipios, cadenas GPS o ejemplos de documentos. La búsqueda del mapa opera sobre todos los individuos del conjunto autorizado y devuelve hasta 20 resultados por página. Un resultado sin ubicación conserva geometry=NULL y permite abrir su ficha, sin centrar el mapa ni crear un punto ficticio; si hay filtro de radio, solo pueden coincidir individuos con geometría.

## Verificación y límites conservados

El motor compartido pasó `TreeFilterEngineTest`: **27 pruebas y 239 aserciones**, con semántica de última visita, ANY/ALL, campañas, referencias inactivas, filtros booleanos, búsquedas literales, scopes institucionales y órdenes de mantenimiento. Los resultados históricos de Fase 6 y Fase 7 permanecen en sus reportes; no se suman aquí como pruebas nuevas.

`MapPerformanceTest` prepara comparaciones de 1 y 100 marcadores y un conjunto transaccional de 4096 puntos para `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`. No se afirma un conteo medido ni un plan verificado hasta terminar su ejecución coordinada. El artefacto optativo usa `SYSFOREST_MAP_EXPLAIN=1`; los datos pertenecen exclusivamente a `sysforest_testing` y se revierten. No se deshabilita el recorrido secuencial para demostrar GiST.

Se conservan las deudas previas: certificación del levantamiento, transformaciones de datum, altitud, corrección diferencial y validación territorial avanzada. La pertenencia institucional a un municipio no certifica que el punto esté dentro de su polígono. No se añaden geometrías territoriales de ejemplo, inferencias ambientales, importación ni reportes finales. Véanse [georeferencing.md](georeferencing.md), [tree-domain.md](tree-domain.md) y [maintenances.md](maintenances.md).

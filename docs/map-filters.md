# Motor compartido de filtros y mapas

## Selectores de condiciones en cascada · 04/11/2026

Los filtros web de árboles, inspecciones, mapas, análisis y reportes reutilizan `inspections/condition-fields.blade.php` en `filterMode`. La consulta pública de mapa, listado y reportes utiliza el mismo componente. Cada evaluación permite elegir varios órganos o grupos y, por cada uno, varias condiciones. Las selecciones físico-mecánicas y fitosanitarias permanecen independientes.

El selector de órganos es auxiliar y no tiene `name`: elegir un órgano sin condiciones solo muestra opciones, sin filtrar ni añadir parámetros GET. Se mantienen `physical_condition_ids[]`, `phytosanitary_condition_ids[]` y `condition_mode`. Quitar un órgano limpia únicamente sus condiciones, con una advertencia previa en la ayuda. Las referencias históricas inactivas siguen disponibles para consulta. No cambia ANY/ALL, la última inspección determinista, las reglas de autorización ni las proyecciones públicas.

El componente recibe UUID en privado y códigos estables en público. `MapFilters.sync` restaura condiciones y órganos mediante `SysforestConditionCascade.setValues(contenedor, ids)`; el helper actualiza Select2 sin disparar cambios de negocio. Cada selector de condiciones expone `data-filter-label` con evaluación y órgano para identificar los criterios. Los catálogos se preparan en servicios/repositorios, sin consultas en Blade.

La evidencia específica de esta presentación está en [condition-cascade-20261104.md](condition-cascade-20261104.md). Las secciones de fases y sus cifras de verificación se conservan como historia de sus respectivas entregas.

## Alcance de Fase 10

El listado de árboles, el listado de árboles con su última inspección y el mapa consultan un único motor forestal. PostGIS resuelve la selección espacial y las agregaciones. No se reconstruyen coordenadas desde `coordinate_raw`, no se cargan todos los árboles para filtrarlos en PHP o JavaScript y no se crean puntos para registros sin geometría.

El mapa institucional de Fase 10 mantiene autenticación JWT, sesión vigente, correo verificado y autorización actual. Fase 11 añade el alcance publicado del mismo motor para el portal anónimo; su contrato específico está en [public-portal.md](public-portal.md) y en la sección de publicación de este documento. Las exportaciones y los reportes finales siguen pendientes de fases posteriores; cada consumidor deberá definir su alcance y proyección.

## Contratos y responsabilidades

| Componente | Contrato y responsabilidad |
| --- | --- |
| `TreeFilterInput` | Reglas comunes de tipos y límites para las entradas forestales. `TreeInput::filters()` conserva su API delegando en estas reglas. |
| `TreeFilterService::validate(array): TreeFilterDTO` | Valida grupos de condiciones, fechas y rangos; devuelve únicamente los campos reconocidos. No decide permisos ni amplía un alcance. |
| `TreeFilterDTO::fromValidated(array)` / `values()` | DTO inmutable de filtros previamente validados. No es una credencial de autorización. |
| `App\DTOs\TreeReadScope` | Alcance obligatorio y explícito mediante `organization(UUID)`, `department(UUID[])`, `national()` o `published()`. Este último aplica publicación explícita, no permisos nacionales. No contiene actor ni acepta que `NULL` signifique acceso nacional. |
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

Las tablas siguientes describen el contrato institucional. Todas las claves son opcionales. Sus UUID de catálogo sirven para consultar datos históricos. En el portal público se mantienen los nombres de campo, pero las referencias llegan como códigos públicos y se resuelven internamente; sus exclusiones y diferencias figuran más adelante.

| Clave | Tipo / valores | Criterio |
| --- | --- | --- |
| `q` | Texto, hasta 150 caracteres | Coincidencia parcial sin distinguir mayúsculas en código, `external_number`, descripción de ubicación, nombre científico o cualquier nombre común de la especie. |
| `code` | Texto, hasta 150 | Coincidencia parcial en el código automático de Tree. |
| `scientific_name` | Texto, hasta 150 | Coincidencia parcial en `Species.scientific_name`. |
| `common_name` | Texto, hasta 150 | Coincidencia parcial en cualquiera de los nombres comunes de Species; no solo en el principal. |
| `tree_id` | UUID | Individuo exacto, incluso si aún no tiene una inspección. |
| `species_id`, `family_id` | UUID | Especie exacta o familia de la especie. Family permanece normalizada; no se agrega una columna familiar a Tree. |
| `common_name_id` | UUID | Nombre común exacto de `species_common_names`, principal o secundario. Selecciona los árboles de su especie mediante `EXISTS`, sin duplicarlos. |
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

## Nombres comunes y selectores de taxonomía · 13/10/2026

`Species` conserva varios `SpeciesCommonName` mediante `commonNames()`, con un máximo de un nombre principal. El árbol referencia una especie y hereda sus nombres; no se guardan copias de esos nombres en cada individuo ni se convierte cada alias en una especie distinta. Un mismo texto común puede identificar especies diferentes: la etiqueta de la opción incluye el nombre científico para distinguirlas.

Los formularios de consulta usan selectores buscables: **Nombre científico** envía `species_id` y **Nombre común** envía `common_name_id`. El motor común lleva los mismos criterios a mapas, bandejas, reportes y análisis. Los selectores AJAX comparten `MapOptionRepository`: `species` busca nombre científico o cualquiera de sus nombres comunes; `common_names` busca el nombre común o el científico asociado. Las opciones de especies incluyen todos sus nombres; las de nombres comunes muestran `nombre · nombre científico`. La consulta usa `EXISTS`, por lo que varios alias coincidentes no repiten especies ni árboles, ni inflan indicadores.

Familia, especie y nombre común se combinan con **AND**. Criterios existentes pero incompatibles producen cero resultados; no se ignora uno para ampliar la búsqueda. La cascada de opciones limita especies por familia y nombres comunes por familia/especie. Al cambiar familia se limpian especie y nombre común; al cambiar especie se limpia el nombre común. La recuperación de etiquetas seleccionadas conserva criterios históricos, aunque sean incompatibles entre sí.

Las claves de texto `common_name` y `scientific_name` siguen aceptando coincidencia parcial para conservar URLs y clientes anteriores. Los formularios muestran un aviso cuando llega una búsqueda antigua y permiten quitarla. Seleccionar una opción del catálogo reemplaza su criterio de texto equivalente; no se convierte silenciosamente un texto ambiguo en la primera especie encontrada.

Las proyecciones de puntos privadas y públicas entregan `common_names` como arreglo de textos con todos los nombres registrados, ordenados por principal, nombre e ID. Se conserva `common_name` como nombre principal —o el primero de ese orden si no hay principal— por compatibilidad. Sin nombres: `common_names=[]` y `common_name=null`. El popup, las fichas y los listados muestran todos los nombres; el título público puede conservar el principal. La tabla de reportes usa `common_names`; las exportaciones conservan también la columna del principal. Estas lecturas mantienen las referencias históricas de catálogo inactivas y no habilitan su uso en nuevas capturas.

## Última inspección y ANY/ALL

El motor incorpora siempre `LEFT JOIN LATERAL (...) AS latest_inspection`. La subconsulta correlaciona `organization_id` y `tree_id`, ordena por `inspection_date DESC, created_at DESC, id DESC` y toma una sola fila. En los alcances institucionales primero elige la última visita global del individuo y después aplica los filtros; nunca escoge una visita anterior porque esa visita sí coincida con la búsqueda.

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

Los nombres de rol describen el catálogo actual; el código consulta permisos y concesiones vigentes. Desde el **25/11/2026**, sin `scope` solicitado, Cartografía, Diagramas/Gráficas y Reportes privados usan únicamente la organización seleccionada. No hay alternativa automática nacional o departamental. Las páginas sin organización muestran selección y enlaces amplios solo si están autorizados, sin cargar un dataset; las consultas de datos sin contexto rechazan el acceso. Solicitar un alcance explícito no autorizado también se rechaza. Los filtros territoriales solo estrechan un alcance y sus referencias se verifican dentro de él.

El selector de vista de Cartografía elimina departamento, municipio, organización e inventario al cambiar de alcance, para evitar referencias de otro contexto; conserva criterios forestales compatibles y descarta los de dominios que el destino no autorice. Restablecer filtros conserva el alcance elegido. Los enlaces entre mapa, gráficas y reportes mantienen un `scope` explícito; la navegación normal después de cambiar organización vuelve al institucional. Atrás hacia una URL sin `scope` también recupera ese valor inicial. El portal público conserva su alcance publicado independiente. Véase [contrato y evidencia del cambio](map-organization-scope-20261125.md).

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

## Alcance publicado incorporado en Fase 11

`TreeReadScope::published()` es independiente de JWT y de `TenantContext`. `MapScopeRepository` exige organización publicada y activa con jerarquía territorial activa. `PublicVisibilityRepository` añade árbol publicado, ausencia de anulación y participación en un inventario publicado, activo y distinto de `draft`. Las campañas cerradas o archivadas pueden conservar historia pública. No se cambia la semántica institucional de campañas.

`PublicTreeFilterService` valida una lista cerrada, resuelve códigos con `PublicReferenceRepository` y reutiliza `TreeFilterService` para rangos, grupos de condiciones y booleanos. El DTO público contiene `trees`, `publicValues` y `map`. Los formularios y enlaces conservan códigos; los UUID internos no se serializan. Los territorios usan códigos compuestos país/departamento/municipio; la organización usa `public_code` y la campaña `public_code~inventory.code`.

Las diferencias públicas son explícitas:

- `q` solo consulta código y nombres comunes/científico. Los conteos no pueden usarse para buscar textos operativos privados.
- La subconsulta LATERAL limita primero a inspecciones publicadas cuya campaña, si existe, es pública elegible. Mantiene `inspection_date DESC, created_at DESC, id DESC`. Después aplica fechas, medidas y ANY/ALL a esa única visita. Cuando todas las visitas son publicables, coincide con el resultado institucional.
- `inventory_id` exige publicación de la campaña seleccionada dentro del mismo `EXISTS` de participación. No redefine la última visita.
- El `EXISTS` de mantenimiento restringe `executed|verified` en la misma orden que satisface tipo y estado. La consulta pública no ofrece prioridad ni pendientes.
- Se rechazan `scope`, `tree_id`, papelera e inclusión de anulados, campos desconocidos y estados de mantenimiento no publicables.
- El orden admite `code`, `common_name`, `scientific_name`, `municipality` y `latest_inspection_date`, con `asc|desc`; las páginas públicas admiten 1..1000. El orden por nombre común prioriza el principal, después nombre e ID; las relaciones y proyecciones de ambos mapas conservan ese mismo orden y entregan todos los nombres en `common_names`.

`PublicTreeRepository` compone el motor común para tabla, indicadores, ficha e historiales. `PublicMapService` usa el mismo `MapTreeRepository` para puntos, clusters, calor, extensión y búsqueda. El radio se aplica también a tabla e indicadores; la caja limita el dibujo. La geometría, precisión de parámetros, unidades métricas, ausencia de punto y límites de transporte mantienen las reglas espaciales anteriores.

Los Resources públicos proyectan campos autorizados y referencias por códigos; nunca se devuelve un modelo completo. Las fotos se resuelven por ULID y revalidan el árbol y el propietario publicable. Véase [public-portal.md](public-portal.md) para rutas, filtros, errores y ejemplos sin credenciales.

## Verificación y límites conservados

El motor compartido pasó `TreeFilterEngineTest`: **27 pruebas y 239 aserciones**, con semántica de última visita, ANY/ALL, campañas, referencias inactivas, filtros booleanos, búsquedas literales, scopes institucionales y órdenes de mantenimiento. Los resultados históricos de Fase 6 y Fase 7 permanecen en sus reportes; no se suman aquí como pruebas nuevas.

`MapPerformanceTest` registró durante Fase 10 73 consultas para 1 y para 100 marcadores completos, y un plan natural con `trees_geometry_gist` sobre 4096 puntos. Los resultados y sus límites están en [maps.md](maps.md) y [phase-10-performance.json](phase-10-performance.json). El artefacto optativo usa `SYSFOREST_MAP_EXPLAIN=1`; los datos pertenecen exclusivamente a `sysforest_testing` y se revierten. No se deshabilita el recorrido secuencial para demostrar GiST.

`PublicTreeEngineTest` y `PublicPortalPerformanceTest` verifican el alcance publicado y su reutilización espacial. Los resultados de Fase 11 se documentan por separado; no se suman ni sustituyen las cifras históricas anteriores.

Se conservan las deudas previas: certificación del levantamiento, transformaciones de datum, altitud, corrección diferencial y validación territorial avanzada. La pertenencia institucional a un municipio no certifica que el punto esté dentro de su polígono. No se añaden geometrías territoriales de ejemplo, inferencias ambientales, importación ni reportes finales. Véanse [georeferencing.md](georeferencing.md), [tree-domain.md](tree-domain.md) y [maintenances.md](maintenances.md).


## Revisión de selectores y nombres comunes · 13/10/2026

Los filtros de árboles, inspecciones, mapas, tablero analítico, reportes y listado público usan selectores con búsqueda para nombre científico y nombre común. La especie acota las opciones de nombres comunes; en los formularios remotos, la familia acota también las especies. Los filtros locales incluyen referencias inactivas para consultar historia; esto no habilita referencias inactivas en la captura de nuevos árboles. Las búsquedas generales, códigos, fechas y rangos mantienen sus tipos apropiados.

La especie conserva varios nombres comunes, uno de ellos principal. La consulta exacta por cualquier alias utiliza `EXISTS`, combina criterios con AND y no multiplica árboles ni inspecciones. Las tablas de árboles, inspecciones y reportes, los popups y el portal muestran todos los nombres disponibles. La API móvil ya entrega los alias activos y permite buscar la especie por un nombre secundario; no cambia su contrato de captura.

Verificación actual, independiente de las cifras históricas anteriores:

- `bash bin/check` terminó con código 0: formato PSR-12, sintaxis JavaScript y sintaxis de 550 archivos PHP. No se ejecutaron ni recrearon suites automatizadas.
- 21 comprobaciones manuales de backend y 22 de integración, realizadas fuera del repositorio fuente sobre datos existentes en transacciones PostgreSQL de solo lectura. Los alias Palma y Palma Botella de Roystonea regia devolvieron los mismos 137 árboles y 137 inspecciones; se comprobaron intersecciones incompatibles, ausencia de duplicados, búsquedas literales y códigos públicos.
- El XLSX temporal generado y releído conservó 137 filas, ambos nombres y el criterio legible. Los enlaces de CSV, Excel, GeoJSON y PDF conservaron `common_name_id`. El temporal se eliminó después de su lectura.
- Revisión en navegador de cascada científico/nombre común, selección de ambos alias, reportes, gráficos, mapa y consulta pública. El mapa conservó 137 árboles totales, 136 con punto y 1 sin ubicación; el portal filtró Samán mediante su código público, conservando solo el árbol publicado elegible. Se revisó el desplegable en ventana estrecha.
- Proyección manual de 2.852 puntos, 154 con varios nombres, sin consultas adicionales desde Resources. Contratos de proyección pública comprobados también con modelos en memoria: arreglo completo, campo principal compatible, especie ausente y ausencia de UUID interno.

Límites: no había nombres comunes inactivos en los datos locales para ejercitar ese caso histórico. Se revisó que los filtros no imponen actividad y que captura conserva su validación anterior; no se crearon datos ficticios ni se modificaron registros forestales.

# Portal público de SYSFOREST

## Alcance de Fase 11

El portal ofrece consulta anónima de árboles expresamente publicados, su mapa, indicadores, inspecciones, intervenciones realizadas y fotografías seleccionadas. Las páginas están en `/portal`; la API está en `/api/public/v1`. No requiere cuenta, JWT, cookie de sesión, membresía ni selección de `TenantContext`. Las rutas públicas son de lectura y no activan contexto institucional.

La consulta comparte `TreeQueryRepository`, `InspectionQueryRepository`, `MapTreeRepository` y PostGIS con el mapa privado. Usa un alcance diferente, `TreeReadScope::published()`, y Resources públicos con listas explícitas de campos. Un alcance público no concede permisos administrativos ni equivale al alcance nacional autenticado.

La entrega no implementa exportación, importación ni reportes finales de Fase 12. El motor deja contratos reutilizables para que un consumidor futuro componga su propia autorización, proyección y formato sin duplicar la selección forestal.

## Cadena de publicación

Las cinco banderas `is_public` parten de `false`. La consulta revalida toda la cadena en cada petición; publicar un registro aislado no publica a sus ascendientes ni a otros registros relacionados.

| Registro | Condiciones para participar en la lectura pública |
| --- | --- |
| Organización | `is_public=true`, activa, sin anulación y municipio, departamento y país activos. Debe tener `public_code`. |
| Inventario | Organización elegible, `is_public=true`, `active=true` y estado distinto de `draft`. Las campañas `closed` y `archived` conservan historia pública cuando siguen disponibles y publicadas. |
| Árbol | `is_public=true`, sin anulación administrativa y al menos una participación en un inventario público elegible de su misma organización. |
| Inspección | Árbol elegible, `is_public=true` y, cuando tiene `inventory_tree_id`, esa participación corresponde a una campaña pública elegible. Una visita sin campaña puede ser publicada. |
| Mantenimiento | Árbol elegible, orden no anulada y estado `executed` o `verified`. No tiene bandera de publicación independiente. |
| Evidencia | `is_public=true`, no anulada y propietario elegible: árbol, inspección pública o mantenimiento realizado/verificado. También se exige toda la cadena del árbol propietario. |

Un Tree `dead`, `felled`, `transplanted`, `removed` o `replaced` puede conservar información pública: su estado forestal no es una anulación administrativa. Los registros con `deleted_at` quedan fuera. Una campaña privada de un árbol no se hace pública porque ese mismo árbol participe en otra campaña publicada.

La administración de las banderas permanece dentro de la aplicación autenticada, con `publication.manage`, comprobación vigente del actor, contexto y concurrencia. La primera publicación reserva un `Organization.public_code` único a partir del código institucional; luego es inmutable. Una evidencia obtiene un `public_id` ULID independiente de su UUID interno y puede tener `public_caption` escrito expresamente. Nunca se copia automáticamente el pie privado de la fotografía.

## Identificadores y opciones

Los nombres de parámetros terminados en `_id` se conservan para reutilizar la interfaz, pero **sus valores públicos son códigos**, no UUID. El backend resuelve esos códigos a claves internas dentro del alcance publicado. Una referencia inexistente o privada produce el mismo error de opción no disponible.

| Parámetro o identidad | Representación pública |
| --- | --- |
| `department_id` | `COUNTRY~DEPARTMENT`; ejemplo de estructura: `170~50`. |
| `municipality_id` | `COUNTRY~DEPARTMENT~MUNICIPALITY`; ejemplo de estructura: `170~50~50001`. |
| `organization_id` y organización de la URL | `Organization.public_code`, reservado e inmutable. |
| `inventory_id` | `ORGANIZATION_PUBLIC_CODE~INVENTORY_CODE`. |
| Especie, familia y demás referencias nacionales | `code` del catálogo correspondiente; por ejemplo `SPECIES_LICANIA_TOMENTOSA`. |
| `physical_condition_ids[]`, `phytosanitary_condition_ids[]` | Códigos de condiciones del grupo correspondiente. |
| Árbol en una URL | Par `organization.public_code` + `Tree.code`. |
| `id` de una entidad GeoJSON pública | `ORGANIZATION_PUBLIC_CODE~TREE_CODE`. |
| Fotografía | `Evidence.public_id`, ULID de 26 caracteres. |

Los ejemplos describen formatos, no garantizan que exista una institución o árbol publicado. Publicar catálogos nacionales no publica automáticamente individuos. Las opciones permiten referencias de catálogo inactivas para consultar historia y las señalan como tales; no habilitan nuevas capturas con esos valores.

`GET /api/public/v1/options/{kind}` acepta estos tipos: `departments`, `municipalities`, `organizations`, `inventories`, `species`, `families`, `placements`, `growth_habits`, `establishment_origins`, `biogeographic_origins`, `maturity_states`, `physical_conditions`, `phytosanitary_conditions` y `maintenance_types`. Devuelve hasta 30 resultados con `{id: código, text: etiqueta}` y `pagination.more`. Los territorios se limitan a la jerarquía activa de organizaciones publicadas; los inventarios cumplen su propia política de publicación.

## Filtros admitidos

`PublicTreeFilterService` usa una lista cerrada: los campos desconocidos se rechazan. `validate(array, bool requireBbox=false)` compone un `PublicTreeFilterDTO` inmutable con:

- `trees`: `TreeFilterDTO` con referencias internas ya resueltas y reglas forestales comunes.
- `publicValues`: valores públicos validados, conservados para formularios y enlaces; incluye `sort`, `direction` y `page` cuando se reciben.
- `map`: `MapTreeFilterDTO` con el mismo filtro forestal, caja, zoom y radio.

`keys()` expone todas las claves superiores admitidas; `publicFilterKeys()` entrega las claves persistibles del formulario y `render_mode`, excluyendo ordenación, páginas, término AJAX, zoom y caja. Esta lista alimenta la configuración del navegador para evitar un segundo contrato de filtros.

| Grupo | Claves / valores |
| --- | --- |
| Texto | `q`, `code`, `common_name`, `scientific_name`: hasta 150 caracteres. |
| Taxonomía | `species_id`, `family_id`: códigos de catálogo. |
| Características permanentes | `placement_id`, `growth_habit_id`, `establishment_origin_id`, `biogeographic_origin_id`: códigos; `is_protected`, `is_heritage`: booleanos. |
| Estado | `current_status`: `active`, `under_observation`, `dead`, `felled`, `transplanted`, `removed`, `replaced`. |
| Territorio y campaña | `department_id`, `municipality_id`, `organization_id`, `inventory_id`: códigos con los formatos anteriores. |
| Inspección | `date_from`, `date_to`, `maturity_state_id`, `diameter_class_number`, `height_class_number`. |
| Mediciones | `cap_cm_min/max`, `dap_m_min/max`, `total_height_m_min/max`; mismos límites y unidades del [motor común](map-filters.md). |
| Condiciones | `physical_condition_ids[]`, `phytosanitary_condition_ids[]`: hasta 100 códigos distintos por grupo; `condition_mode=any|all`. |
| Intervenciones | `maintenance_type_id`: código; `maintenance_status=executed|verified`. |
| Orden público | `sort=code|common_name|scientific_name|municipality|latest_inspection_date`; `direction=asc|desc`. Por defecto código ascendente, con desempate por UUID interno nunca serializado. |
| Navegación y búsqueda AJAX | `page`: 1..1000; `term`: hasta 150 caracteres. |
| Espacial | `west`, `south`, `east`, `north`; `radius_latitude`, `radius_longitude`, `radius_meters`; `zoom=0..22`. |
| Representación | `render_mode=points|clusters|heat`. |

`q` busca solo código, nombre científico y nombres comunes. No consulta `external_number`, descripción de ubicación ni texto interno: excluir un atributo de JSON no bastaría si los conteos permitieran inferirlo. Los caracteres `%`, `_` y barra inversa se tratan literalmente. Las referencias y valores SQL se enlazan.

No se admiten `scope`, `tree_id`, `trashed`, `include_deleted`, `include_voided_trees`, `maintenance_priority` ni `has_pending_maintenance`, ni siquiera con un valor falso. Tampoco se admiten estados pendientes, programados, asignados, en proceso o cancelados como filtros públicos. Los booleanos `false`, `0` y `"0"` se conservan como criterios explícitos; vacío o `null` no añade un filtro.

Las referencias territoriales se resuelven en orden país/departamento/municipio/organización/campaña cuando corresponda. Las referencias de catálogo de un grupo incorrecto se rechazan; las condiciones inactivas conservadas en observaciones históricas siguen siendo consultables.

## Última visita, campañas y mantenimiento

La subconsulta `LEFT JOIN LATERAL ... latest_inspection` delimita primero las visitas publicables y luego aplica `inspection_date DESC, created_at DESC, id DESC LIMIT 1`. Los criterios forestales de fecha, clase, madurez, medidas y condiciones se aplican **después** de elegir esa única visita. No se busca una visita anterior para hacer coincidir el filtro.

El orden es idéntico al privado; cambia el universo elegible. Si todas las visitas son públicas y sus campañas son elegibles, la última visita pública coincide con la privada. Una visita privada más reciente no sustituye la última pública ni permite inferir sus medidas. La ficha y la historia paginada reutilizan los mismos predicados y el mismo desempate, incluidos microsegundos y UUID.

ANY exige al menos una condición de la unión de ambas listas. ALL exige todas en **la misma última visita**, usando `COUNT(DISTINCT condition_id)`. No mezcla hallazgos entre fechas. Las listas vacías no exigen condiciones.

`inventory_id` filtra participación del árbol mediante `EXISTS`, con organización y árbol correlacionados y la campaña seleccionada elegible. No exige que la última inspección pertenezca a la campaña filtrada. Aparte, cada inspección que declare una campaña debe pertenecer a una campaña pública para ser elegible. Varias participaciones no duplican al árbol ni los indicadores.

El mantenimiento público usa **un mismo EXISTS** para tipo, estado solicitado, estado publicable `executed|verified` y ausencia de anulación. Una orden pendiente de poda y otra ejecutada de riego no hacen coincidir un filtro público de poda. La historia muestra fecha realizada, tipo y estado; excluye asignaciones, prioridades, notas y resultados libres.

## Contratos de lectura y rutas

`PublicTreeRepository::query(DTO)` deja un Builder filtrado para futuros consumidores. `paginate(DTO, perPage=20)` entrega la tabla; `indicators(DTO)` devuelve `trees`, `georeferenced`, `without_geometry`, `species`, `families`, `municipalities`, `organizations` y `states[{value,label,count}]`. Las cantidades distintas excluyen los valores nulos; los estados incluyen cero cuando no hay coincidencias.

`find(organizationPublicCode, treeCode)` prepara las relaciones públicas y `publicLatestInspection`. `inspections(Tree,page)`, `maintenances(Tree,page)` y `evidences(Tree,page)` paginan respectivamente 15, 15 y 12 registros. Sus claves de página son `inspections_page`, `maintenances_page` y `evidences_page`; la ficha rechaza otros parámetros. Los métodos `inspectionQuery`, `maintenanceQuery` y `evidenceQuery` conservan los predicados reutilizables. `findEvidence(publicId)` vuelve a validar toda la cadena antes de entregar una imagen.

| Método y ruta | Salida |
| --- | --- |
| `GET /portal` | Página de inicio pública. |
| `GET /portal/map` | Mapa público. |
| `GET /portal/trees` | Tabla Blade con filtros y paginación. |
| `GET /portal/organizations/{organization}/trees/{tree}` | Ficha pública por códigos. |
| `GET /api/public/v1/trees` | Paginador JSON de árboles con proyección pública. |
| `GET /api/public/v1/indicators` | Indicadores del conjunto publicado y filtrado. |
| `GET /api/public/v1/organizations/{organization}/trees/{tree}` | `data` de ficha y paginadores `inspections`, `maintenances`, `evidences`. |
| `GET /api/public/v1/map/trees` | Puntos GeoJSON acotados. |
| `GET /api/public/v1/map/clusters` | Puntos o celdas según zoom y volumen. |
| `GET /api/public/v1/map/heat` | Densidad agregada sin atributos individuales. |
| `GET /api/public/v1/map/extent` | Extensión, conteos y geometrías territoriales reales. |
| `GET /api/public/v1/map/search` | Hasta 20 coincidencias por página, por código/nombres. |
| `GET /api/public/v1/options/{kind}` | Opciones paginadas con códigos. |
| `GET /portal/media/{evidence}` | Derivado WebP de una fotografía publicada. |

Ejemplos de consulta local, sin JWT ni cookies; no publican registros ni modifican datos:

```bash
curl --get 'http://localhost:8080/api/public/v1/trees' \
  -H 'Accept: application/json' --data-urlencode 'is_protected=0' \
  --data-urlencode 'sort=scientific_name' --data-urlencode 'page=1'

curl --get 'http://localhost:8080/api/public/v1/map/trees' \
  -H 'Accept: application/json' --data-urlencode 'west=-74' \
  --data-urlencode 'south=3' --data-urlencode 'east=-73' \
  --data-urlencode 'north=5' --data-urlencode 'zoom=12'

curl --get 'http://localhost:8080/api/public/v1/options/species' \
  -H 'Accept: application/json' --data-urlencode 'term=Licania'

curl --get 'http://localhost:8080/api/public/v1/indicators' \
  -H 'Accept: application/json' --data-urlencode 'maintenance_type_id=MNT_PRUNING'
```

Una instalación sin árboles publicados devuelve estados vacíos y conteos cero. No se insertan registros forestales operativos para rellenar la interfaz.

## Espacio, límites y privacidad

La caja es obligatoria y completa en puntos, clusters y calor. Debe cumplir oeste < este y sur < norte; no representa cruces del antimeridiano. El radio métrico usa `ST_DWithin` sobre `geography` y necesita centro más metros, de 0 a 20.000.000. La caja usa `ST_Intersects`, con bordes incluidos. Se conserva la precisión del double en los parámetros enlazados.

La tabla y los indicadores describen el conjunto forestal filtrado y, cuando existe, el radio; la caja limita el dibujo. `total`, `georeferenced`, `without_geometry`, `in_view`, `returned`, `truncated` y `aggregation` conservan la semántica del [mapa compartido](maps.md). Hay como máximo 2.000 marcadores por respuesta, y la malla acotada conserva los conteos de todos los puntos coincidentes. La densidad representa cantidad de árboles, no riesgo o diagnóstico.

Sin geometría, el árbol puede aparecer en tabla, búsqueda e indicadores, pero no se coloca en 0,0. Con radio, solo coinciden individuos con punto. Extensión y polígonos usan cartografía real del alcance publicado; los avisos de complejidad, invalidez y truncamiento territorial permanecen visibles. No se reconstruyen coordenadas desde GPS textual o nombres de lugares.

Los Resources excluyen UUID internos, propietarios técnicos, usuarios, correos, teléfonos, membresías, asignaciones, fechas de auditoría, tokens, GPS técnico, número legado y textos operativos libres. Las inspecciones publican medidas ya almacenadas, clases, madurez, condiciones, categorías de fauna y fechas de floración/fructificación; no recalculan fórmulas ni publican notas libres de fenología, fauna o diagnóstico.

Las fotos se sirven mediante una URL pública opaca tras revalidar publicación. El servicio decodifica los píxeles, aplica orientación JPEG y codifica WebP sin EXIF/XMP/IPTC. No entrega el original ni su nombre, ruta, checksum o pie privado. El derivado puede conservarse en caché interna, pero la elegibilidad se comprueba antes de usarla y antes de responder 304. La respuesta exige revalidación HTTP (`max-age=0, must-revalidate`). Los bytes ya descargados por una persona no pueden revocarse retroactivamente.

HTML y API usan `Cache-Control: no-store`; no se crea una caché compartida de datos públicos. Las cabeceras incluyen `nosniff`, denegación de iframe y restricciones de permisos del navegador. Los límites por IP son 90 páginas, 180 solicitudes API y 240 imágenes por minuto, configurables. Las entradas inválidas devuelven 422; identidad pública inexistente o fuera de publicación, 404; exceso de solicitudes, 429. Los errores de infraestructura no deben exponer SQL, trazas, rutas o credenciales.

## Verificación reproducible

`PublicTreeEngineTest` comprueba predicados de publicación, última visita, ANY/ALL, campañas, consulta literal, estados, propietarios de fotos, referencias por código e igualdad entre consumidores. `PublicPortalPerformanceTest` mide consultas en 1, 100, 1.024 y 2.048 individuos públicos de prueba, limita el crecimiento a cinco consultas y verifica respuestas acotadas; también obtiene un plan espacial natural sobre 4.096 puntos. Los resultados definitivos se registran en el reporte de Fase 11, no se infieren de la existencia de estos tests.

La corrida focal coordinada pasó **31 pruebas de motor público y 2 pruebas de rendimiento**. El artefacto de consultas registra los mismos conteos para 1, 100, 1.024 y 2.048 árboles:

| Operación de servicio | Consultas SQL en cada tamaño |
| --- | --- |
| Puntos | 10 |
| Tabla con indicadores y preparación de filtros | 16 |
| Clusters | 6 |
| Calor | 5 |
| Búsqueda | 7 |
| Opciones de inventario | 5 |

La ventana incluye los servicios y la proyección, y excluye la preparación de datos; no equivale a una petición HTTP completa. Con 2.048 puntos el payload se limita a 2.000 e informa truncamiento. El plan publicado con 4.096 puntos usó `trees_geometry_gist`, devolvió una fila y mantuvo `enable_seqscan`, `enable_indexscan` y `enable_bitmapscan` en `on`. Registró 1,784 ms de planificación y 0,196 ms de ejecución para ese fixture selectivo. Estas cifras focales no sustituyen el resultado global de la fase.

Ejecutar serialmente, desde `/home/josev/SYSFOREST`, cuando `sysforest_testing` esté libre:

```bash
docker compose exec -T app php artisan test tests/Feature/PublicTreeEngineTest.php
docker compose exec -T -e SYSFOREST_PUBLIC_EXPLAIN=1 app php artisan test tests/Feature/PublicPortalPerformanceTest.php
docker compose exec -T app cat /tmp/sysforest-phase11-query-count.json
docker compose exec -T app cat /tmp/sysforest-phase11-explain.json
```

Los fixtures están dentro de transacciones de la base de pruebas. El opt-in genera únicamente artefactos técnicos con SQL y parámetros sintéticos; no crea árboles ni publicaciones en la base operativa. Los tiempos de un plan selectivo no constituyen una garantía de latencia productiva.

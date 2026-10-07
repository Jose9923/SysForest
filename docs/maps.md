# Mapas de SYSFOREST

## Alcance y componentes

Fase 10 incorpora un mapa privado en `/map` para consultar individuos, agruparlos y representar densidad. Utiliza exclusivamente `Tree.geometry`, un punto 2D con SRID 4326, y los datos persistidos de cada dominio. No reconstruye puntos desde texto GPS ni modifica árboles, inspecciones, mantenimientos o evidencias. Fase 11 incorpora `/portal/map` sobre ese mismo motor, con publicación explícita y proyecciones anónimas. Las secciones institucionales de este documento conservan el contrato privado; las diferencias públicas están documentadas más adelante y en [public-portal.md](public-portal.md).

Se mantiene el flujo `Controller → Service → Repository → Model`. Blade recibe datos preparados; JavaScript representa capas y coordina peticiones. La autorización, las condiciones forestales, las distancias y los conteos se resuelven en el backend. El contrato completo de criterios está en [map-filters.md](map-filters.md).

| Componente | Responsabilidad |
| --- | --- |
| `MapController` | Delega las operaciones y devuelve Blade/JSON o persiste una selección ya autorizada. |
| `MapAccessService` / `TreeReadScope` | Resuelven permisos vigentes y representan un alcance explícito separado de `TenantContext`. |
| `MapFilterService` / `MapTreeFilterDTO` | Componen filtros comunes, alcance, encuadre, zoom y radio validados. |
| `TreeFilterService` / `TreeFilterDTO` | Validan el contrato forestal compartido con las bandejas. |
| `TreeQueryRepository` / `InspectionQueryRepository` | Aplican los filtros comunes, incluida la última visita determinista y las relaciones mediante `EXISTS`. |
| `MapScopeRepository` | Restringe organizaciones y jerarquía territorial activas sin multiplicar árboles. |
| `MapTreeRepository` | Ejecuta PostGIS, conteos, proyecciones acotadas, búsqueda, extensión y agregación. |
| `MapOptionRepository` | Resuelve opciones paginadas, referencias autorizadas y geometrías territoriales reales. |
| `MapTreeResource` / `MapCellResource` | Proyectan campos explícitos; no serializan modelos completos. |
| `MapTreeNavigationService` | Reautoriza el alcance elegido y el árbol antes de activar la organización de la ficha. |

## Identidad, permisos y fichas

Las rutas heredan `jwt.cookie`, `identity.session`, `identity.verified` y `tenant.share`. Las consultas amplias quedan fuera del middleware que exige tenant seleccionado. Esto permite leer un ámbito nacional o departamental sin fabricar membresías ni sustituir las reglas de las fichas privadas.

| Actor y concesión vigente | Alcance |
| --- | --- |
| `ORGANIZATION_ADMIN` / `OFFICIAL` | Organización seleccionada, con membresía, rol y permisos vigentes. |
| `DEPARTMENT_ADMIN` | Organizaciones de sus departamentos autorizados; lectura. |
| `SUPER_ADMIN` | Alcance nacional autorizado, sin exigir selección ni membresía artificial. |

El código exige cuenta activa y verificada, `organizations.view` y `trees.view` en el mismo ámbito; no autoriza por el nombre del rol. Sin `scope`, prioriza el nacional permitido, después los departamentos autorizados y finalmente la organización seleccionada. `scope=organization|department|national` solicita un ámbito concreto, pero no concede autoridad.

Los filtros y opciones de inspección requieren `inspections.view`; los de mantenimiento, `maintenances.view`. Sin permiso de inspecciones, `latest_inspection` es `null`. Los UUID territoriales y de inventario se comprueban dentro del alcance; referencias ajenas devuelven 404.

Cada operación reconsulta el estado actual. La optimización de `MapAccessService` elimina revalidaciones repetidas dentro de una operación, sin cachear concesiones entre solicitudes. El DTO estructural no es una credencial.

**Ver ficha utiliza POST con CSRF**:

1. El `open_url` conserva `?scope=organization|department|national`.
2. `MapTreeNavigationService::open(User, treeId, input)` valida esa selección y vuelve a resolver permisos.
3. Busca el árbol dentro del alcance, obtiene su organización real, activa `TenantContext` y aplica `TreePolicy::view`.
4. El Controller guarda `active_organization_id` y redirige exclusivamente a `trees.show`.

No se acepta organización ni URL de retorno para decidir propietario o destino. Conservar el tipo de alcance evita que una cuenta con membresía en A y rol departamental en B pierda la ficha de A al abrirla desde `scope=organization`. Cambiar el parámetro a `national` no eleva privilegios. Los middleware limpian el contexto al finalizar.

Véase [multitenancy.md](multitenancy.md) para identidad, membresía, selección, permisos y contexto de escritura.

## Contratos HTTP

| Método y ruta | Resultado |
| --- | --- |
| `GET /map` | Vista Blade y configuración autorizada. |
| `GET /api/v1/map/trees` | `FeatureCollection` de puntos limitada y con conteos. |
| `GET /api/v1/map/clusters` | Puntos o celdas según zoom y cantidad visible. |
| `GET /api/v1/map/heat` | Coordenadas agregadas y peso; sin identidad ni atributos individuales. |
| `GET /api/v1/map/extent` | Conteos, `bounds` y `territories`. |
| `GET /api/v1/map/search` | Hasta 20 coincidencias por página y `pagination.more`. |
| `GET /api/v1/map/options/{kind}` | Hasta 30 opciones por página y `pagination.more`. |
| `POST /map/trees/{tree}/open` | Selección institucional autorizada y redirección. |

Puntos, clusters y calor exigen `west`, `south`, `east` y `north` dentro de los límites geográficos, con oeste menor que este y sur menor que norte. `zoom` admite enteros de 0 a 22. Una caja invertida no representa un cruce del antimeridiano; ese caso queda fuera del contrato actual.

El radio opcional exige conjuntamente `radius_latitude`, `radius_longitude` y `radius_meters`, de 0 a 20.000.000 metros. Las búsquedas AJAX reciben `term`, de hasta 150 caracteres, y `page`, entre 1 y 1.000. El término es obligatorio para individuos y opcional en opciones.

Los tipos de opciones son `departments`, `municipalities`, `organizations`, `inventories`, `species`, `families`, `placements`, `growth_habits`, `establishment_origins`, `biogeographic_origins`, `maturity_states`, `physical_conditions`, `phytosanitary_conditions` y `maintenance_types`. Las entradas de catálogo inactivas se identifican para consulta histórica; organizaciones y territorios deben seguir activos.

Errores de entrada devuelven 422; falta de autenticación, 401 JSON también con `Accept: */*`; falta de permiso, 403; referencia ajena, 404. Los errores de infraestructura usan un mensaje genérico sin SQL, trazas o rutas. Las respuestas de datos usan `Cache-Control: private, no-store`; no hay caché compartida de GeoJSON.

`throttle:maps.read` limita `/api/v1/map/*` a 180 peticiones por minuto por actor, según `config/maps.php`. Una respuesta 429 conserva las cabeceras de límite, incluido `Retry-After`.

## Proyecciones privadas

`MapTreeResource` publica UUID, código, nombre común, nombre científico, estado y etiqueta, emplazamiento, fecha de última inspección cuando procede y destino protegido de apertura. GeoJSON usa `[longitud, latitud]`. No incluye metadatos GPS, observaciones, autores, correos, rutas de archivo, evidencias ni atributos internos de organización.

Las relaciones necesarias se cargan por lote. El Resource utiliza relaciones ya cargadas, sin consultar por marcador. Los nombres recibidos como JSON son texto; el popup los incorpora como contenido textual, no como HTML de negocio.

La búsqueda puede encontrar individuos sin geometría: conserva `geometry: null` y permite abrir su ficha, sin crear un marcador en 0,0. Las capas excluyen registros sin punto. Un radio solo puede coincidir con árboles georreferenciados.

El mapa excluye árboles anulados administrativamente incluso para administradores. Rechaza solicitudes de `trashed`, `include_deleted` o `include_voided_trees` que intenten incluirlos. `dead`, `felled` y `removed` no equivalen a anulación y se consultan mediante filtros.

## PostGIS, extensión y densidad

El encuadre usa `ST_Intersects(geometry, ST_MakeEnvelope(..., 4326))`: incluye el borde y permite usar GiST. El radio usa `ST_DWithin(geometry::geography, centro::geography, metros)`. Los parámetros se enlazan conservando la precisión del double al convertirlos en cadenas para PostgreSQL. El navegador no aproxima metros mediante grados. Fuentes: [ST_Intersects](https://postgis.net/docs/ST_Intersects.html), [ST_DWithin](https://postgis.net/docs/ST_DWithin.html).

La extensión usa `ST_Extent` sobre el conjunto filtrado antes del encuadre. Sin puntos, puede utilizar el municipio real de la organización o del propietario del inventario; si falta esa geometría, utiliza su departamento real. Si tampoco existe, `bounds` queda en `null` y se conserva la vista general configurada. No se deducen coordenadas desde nombres ni se insertan polígonos de ejemplo. Fuente: [ST_Extent](https://postgis.net/docs/ST_Extent.html).

La campaña se filtra mediante `inventory_trees`, no por un campo `Tree.inventory_id`. La última inspección se elige antes de filtrar, por `inspection_date DESC, created_at DESC, id DESC`. ANY/ALL compara condiciones de esa misma visita. Los criterios de mantenimiento coinciden en una misma orden; los cancelados participan solo al solicitar ese estado. Véase [map-filters.md](map-filters.md).

| Parámetro actual | Valor / efecto |
| --- | --- |
| `maps.max_points` | 2.000 individuos por respuesta de puntos. |
| `maps.cluster_zoom_threshold` | Zoom menor que 10 agrega celdas en `/clusters`. |
| Exceso de puntos visibles | `/clusters` agrega si supera `max_points`, cualquiera que sea el zoom. |
| `maps.grid_cells_per_axis` | 64; el borde inclusivo permite hasta 65 × 65 celdas. |
| `maps.debounce_ms` | 300 ms para coordinar peticiones al mover el mapa. |
| Geometría territorial | Hasta 20 geometrías por clase y 20.000 vértices por geometría. |

La malla del servidor depende del zoom y se ensancha según el encuadre. Usa `COUNT`, centro `AVG` y límites reales por celda. Los clusters publican cantidad y límites. El calor publica coordenadas agregadas y `weight`: expresa **densidad de árboles**, sin inferir riesgo, diagnóstico o mortalidad. Supercluster organiza puntos ya acotados en el navegador; no decide su autorización.

Los metadatos distinguen:

- `total`: conjunto autorizado con filtros forestales y radio, antes del encuadre.
- `georeferenced` y `without_geometry`: presencia de punto dentro de ese conjunto.
- `in_view`: individuos georreferenciados dentro del encuadre.
- `returned`: elementos enviados, puntos o celdas.
- `truncated`: la respuesta de puntos alcanzó el límite sin contener todo el encuadre.
- `aggregation`: `none`, `grid` o `density_grid`.

El límite de marcadores no reduce los conteos. La agregación de densidad conserva todos los puntos coincidentes mediante sus pesos.

`territories` es una `FeatureCollection` de polígonos reales seleccionados. Su `meta` contiene `omitted_complex`, `omitted_invalid` y `truncated` para informar omisiones por complejidad, invalidez o límite. El orden es estable por nombre e ID. Los indicadores se muestran por separado del estado de capas; omitir un polígono no amplía el alcance ni elimina el filtro territorial.

El backend mantiene UTC. `date_from` y `date_to` comparan fechas de inspección `AAAA-MM-DD` inclusivas, sin convertirlas en instantes locales. El desempate conserva los timestamps almacenados; Fase 10 no cambia las reglas históricas de fechas o mediciones.

## Reutilización pública en Fase 11

`PublicMapService` recibe filtros de `PublicTreeFilterService` y consulta `MapTreeRepository` con `TreeReadScope::published()`. No recibe actor ni activa `TenantContext`. `PublicReferenceRepository` adapta las opciones a códigos públicos sobre `MapOptionRepository`; no expone UUID institucionales.

El alcance exige organización y árbol publicados, jerarquía territorial activa y participación en un inventario publicado, activo y fuera de borrador. Excluye anulados. La última inspección pública conserva el orden determinista, pero primero restringe las visitas a las publicadas y sus campañas elegibles. El mantenimiento se restringe a ejecutado/verificado dentro del mismo `EXISTS` que satisface los filtros. La búsqueda pública usa únicamente código y nombres; los textos internos de ubicación y legado permanecen fuera incluso de los predicados.

Las rutas son `GET /portal/map` y `GET /api/public/v1/map/{trees,clusters,heat,extent,search}`, con opciones en `GET /api/public/v1/options/{kind}`. La apertura de una ficha pública es un enlace GET por `organization.public_code` y `Tree.code`, sin selección institucional. El flujo POST/CSRF de apertura privada permanece vigente para `/map`.

El mapa público reutiliza caja y radio PostGIS, precisión de bindings, extensión real, malla acotada, advertencias territoriales y límites de 2.000 puntos. La tabla pública y sus indicadores comparten el universo forestal filtrado y el radio; el viewport limita solamente el dibujo. La búsqueda conserva árboles sin geometría sin colocar puntos ficticios. El nombre común público prioriza el principal y luego nombre/ID para mantener coherencia con el orden de la tabla.

`PublicTreeMapResource` devuelve código compuesto público, punto, nombres, estado, emplazamiento, fecha de última inspección publicada y URL pública. Los conteos y celdas no incluyen registros privados. No devuelve UUID, usuarios, GPS técnico, número legado, notas ni evidencias originales. Los detalles de inspecciones y fotos están en Resources separados con sus propias listas de campos.

Las páginas/API públicas usan `no-store`, límites por IP y errores genéricos. No usan sesión ni cookies JWT y no amplían las concesiones del mapa interno. Las bibliotecas y teselas de la sección siguiente se reutilizan; la política de publicación no depende de filtros del navegador. Véase [public-portal.md](public-portal.md) para las cinco banderas, identificadores, rutas, ejemplos, fotografías y pruebas.

## Bibliotecas y mapa base

Las distribuciones se sirven desde `public/vendor`; no se resuelven versiones ni se descargan scripts desde CDN al abrir el mapa. `SOURCE*.json` conserva procedencia, versiones, hashes y avisos incluidos.

| Componente incluido | Versión | Licencia documentada | Metadatos |
| --- | --- | --- | --- |
| Leaflet | 1.9.4 | BSD-2-Clause | [SOURCE.json](../public/vendor/leaflet/SOURCE.json) |
| Supercluster | 9.1.0 | ISC | [SOURCE.json](../public/vendor/supercluster/SOURCE.json) |
| kdbush, incluido en Supercluster | 4.1.0 | ISC | [SOURCE.kdbush.json](../public/vendor/supercluster/SOURCE.kdbush.json) |
| `@linkurious/leaflet-heat` | 0.2.6 | Archivo distribuido con BSD-2-Clause y Apache-2.0 | [SOURCE.json](../public/vendor/leaflet-heat/SOURCE.json) |
| simpleheat, incluido en calor | 0.4.0 | BSD-2-Clause | [SOURCE.simpleheat.json](../public/vendor/leaflet-heat/SOURCE.simpleheat.json) |

La metadata npm de `@linkurious/leaflet-heat` declara ISC, pero su `LICENSE` distribuido contiene los otros avisos indicados; se conserva íntegro y se registra la diferencia. Se describen los artefactos concretos incluidos, sin inferir una licencia a partir del nombre del paquete. Fuentes: [Leaflet](https://leafletjs.com/download.html), [Supercluster](https://github.com/mapbox/supercluster), [Linkurious/Leaflet-heat](https://github.com/Linkurious/Leaflet-heat).

La capa base usa HTTPS de OpenStreetMap, atribución visible y zoom nativo máximo 19. Las teselas usan `referrerPolicy: origin`: transmiten el origen sin ruta ni filtros de la página. Se respetan las reglas de visualización interactiva y caché del navegador; no se ofrece descarga masiva ni mapa offline. No se configura satélite sin proveedor y condiciones concretos. Fuente: [política oficial de teselas](https://operations.osmfoundation.org/policies/tiles/).

## Verificación medida durante Fase 10

La suite final registró **1251 pruebas PHP y 6277 aserciones**, sin fallos, errores ni omitidas. Las pruebas JavaScript fueron **37/37**, y PSR-12 pasó en **432 archivos**. El [reporte A–AL](phase-10.md) consolida los resultados y la [evidencia JSON](phase-10-performance.json) conserva el plan final.

El conjunto focal `MapPerformanceTest`, `MapScopeSecurityTest` y `MapEndpointSecurityTest` registró **82 pruebas y 939 aserciones, sin fallos ni errores**, antes de los últimos ajustes de navegación multirrol y avisos territoriales. Es evidencia focal, no el resultado final de la suite completa.

`MapReadService::points` con `scope=organization` registró:

| Marcadores completos | Consultas SQL |
| --- | --- |
| 1 | 73 |
| 100 | 73 |

La ventana incluye autorización del Service, filtros, conteos, selección, relaciones y proyección. Excluye preparación de fixtures y no equivale a una petición HTTP completa. El test mantiene un presupuesto inferior a 80 consultas y comprueba que no crezcan por individuo; no se relajó el límite.

El EXPLAIN sobre **4.096 puntos sintéticos** de `sysforest_testing` usó naturalmente `Index Scan` sobre `trees_geometry_gist` y devolvió una fila para `west=-73.81, south=1.19, east=-73.79, north=1.21`. `enable_seqscan`, `enable_indexscan` y `enable_bitmapscan` estaban en `on`: no se forzó GiST. La ejecución final registra **1,288 ms de planificación y 0,193 ms de ejecución**. Es un plan observado para ese fixture selectivo, no una garantía de latencia productiva.

Artefactos dentro del contenedor `app`:

- `/tmp/sysforest-phase10-query-count.json`.
- `/tmp/sysforest-phase10-explain.json`, con SQL, parámetros sintéticos y `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`.

Las pruebas de seguridad verifican A/B, departamentos ajenos, nacional sin selección, revocación, permisos independientes, respuestas mínimas, CSRF, errores sin trazas, caché privada, tasa y nombres como texto. Las pruebas espaciales y de presentación complementan la cobertura. Los fixtures se revierten en `sysforest_testing`; no se cargan individuos de ejemplo en la base operativa.

Las suites automatizadas se retiraron por solicitud del usuario. Los resultados descritos aquí son históricos; consulta [la verificación vigente](testing.md) para formato, sintaxis y revisión manual.

El [informe de Fase 10](phase-10.md) conserva las mediciones y condiciones de aquella ejecución.

Estos comandos no implican que las suites se hayan ejecutado nuevamente al redactar el documento.

## Límites conservados

La pertenencia institucional a un municipio no certifica que un punto caiga dentro de un polígono oficial. Se conservan los límites de [georreferenciación](georeferencing.md), [inspecciones](inspections.md) y [mantenimientos](maintenances.md). El mapa no añade notificaciones de mantenimiento, reglas de fenología o multirramificación, anulación de inspecciones, importaciones, exportaciones ni informes finales. Tampoco cambia la deuda documentada de fijación de dependencia JWT.

No hay geometrías territoriales inventadas ni descarga nacional de individuos. Fase 11 ya reutiliza la selección compartida para el alcance publicado. Las exportaciones y reportes futuros aún requieren alcance, proyección y aprobación propios; no se implementan como parte del portal.

## Ampliación cartográfica del 07/10/2026

La [guía vigente de cartografía y analíticas](cartography-and-analytics.md) documenta los espacios operativo y territorial, herramientas de medición y ubicación, capas GeoJSON locales y las bases adicionales OpenTopoMap y NASA Blue Marble histórico de 2004. Amplía el alcance descrito en las fases anteriores y conserva sus permisos, filtros y límites de lectura.

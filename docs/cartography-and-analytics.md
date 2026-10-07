# Cartografía, diagramas y gráficas

Ampliación del 07/10/2026. Esta guía describe las herramientas actuales de `/map` y el tablero privado `/analytics`. Complementa [mapas](maps.md), [filtros](map-filters.md) y [reportes y exportaciones](reports-and-exports.md); sus informes de fases anteriores conservan carácter histórico.

## Explorar el territorio

En `/map`, **Mapa operativo** permite localizar árboles, abrir fichas y consultar puntos o agrupaciones. **Análisis territorial** activa la densidad del mismo conjunto autorizado. Cambiar de espacio conserva los filtros; son dos formas de explorar el mismo mapa, no dos inventarios distintos.

Los límites territoriales proceden de geometrías reales disponibles en SysForest. Se pueden alternar mapas base, consultar la leyenda, ampliar a pantalla completa, ver coordenadas y escala, medir, cargar capas locales y copiar el enlace con filtros y modo de visualización. El enlace no incluye capas locales ni mediciones y exige que el destinatario tenga acceso propio.

El calor representa concentración de árboles registrados: no equivale a cobertura de copa, riesgo, enfermedad ni mortalidad. Su intensidad se normaliza al conjunto visible, por lo que el mismo color no constituye una escala absoluta para comparar encuadres. Los conteos conservan el universo completo; el dibujo de puntos está limitado a 2.000 por respuesta y las agrupaciones agregan los puntos cuando corresponde. No se crean árboles ni coordenadas para llenar el mapa.

### Herramientas locales

| Herramienta | Comportamiento y límites |
| --- | --- |
| Distancia y área | Aproximación esférica en metros/kilómetros o m²/hectáreas; hasta 500 vértices. Un área requiere al menos tres vértices sin cruces. Se puede deshacer, finalizar y limpiar. No sustituye un levantamiento de campo ni se guarda como geometría del inventario. |
| Mi ubicación | Solo se solicita al pulsar el botón y con permiso del navegador. Requiere HTTPS o un contexto local seguro. Muestra precisión aproximada y no se guarda. «Ver mi ubicación» centra el mapa y consulta el encuadre resultante. |
| Capa GeoJSON | Se lee en el navegador, sin subir el archivo ni modificar árboles o estadísticas. Hasta tres capas simultáneas; cada archivo admite como máximo 2 MiB, 3.000 elementos y 30.000 posiciones de coordenadas. |

Las capas locales deben ser `FeatureCollection` en WGS84/EPSG:4326, con coordenadas `[longitud, latitud]`, sin campo `crs`. Se admiten Point, LineString, Polygon y sus variantes Multi, con anillos cerrados; no GeometryCollection ni geometrías vacías. Se conserva únicamente la geometría 2D y una etiqueta breve derivada de `name`, `nombre`, `label`, `code` o `codigo`. HTML, estilos y URLs del archivo no se interpretan. Una capa puede ocultarse, enfocarse o retirarse; se pierde al recargar o abandonar la página.

## Mapas base y procedencia

La configuración está en `config/maps.php`. Las bibliotecas se sirven localmente; las teselas se solicitan por HTTPS al proveedor. Se mantienen atribuciones visibles y `referrerPolicy: origin`, sin enviar la ruta ni los filtros como referente. La navegación puede ampliar hasta zoom 20, reutilizando la resolución nativa disponible; ampliar una tesela no añade detalle.

| Base | URL de teselas | Máximo nativo y condiciones |
| --- | --- | --- |
| OpenStreetMap | `https://tile.openstreetmap.org/{z}/{x}/{y}.png` | Zoom 19. Atribución OpenStreetMap contributors; datos ODbL y servicio sujeto a su política de teselas. |
| Relieve · OpenTopoMap | `https://a.tile.opentopomap.org/{z}/{x}/{y}.png` | Zoom 17. Atribuir OpenStreetMap, SRTM y OpenTopoMap; representación CC-BY-SA 3.0. Servicio comunitario sin garantía de disponibilidad. |
| Satélite NASA · referencia 2004 | `https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief_Bathymetry/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg` | Zoom 8, teselas de 256 px. Mosaico Blue Marble de **agosto de 2004**, histórico y de escala regional; no muestra árboles individuales ni cambios recientes. Atribuir NASA Earth Observatory/GIBS. |

OpenStreetMap exige uso interactivo y respeto de caché/atribución; prohíbe precarga masiva y descarga offline de su servicio estándar, que tampoco ofrece SLA. OpenTopoMap permite reutilización bajo CC-BY-SA y pide no sobrecargar el servidor. SysForest no ofrece descarga de teselas ni mapas offline. Para otro proveedor o imágenes de mayor resolución deben verificarse sus condiciones antes de configurarlo. Fuentes: [política de OSM](https://operations.osmfoundation.org/policies/tiles/), [licencia de OSM](https://www.openstreetmap.org/copyright), [condiciones de OpenTopoMap](https://opentopomap.org/about).

La fecha y carácter no continuo de Blue Marble constan en sus [metadatos oficiales](https://gibs.earthdata.nasa.gov/layer-metadata/v1.0/BlueMarble_ShadedRelief_Bathymetry.json); su matriz EPSG:3857, JPEG y niveles 0–8 figuran en [GetCapabilities](https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/1.0.0/WMTSCapabilities.xml). Se utiliza con reconocimiento de NASA conforme a su [guía de uso y atribución](https://www.earthdata.nasa.gov/engage/open-data-services-software/data-use-policy), sin implicar respaldo institucional. Véanse también la [descripción del mosaico](https://science.nasa.gov/earth/earth-observatory/blue-marble-next-generation/topography-bathymetry-maps/) y el [acceso a GIBS](https://nasa-gibs.github.io/gibs-api-docs/access-basics/).

## Diagramas y gráficas

El tablero presenta individuos únicos, ubicación disponible, especies identificadas y ausencia de ubicación. El diagrama de cobertura separa ubicación e inspección en ramas independientes: sus cantidades no se suman ni representan etapas sucesivas.

Las secciones reúnen composición por estado/especie/familia, clases de altura y diámetro, condiciones físicas y fitosanitarias, inspecciones, mantenimientos, comparación de inventarios y registros históricos por mes. En alcances amplios también aparecen organización y municipio. Las clases conservan la versión de regla registrada; los estados usan el estado actual del árbol y los mismos colores del mapa.

Cada gráfica conserva una tabla con todos sus grupos autorizados, presentada en páginas de 100 filas mediante JavaScript. La navegación por tablas no exige permiso de exportación. Sin JavaScript se muestra la primera página. Los conjuntos vacíos se identifican sin inventar categorías o valores.

- Especies y familias dibujan los diez grupos principales y reúnen el resto en «Otros grupos»; la tabla conserva el detalle.
- Las demás barras dibujan hasta doce grupos. Condiciones e inventarios pueden superponerse: un árbol puede pertenecer a varios grupos, por lo que sus barras no se suman como individuos únicos.
- Mantenimientos cuenta órdenes por tipo y estado; la tabla incluye también individuos distintos. Conserva exclusiones y filtros del reporte correspondiente.
- La serie histórica dibuja hasta los últimos 36 **meses con registros**. No completa meses ausentes con ceros ni certifica actividad de campo o crecimiento biológico. La tabla conserva los meses anteriores.

La fecha de inspección puede ser una referencia importada. En Restrepo, **30/11/2025 es la fecha de entrega autorizada**, no un día de visita conocido. Los filtros forestales seleccionan árboles usando su última inspección determinista; la serie consulta la historia de esos árboles y aplica además sus límites de fecha e inventario a los registros históricos. Filtrar una campaña en las otras gráficas selecciona participaciones del árbol y no sustituye su última inspección por la última de esa campaña.

## Acceso, filtros y rutas

| Método y ruta | Uso |
| --- | --- |
| `GET /analytics` (`analytics.index`) | Vista Blade del tablero. |
| `GET /api/v1/analytics` (`analytics.data`) | JSON con resumen, gráficas, filas, columnas, filtros, capacidades y avisos de la misma selección. |

Estas rutas pertenecen a la aplicación web privada: usan sesión y JWT en cookies, cuenta verificada y contexto institucional compartido. **No son endpoints de la API móvil Bearer**; ese contrato permanece en [mobile-api.md](mobile-api.md). La representación pública mantiene sus propios servicios y reglas de publicación.

`AnalyticsController → AnalyticsReadService → AnalyticsRepository/ReportRepository` reutiliza `ReportFilterService`, `ReportAccessService` y el motor común. `scope=organization|department|national` solicita un alcance exacto y siempre se reautoriza; no concede permisos ni amplía el tenant de escritura. Se requieren los permisos base de mapas/árboles y `reports.view`. Cada gráfica comprueba además su dominio: `inspections.view`, `maintenances.view` o `inventories.view`, según corresponda. Los filtros de inspección/mantenimiento exigen esos permisos aunque se consulte otra dimensión. Las gráficas no autorizadas se omiten.

El tablero acepta los filtros documentados en [map-filters.md](map-filters.md). El encuadre, zoom y modo visual no reducen sus resultados; un radio explícito sí. Se excluyen árboles anulados. La lectura normal se realiza dentro de una instantánea PostgreSQL `REPEATABLE READ READ ONLY` compartida para evitar mezclar momentos entre gráficos; no persiste indicadores.

El JSON es `private, no-store`. Ambas rutas usan `throttle:maps.read` (180 consultas/minuto por actor con la configuración actual). Sin autenticación, la API devuelve 401 y la página sigue la navegación de inicio/renovación de sesión. Se conservan 403 por falta de autorización, 404 por referencia fuera del alcance, 422 por filtros o límites, 429 por frecuencia y mensajes genéricos ante fallos de infraestructura. Un agregado que supere `reports.max_aggregate_rows` (10.000 grupos por defecto) se rechaza; no se entrega un recorte como total.

## Descargas y bibliotecas

Los controles de descarga requieren `reports.export` además del permiso del contenido. CSV y PNG se generan en JavaScript a partir de la consulta ya recibida: incluyen alcance, fecha, filtros y avisos. CSV entrega todos los grupos autorizados, con BOM UTF-8, separador `;`, comillas escapadas y protección de prefijos de fórmula. PNG contiene la gráfica representada y sus etiquetas/valores, no todas las filas de la tabla. Estos archivos locales no generan una nueva consulta ni un evento servidor de preparación de exportación.

Excel utiliza los endpoints existentes de [reportes](reports-and-exports.md), que reautorizan al descargar y conservan sus límites y manejo de temporales. Está disponible en las gráficas respaldadas por ese catálogo; comparación de inventarios y serie mensual ofrecen CSV/PNG, sin un nuevo endpoint Excel. La lectura de tablas permanece disponible sin exportación. Al volver desde una página conservada por el navegador se recarga el tablero para reconstruir gráficas y autorización.

Se conserva Leaflet, Supercluster y Leaflet.heat ya fijados. Las gráficas incorporan **Chart.js 4.5.1, MIT**, distribución UMD local con global `Chart`: [procedencia y hashes](../public/vendor/chartjs/SOURCE.json), [licencia](../public/vendor/chartjs/LICENSE.md) y [documentación oficial](https://www.chartjs.org/docs/4.5.1/). No se resuelven versiones desde CDN durante el uso.

## Evidencia y límites de esta nota

El 07/10/2026 se consultaron las fuentes oficiales anteriores; las muestras de OpenTopoMap y NASA devolvieron HTTP 200 con tipo de imagen mediante consultas de cabeceras, sin guardar imágenes. El paquete Chart.js se comprobó contra la integridad SHA-512 publicada por npm, se registraron los SHA-256 de sus archivos y `node --check` terminó sin errores. Estas comprobaciones no garantizan disponibilidad futura de los proveedores ni reemplazan la revisión visual y funcional de la aplicación.

Esta guía no atribuye pruebas automatizadas ni resultados de fases anteriores a la ampliación. Se mantiene la decisión de no recrear suites; la validación final de formato, sintaxis y navegación se registra por separado cuando se complete. No se ha publicado un despliegue externo ni habilitado cartografía offline.


### Cierre de verificación local — 7 de octubre de 2026

- `bash bin/check`: formato PSR-12 (532 archivos), sintaxis JavaScript (27) y PHP (533), salida 0. El último ajuste del módulo analítico también pasó `node --check`.
- 33 comprobaciones manuales del backend con rollback: alcances nacional/departamental/organización, filtros, totales, conjuntos vacíos y revocación de permisos. 28 comprobaciones de geometría y límites GeoJSON, y 8 de generación/escape CSV. Scripts temporales fuera del repositorio; no se agregaron suites.
- Navegador: gráficos, categorías, tabla de especies de 143 filas con paginación, filtros sin resultados, regreso desde reportes, mapa operativo/calor, OSM/OpenTopoMap, aviso NASA, medición por teclado, pantalla completa y carga/encuadre/retiro de una capa local. Archivo PNG descargado y abierto. Portal público sin errores de JavaScript.
- Revisión visual a anchos de escritorio y 390 px, sin desbordamiento horizontal. No equivale a una prueba en un teléfono físico.
- `view:cache`, `git diff --check` y `/health/ready` correctos. La geolocalización depende del permiso y la señal del dispositivo; no se concedió permiso de ubicación durante la revisión. No hubo cambios en árboles ni migraciones.

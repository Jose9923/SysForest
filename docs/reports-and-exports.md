# Reportes y exportaciones — Fase 12

SYSFOREST presenta reportes en Blade y descarga el mismo conjunto filtrado en CSV, Excel, PDF o GeoJSON según el reporte. Las descargas son síncronas, completas y acotadas: no hay `ExportRun`, trabajos de exportación ni archivos públicos permanentes. Este documento describe la implementación; los resultados finales de verificación se registran al cierre de la fase.

## Consulta y alcance

La vista interna está en `/reports`; la pública en `/portal/reports`. La interna exige cuenta activa/verificada, sesión vigente y `reports.view`. Descargar exige además `reports.export`. Se verifican también los permisos del contenido: `trees.view`; `inspections.view` para medidas, clases, condiciones y fauna; `maintenances.view` para órdenes; el inventario/ficha completos exigen `evidences.view` por sus enlaces CN/CO. Usar filtros de inspección o mantenimiento añade el permiso correspondiente incluso en un reporte de otra dimensión.

| Perfil | Selección autorizada |
| --- | --- |
| SUPER_ADMIN | Nacional, o la organización seleccionada; sin membresías artificiales. |
| DEPARTMENT_ADMIN | Departamentos asignados, o una organización de ese ámbito mediante contexto de lectura departamental. |
| ORGANIZATION_ADMIN y OFFICIAL | Organización activa seleccionada y membresía/rol vigentes. |
| Público anónimo | Alcance `published`; sin resolver JWT, usuarios ni `TenantContext`. |

Los roles incorporan concesiones explícitas para consultar y exportar; estas no amplían el alcance territorial ni habilitan escritura. Una descarga privada reautoriza el alcance exacto antes de entregar el archivo. La descarga pública revalida las fuentes publicadas del inventario; los agregados vuelven a calcularse y se comparan sus filas y su resumen con la instantánea, incluso si no hay grupos. Una revocación detectada cancela la entrega.

La proyección pública mantiene la política de Fase 11: organización publicada y activa, jerarquía territorial activa, árbol publicado/no anulado y participación en al menos una campaña pública activa fuera de Borrador. Las inspecciones requieren publicación propia y campaña publicable cuando tienen una asignada. Las fotografías requieren además su propia publicación. Solo se consideran mantenimientos ejecutados/verificados en el ámbito público. No se publican funcionarios, UUID internos, auditoría, textos privados de observación/desarrollo, legado, GPS técnico ni originales de imágenes.

## Reportes disponibles

El parámetro `report` utiliza las siguientes claves. `inventory` es el valor predeterminado; la ficha individual se obtiene por su ruta específica.

| Clave | Contenido |
| --- | --- |
| `inventory` | Una fila por árbol y su última inspección pertinente. |
| `species`, `families` | Individuos por especie y familia; incluye ausencia de identificación. |
| `origins` | Origen de establecimiento y origen biogeográfico como dimensiones independientes. |
| `habits`, `states` | Individuos por hábito y estado actual. |
| `diameter_classes`, `height_classes` | Clases persistidas, separadas por versión de la regla. |
| `physical_conditions`, `phytosanitary_conditions` | Condiciones de la última inspección, con órgano/categoría. |
| `maintenances` | Órdenes no anuladas de los árboles filtrados, agrupadas por tipo/estado; público solo executed/verified. |
| `municipalities`, `organizations` | Individuos por municipio u organización. |
| `inspections` | Individuos con o sin última inspección; no cuenta todas las visitas históricas. |
| `fauna` | Categorías observadas en la última inspección. |
| `protected` | Protegido y patrimonial como dos dimensiones independientes. |
| `zones` | No disponible: HTTP 422. No existe definición ni catálogo institucional de zonas. |

Las selecciones múltiples de condiciones o fauna pueden sumar más que el total de árboles. El reporte lo indica y cuenta individuos distintos dentro de cada grupo. El reporte de mantenimiento distingue número de órdenes y número de individuos. Si se filtran tipos, estados o prioridades de mantenimiento, la agregación conserva esos criterios sobre la misma orden; no suma órdenes ajenas al criterio del usuario.

`zones` no se sustituye por municipio, emplazamiento ni texto libre de localización. Es el requisito del §30 que continúa pendiente de definición de dominio. Los dashboards del §31 no forman parte de esta implementación de reportes. La plantilla/importación oficial corresponde a Fase 13 y no se implementa aquí.

## Filtros y consistencia

`ReportFilterService` produce `ReportSelectionDTO` y compone el motor existente `TreeQueryRepository` → `MapTreeRepository`. Los filtros se documentan en [map-filters.md](map-filters.md). No se introduce una segunda interpretación de taxonomía, territorio, orígenes, condiciones ANY/ALL, inventario o mantenimiento.

Se conservan filtros de búsqueda, código, nombres, familia/especie, emplazamiento, hábito, ambos orígenes, estado, protección/patrimonio, territorio/organización/campaña, fecha/clases/madurez/medidas y condiciones, tipo/estado/prioridad de mantenimiento y radio. Público utiliza los mismos códigos compartibles del portal para las referencias; privado utiliza referencias internas validadas dentro del alcance. `false`, `0` y `"0"` siguen siendo valores expresos. `has_pending_maintenance=false` significa que la casilla no añade un filtro; no significa «sin pendientes».

La última visita se ordena por `inspection_date DESC, created_at DESC, id DESC` en el LATERAL compartido. En público, la publicación delimita las visitas candidatas **antes** de escoger la última. Los filtros de medidas, fechas y ANY/ALL se aplican después sobre esa única visita. Filtrar una campaña exige participación del árbol mediante `inventory_trees`; no cambia la visita seleccionada por la última de esa campaña.

El radio explícito sí limita la selección. `bbox`, los bordes del viewport, zoom, modo de dibujo y página no recortan el archivo descargado. Tampoco se aplica el máximo de marcadores del mapa. `tree_id`, `trashed`, `include_deleted` e `include_voided_trees` no son filtros de estos reportes. Los filtros desconocidos se rechazan; una ficha tampoco permite eludir el conjunto autorizado mediante su identificador.

El orden es el compartido con las tablas: código por defecto, o una opción admitida como nombre común/científico, municipio o fecha de última inspección; se conserva dirección y desempate estable. El inventario se lee en lotes de 200. La vista previa obtiene directamente su página SQL de 25 registros, sin materializar la descarga completa. Conteo y generación se consumen dentro de una transacción de lectura `REPEATABLE READ` en ejecución normal, evitando mezclar lotes de momentos distintos.

## Rutas y formatos

| Método/ruta | Nombre de ruta | Función |
| --- | --- | --- |
| `GET /reports` | `reports.index` | Vista interna Blade. |
| `GET /reports/download/{format}` | `reports.download` | Descarga interna filtrada. |
| `GET /reports/trees/{tree}/pdf` | `reports.trees.pdf` | Ficha interna por UUID, dentro del alcance. |
| `GET /portal/reports` | `public.reports.index` | Vista pública Blade. |
| `GET /portal/reports/download/{format}` | `public.reports.download` | Descarga anónima publicada. |
| `GET /portal/organizations/{organization}/trees/{tree}/pdf` | `public.trees.pdf` | Ficha por código público de organización y código de árbol. |

`format` pertenece a `csv`, `xlsx`, `pdf` o `geojson` en el segmento de ruta. GeoJSON se admite únicamente para `inventory`; un formato incompatible devuelve 422. Las fichas aceptan el perfil de inventario y conservan los filtros activos. Un individuo ausente, no publicado o fuera de la selección devuelve 404. Los errores de validación pueden consultarse como JSON usando `Accept: application/json`; en la navegación web se presentan según el manejo de errores de la aplicación.

Ejemplos en la terminal Ubuntu, sin JWT ni credenciales:

```bash
curl --fail --show-error --get \
  --data-urlencode 'report=inventory' \
  --data-urlencode 'current_status=active' \
  --output inventario-publico.csv \
  'http://localhost:8080/portal/reports/download/csv'

curl --fail --show-error --get \
  --data-urlencode 'report=inventory' \
  --data-urlencode 'is_protected=1' \
  --output protegidos.geojson \
  'http://localhost:8080/portal/reports/download/geojson'

curl --fail --show-error --get \
  --data-urlencode 'report=species' \
  --output especies.xlsx \
  'http://localhost:8080/portal/reports/download/xlsx'

curl --show-error -H 'Accept: application/json' \
  'http://localhost:8080/portal/reports/download/csv?report=zones'
```

La existencia de un endpoint no garantiza datos publicados: el resultado puede estar vacío y no se crean individuos ficticios para poblarlo.

### Excel y CSV

El inventario privado tiene 126 columnas que conservan las 94 variables conceptuales del Excel original; la proyección pública contiene 111 columnas permitidas. Las correspondencias y diferencias se detallan en [exports-field-mapping.md](exports-field-mapping.md). `current_status` conserva el código estable y `current_status_label` muestra su etiqueta en español, utilizada también en la tabla y el PDF compacto. Las 65 condiciones originales son distintas por código/órgano, con `x` cuando fueron seleccionadas y celda vacía en ausencia. Las condiciones complementarias se agregan por tipo sin perder código, órgano ni nombre.

OpenSpout genera tres hojas: `Datos`, `Diccionario` y `Criterios`. Las celdas son `StringCell` explícitas: códigos, decimales y BIGINT no se convierten a números de 15 dígitos ni a fórmulas. El diccionario incorpora claves, unidades, tipos y grupos; los criterios muestran alcance, fecha, filtros legibles y filas. Una celda que excede 32.767 unidades UTF-16 rechaza todo el XLSX con 422 y sugiere CSV; nunca se recorta.

CSV usa UTF-8 con BOM, separador `;` y finales CRLF; las comillas preservan saltos de línea dentro de las celdas. La protección de fórmulas reconoce signos peligrosos incluso tras espacios/caracteres de control o normalización Unicode y antepone una comilla simple. Los números decimales estrictamente válidos conservan su texto. Al abrir CSV en un editor de hojas, debe elegirse texto para columnas de códigos/precisión: CSV no lleva tipos y la inferencia del programa lector queda fuera del archivo.

Las medidas se exportan con la escala persistida —seis decimales de entradas, doce de derivados— sin recalcular fórmulas ni usar float. Ausencia, cero y falso no se intercambian. CN/CO incluyen enlaces a las fotografías de la inspección elegida, una URL por línea. No mezclan fotografías permanentes de Tree ni las de mantenimiento. Los enlaces públicos solo apuntan al medio derivado controlado; los privados vuelven a comprobar permisos al abrirse.

### PDF

El PDF general de inventario es una tabla compacta con código, nombre común/científico, organización, municipio, emplazamiento, estado y fecha de inspección. La ficha individual contiene todos los campos permitidos para su perfil, agrupados y con unidades; ambos utilizan la última inspección pertinente. La ficha no reemplaza el historial completo de visitas ni incrusta los archivos fotográficos privados.

Los PDF proceden de plantillas Blade propias e incluyen identidad SYSFOREST, fecha, alcance, filtros y paginación. Dompdf tiene recursos remotos, JavaScript y PHP deshabilitados; el logo se incorpora desde un archivo local y los textos se escapan en Blade. El PDF agregado/tabular se limita a 200 filas por defecto; para conjuntos mayores se elige CSV/Excel o se estrechan los filtros.

### GeoJSON

GeoJSON entrega una `FeatureCollection`, una Feature por individuo y Point con coordenadas `[longitud, latitud]` en SRID 4326. Un individuo sin geometría conserva `geometry: null`; no se inventa un punto a partir del texto de coordenadas. Los filtros radiales, por su definición espacial, excluyen esos individuos.

Las propiedades se construyen únicamente a partir de las columnas permitidas. La identidad de Feature utiliza códigos de organización/árbol. `_source`, UUID, DTO, pivotes y modelos crudos nunca se serializan. La geometría técnica solo ocupa `geometry`. Los metadatos usan una lista explícita: título, fecha, indicador público, alcance y filtros legibles, junto con resumen y avisos; no incluyen configuración de la aplicación. Los NUMERIC permanecen como cadenas exactas y las coordenadas preservan los doubles PostGIS.

## Límites y configuración

Los seis valores de `.env.example` se resuelven en `config/reports.php`:

| Variable | Predeterminado | Uso |
| --- | ---: | --- |
| `REPORTS_PUBLIC_MAX_ROWS` | 10000 | Filas máximas por descarga pública. |
| `REPORTS_PRIVATE_MAX_ROWS` | 50000 | Filas máximas por descarga privada. |
| `REPORTS_PDF_MAX_ROWS` | 200 | Máximo PDF tabular/agregado, además del límite del perfil. |
| `REPORTS_AGGREGATE_MAX_ROWS` | 10000 | Máximo de grupos materializados por un agregado. |
| `REPORTS_PUBLIC_RATE_LIMIT` | 6 | Descargas por minuto e IP pública. |
| `REPORTS_PRIVATE_RATE_LIMIT` | 12 | Descargas por minuto y usuario autenticado. |

Los límites rechazan el conjunto completo con 422; no se entrega una muestra como si fuera el resultado total. La frecuencia excedida devuelve 429. Aumentar límites requiere revisar consumo de CPU, memoria y tiempo de generación; no habilita ejecución en segundo plano. El tamaño de página (25), lote (200) y antigüedad de limpieza (3600 segundos) se definen en la configuración, sin otras variables `REPORTS_*`.

## Temporales, descarga y operación

Los archivos se preparan bajo `storage/app/private/report-downloads`, en rutas UUID propias con extensión `.part`, directorio privado y modo 0600 para el archivo. Se entregan después de completarse y revalidarse con `Cache-Control: private, no-store`, `nosniff`, `Referrer-Policy: no-referrer` y `X-Export-Row-Count`. Excel utiliza además un espacio `UUID.part.work` privado para los XML temporales de OpenSpout, que se elimina al cerrar. La respuesta elimina el temporal tras enviarlo; un error durante la preparación lo elimina antes de devolver un mensaje genérico. No hay enlaces directos al almacenamiento.

El scheduler ejecuta la recuperación de temporales huérfanos cada hora. Solo considera archivos `.part` y espacios `.part.work` UUID propios de más de una hora, incluidos los espacios huérfanos sin archivo final. Valida rutas canónicas y no sigue enlaces simbólicos ni recorre el almacenamiento de evidencias. Puede invocarse manualmente:

```bash
cd /home/josev/SYSFOREST
docker compose exec app php artisan sysforest:cleanup-report-downloads
docker compose exec app php artisan schedule:list
```

Al integrar las dependencias fijadas en `composer.lock` (OpenSpout 5.12.0 y Dompdf 3.1.6), reconstruye los contenedores y sincroniza los permisos mediante el seeder idempotente:

```bash
docker compose build
docker compose up -d
docker compose exec app php artisan db:seed --class=AuthorizationSeeder
```

No se añade una migración de tablas de exportación. La auditoría privada registra `reports.export_prepared` con reporte, formato y número de filas; no guarda datos personales ni un manifiesto de filas. El evento acredita preparación, no confirma que el cliente haya terminado de recibir el archivo.

## Verificación y límites de la fase

Las pruebas de proyección verifican las 94 fuentes/65 condiciones, precisión, ceros/nulos, fotografías y campos públicos. Las de selección verifican filtros compartidos, ANY/ALL, latest, campañas, aislamiento, radio, orden, paginación y ausencia del límite cartográfico de 2.000 puntos. Los tests de writers y HTTP completan formatos, seguridad, errores y limpieza; los resultados consolidados están en [el informe de Fase 12](phase-12.md).

Para ejecutar la parte del lector de forma serial, exclusivamente en la base de pruebas configurada:

```bash
docker compose exec app php artisan test tests/Feature/ExportProjectionTest.php tests/Feature/ExportQueryTest.php
```

No ejecutar suites concurrentes contra `sysforest_testing`. Esta fase no implementa importación, una definición de zonas, dashboards nuevos ni distribución de exportaciones mediante correo o cola.

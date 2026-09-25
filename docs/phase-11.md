# Fase 11 — Portal público SIGAU

Entrega del 21 de septiembre de 2026, verificada en Ubuntu-24.04 WSL, `/home/josev/SYSFOREST`. Implementación terminada para revisión del usuario. Rama `codex/phase-11`; sin commit final de Fase 11. Fase 12 no iniciada.

## A. Arquitectura

Se mantiene Laravel 13, PHP 8.4 y `Controller → Service → Repository → Model`. Blade presenta los datos ya preparados; no ejecuta consultas. JavaScript coordina interfaz y cartografía, sin reglas forestales. `PublicPortalService`, `PublicTreeService`, `PublicMapService` y `PublicEvidenceDeliveryService` componen servicios de lectura y Resources explícitos. `TreeReadScope::published()` restringe los Repositories compartidos de Fase 10. PostgreSQL/PostGIS sigue siendo la fuente geográfica.

La API pública no depende de sesión, JWT, membresías ni `TenantContext`. La administración de publicación conserva autenticación, CSRF, autorización e institución seleccionada. El layout público es independiente del administrativo.

## B. Rutas públicas Blade

| GET | Función |
| --- | --- |
| `/portal` | Portada ciudadana e indicadores reales. |
| `/portal/map` | Mapa con filtros. |
| `/portal/trees` | Listado paginado. |
| `/portal/organizations/{organization}/trees/{tree}` | Ficha por código público institucional y código del árbol. |
| `/portal/media/{evidence}` | Fotografía autorizada mediante ULID público. |

No hay rutas públicas de escritura. Las rutas administrativas de publicación comienzan en `/publication` y continúan privadas.

## C. API pública

Prefijo exclusivo `/api/public/v1`:

- `GET /trees`: listado y paginación.
- `GET /indicators`: indicadores del universo filtrado.
- `GET /organizations/{organization}/trees/{tree}`: ficha e historiales paginados.
- `GET /map/trees`, `/map/clusters`, `/map/heat`, `/map/extent`, `/map/search`.
- `GET /options/{kind}`: opciones Select2 paginadas, con códigos públicos.

Los endpoints privados `/api/v1/...` conservan sus protecciones. [Contrato y ejemplos curl](public-portal.md).

## D. Public Resources

`PublicTreeMapResource`, `PublicTreeSummaryResource`, `PublicTreeDetailResource`, `PublicInspectionResource`, `PublicEvidenceResource` y `PublicMaintenanceSummaryResource` declaran sus campos expresamente. Los agregados espaciales reutilizan `MapCellResource`, que solo contiene geometría y conteos. No se serializan modelos completos ni Resources privados. Las relaciones necesarias llegan precargadas.

## E. Política de publicación

Se agregan `is_public=false` y `publication_version=1` a Organization, Inventory, Tree, TreeInspection y Evidence. Todo registro existente o nuevo permanece privado hasta una decisión explícita. La autorización de un hijo no publica a sus padres ni a sus hermanos. Cada petición comprueba toda la cadena vigente, también las imágenes.

La [matriz de datos](public-data-policy.md) distingue PUBLIC, PRIVATE y CONDICIONAL por dominio. Los mantenimientos no reciben una bandera adicional: solo se publica el resumen de órdenes ejecutadas o verificadas de árboles publicables.

## F. Campos públicos

Códigos públicos, nombres comunes y científicos, familia, hábito, orígenes catalogados, emplazamiento, estado forestal, protección/patrimonio, entidad institucional, territorio y geometría del árbol. Las inspecciones autorizadas aportan fecha, mediciones almacenadas con sus unidades y precisión, clases, madurez, condiciones catalogadas, categorías de fauna y fechas de floración/fructificación disponibles. Las imágenes aportan URL controlada, tipo, pie redactado expresamente para publicación y fecha permitida. No se recalculan fórmulas ni se inventan datos ausentes.

## G. Campos privados

Quedan fuera usuarios, correos, teléfonos, responsables, `user_id`, autores de cambios/carga, asignaciones, auditoría, IP, sesiones, tokens, UUID internos, versiones de bloqueo, rutas/disco, nombre original, checksum privado, EXIF/XMP/IPTC, `coordinate_raw`, precisión y dispositivo GPS. También quedan fuera número legado, descripción libre de ubicación, `general_observations`, notas administrativas, resultados libres de mantenimiento y notas libres de fauna/fenología. El pie privado de una evidencia nunca se copia al público.

## H. Publicación de Organization / Inventory / Tree

- Organization: publicada, activa, no anulada y con jerarquía territorial activa. Su primera publicación reserva `public_code`, único e inmutable aunque después cambie el código administrativo.
- Inventory: publicado, activo y distinto de borrador. Cerrados/archivados pueden conservar historia si continúan disponibles y autorizados.
- Tree: publicado, no anulado y participante en al menos una campaña pública elegible de su propia organización. Los estados muerto/talado/trasplantado/retirado/reemplazado no equivalen a SoftDelete.
- TreeInspection: autorización propia; si declara campaña, esa campaña concreta también debe ser pública.

Ocultar una campaña no oculta necesariamente un árbol que participa en otra campaña pública; sí retira las inspecciones vinculadas a la campaña ocultada. La administración mínima ofrece selección del dominio, búsqueda, estado de publicación y modificación explícita. `publication.manage` eleva el catálogo de permisos a 44: OrganizationAdmin solo en su entidad y SuperAdmin dentro del scope institucional seleccionado. Official y DepartmentAdmin no publican. Se registra actor, antes/después, fecha y versión dentro de la misma transacción; los cambios concurrentes devuelven 409. El CRUD ordinario rechaza campos de publicación.

## I. Inicio público

Identidad SYSFOREST, navegación Inicio/Mapa/Inventario/Acerca e Iniciar sesión (acceso institucional), presentación del inventario urbano e indicadores calculados desde la base. Estados vacíos útiles sin cifras ficticias. El piloto territorial surge de los datos, sin una cadena INEM codificada en Blade ni layouts particulares por entidad.

## J. Mapa público

Leaflet, Select2, motor extraído `map/engine.js` y componentes compartidos de filtros, capas, estado y popups. La entrada pública inyecta transporte anónimo, rutas públicas y contenido seguro. Bbox, zoom, radio, cancelación de peticiones, búsqueda, extensión y leyendas se conservan. Los popups solo enlazan la ficha pública.

## K. Puntos

Máximo 2.000 por respuesta. Se diferencian total filtrado, georreferenciados, sin geometría, dentro del encuadre y devueltos. El truncamiento se anuncia y sugiere acercar o usar agrupaciones. Un árbol sin geometría aparece en tabla e indicadores, nunca como un punto inventado en 0,0.

## L. Clusters

Se reutiliza la agregación espacial del servidor y la agrupación cliente donde corresponde. Solo cuentan árboles publicados; ni el total ni el extent revelan individuos privados. No se descarga el inventario nacional completo.

## M. Heatmap

Representa densidad/concentración de árboles publicados que cumplen los filtros. Leyenda textual y mensaje explícito: no representa riesgo ni condición sanitaria. Usa agregación acotada; conserva el conteo real aunque el dibujo sea una malla.

## N. Tabla

Paginación backend de 20 individuos. Código/nombre común, especie/familia, territorio/organización, emplazamiento, estado y enlace a ficha. Orden por código, nombre común, especie, municipio o última inspección publicada, ascendente/descendente. Los filtros persisten en enlaces y páginas. En móvil se presenta como tarjetas legibles.

## O. Indicadores

Árboles, con/sin geometría, especies, familias, municipios, organizaciones y distribución por estado, calculados sobre el mismo filtro. El bbox restringe el dibujo; la tabla y los indicadores describen el universo forestal filtrado. Un radio explícito sí restringe ese universo. Varias campañas no duplican un árbol.

## P. Filtros territoriales

Departamento, municipio, organización e inventario con dependencias y opciones públicas. Los parámetros conservan nombres comunes como `organization_id`, pero sus valores son códigos públicos, nunca UUID. Territorios usan códigos compuestos; inventario usa código institucional público + código de campaña. Se rechazan referencias privadas o incompatibles.

## Q. Filtros taxonómicos y permanentes

Nombre común, científico, especie, familia, hábito, origen de establecimiento/biogeográfico, emplazamiento, estado actual, protegido y patrimonial. Búsqueda literal por código/nombres; no consulta textos privados que pudieran inferirse mediante conteos. Los valores falsos y `0` se conservan en los filtros y URL.

## R. Filtros de inspección

Fecha, madurez, clases diamétrica/de altura, rangos CAP/DAP/altura y condiciones físico-mecánicas/fitosanitarias. Se elige primero la última visita elegible según `inspection_date DESC, created_at DESC, id DESC`; luego se aplican los filtros. Se conserva la definición determinista aprobada, restringida al universo publicado. La interfaz dice «Última inspección publicada» y no revela una visita privada posterior. ANY/ALL no mezcla visitas históricas; ALL exige todas las condiciones en esa misma última inspección.

## S. Filtros de mantenimiento

Tipo y estado ejecutado/verificado. Tipo, estado, elegibilidad y ausencia de anulación se comprueban en el mismo `EXISTS`; no se combinan accidentalmente órdenes distintas. No hay filtros públicos de prioridad ni pendientes, asignaciones o programación futura.

## T. Ficha pública

Identidad, entidad/territorio, mapa puntual, coordenadas, datos forestales publicados, historia y fotografías. URL inequívoca por organización pública + código del árbol. No cambia la PK. Los valores y textos ausentes se presentan como no informados; no se generan observaciones artificiales.

## U. Historial público

Inspecciones publicadas y resúmenes de mantenimientos ejecutados/verificados, sin autores ni notas internas. Páginas independientes: 15 inspecciones, 15 mantenimientos y 12 evidencias. No carga galerías ni historias ilimitadas; la ficha utiliza relaciones precargadas y Resources explícitos.

## V. Evidencias públicas

Entrega por ULID independiente del UUID interno. La elegibilidad incluye al propietario árbol/inspección/mantenimiento y toda su cadena. El servicio lee el original desde almacenamiento privado, verifica límites, decodifica píxeles, corrige las ocho orientaciones JPEG y genera WebP sin metadatos. El original permanece intacto. Docker incluye GD y EXIF.

Caché interna de derivados con namespace propio y duración de una hora; siempre se comprueba publicación antes de usar la caché y antes de un 304. ETag de los bytes derivados, nombre genérico y `Cache-Control: public, max-age=0, must-revalidate`. No hay enlace público al almacenamiento. Las copias ya descargadas por un ciudadano no pueden retirarse retroactivamente.

## W. Seguridad y privacidad

Lista cerrada de filtros, consultas parametrizadas, proyección explícita, Blade escapado y popup seguro. Límites configurables por IP: 90 páginas, 180 API y 240 imágenes/minuto. HTML/API `no-store`, `nosniff`, rechazo de iframe, permisos de cámara/micrófono/geolocalización denegados. Sin CORS global nuevo. Errores públicos genéricos, incluso con debug local: no incluyen SQL, trazas ni rutas internas.

Once rutas públicas reales respondieron 200 anónimamente y sin `Set-Cookie`; una ficha inexistente respondió 404 sin trazas. `/map` y `/publication` redirigieron al login; API cartográfica privada respondió 401. Pruebas automatizadas cubren además catálogos, mantenimiento y fichas públicas autorizadas. La base operativa tiene cero árboles/inspecciones/evidencias/mantenimientos y cero banderas publicadas: los fixtures viven únicamente en `sysforest_testing`.

## X. IDOR

Código de árbol privado, UUID conocido, entidad ajena, imagen privada o cadena revocada no permiten obtener información: 404 público. Los filtros no admiten escapes como `scope`, `tree_id`, `trashed` o `include_deleted`. Los cambios administrativos ajenos al tenant se rechazan. Una campaña privada no se hace visible por compartir un individuo con una pública.

## Y. Funcionarios

Pruebas con nombre y correo identificables asociados a Tree/Inspection/Evidence/Maintenance verifican ausencia de sus valores y claves en JSON y HTML públicos. La ficha presenta la entidad institucional, sin «creado por», responsable operativo, correo ni auditoría. El permiso de publicación y su historial permanecen privados.

## Z. Responsive

Revisión de navegador en escritorio 1440 px, tableta 768 px y móvil 390 px. Portada, mapa, filtros, tabla, ficha e imágenes comprobados sin desbordamiento horizontal del documento. En móvil se verificaron panel colapsable, agrupaciones/calor, contadores, leyenda y tarjetas. Páginas vacías verificadas en el servidor real; estados poblados revisados con capturas HTML/API generadas por tests, servidas temporalmente en localhost sin sembrar datos operativos. La vista temporal se cerró y el tamaño del navegador se restableció.

## AA. Accesibilidad

Enlace para saltar al contenido, jerarquía de títulos, labels, navegación por teclado, indicadores de foco, leyendas textuales, estados con texto, alt de fotos y carga diferida con alternativa si falla la imagen. Controles cartográficos Acercar/Alejar y cierre del popup en español. SEO básico con título, descripción y canonical cuando corresponde. Revisión funcional y visual local; no constituye una certificación WCAG completa.

## AB. Motor de filtros reutilizado

`PublicTreeFilterService::validate()` restringe y resuelve códigos, luego compone `PublicTreeFilterDTO` con `TreeFilterDTO`, `MapTreeFilterDTO` y `publicValues`. El SQL forestal y espacial permanece en los Repositories compartidos. Pruebas comparan semántica privada/pública cuando los datos son publicables. `publicFilterKeys()` alimenta la interfaz; no se mantiene otro contrato de filtros en JS.

## AC. Preparación de Fase 12

Los consumidores futuros podrán reutilizar DTO, Services, `PublicTreeRepository::query()`, indicadores y Repositories espaciales, conservando publicación y proyección. [Interfaz documentada](public-portal.md). No se implementaron exportaciones, reportes finales ni botones falsos. CSV/Excel/GeoJSON y plantillas Blade/PDF se reservan para la fase autorizada posteriormente.

## AD. Pruebas PHP nuevas

**172 casos adicionales** respecto a Fase 10, incluyendo variantes de datasets. Nuevas clases:

- `PublicEvidenceDeliveryTest`: 29 casos, 157 aserciones.
- `PublicHistoryQueryTest`: 1 casos, 26 aserciones.
- `PublicPortalHttpTest`: 36 casos, 362 aserciones.
- `PublicPortalPerformanceTest`: 2 casos, 166 aserciones.
- `PublicPortalPresentationTest`: 12 casos, 214 aserciones.
- `PublicTreeEngineTest`: 31 casos, 102 aserciones.
- `PublicationHttpTest`: 27 casos, 123 aserciones.
- `PublicationTest`: 34 casos, 145 aserciones.

Cubren publicación/auditoría/concurrencia y tenant; rutas sin sesión; filtros y latestInspection; privacidad/IDOR/XSS; anulación y campañas privadas; imágenes y revocación/304; presentación/paginación; conteo de consultas, volumen y plan PostGIS. La migración se aplicó, revirtió y reaplicó en testing; se aplicó luego al entorno operativo sin publicar registros.

## AE. Total PHP y aserciones

| Verificación | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| Checkpoint previo F10 | 1.251 | 6.277 | PASS |
| Suite completa final F11 | 1423 | 7572 | PASS |

Cero errores, fallos y omitidas. Tiempo PHPUnit: 705.239445 segundos. Ejecución con `--fail-on-warning`; XML JUnit como evidencia. Se activaron las mismas opciones de artefactos F10 y las nuevas F11 para que las aserciones sean comparables.

## AF. Pruebas JavaScript nuevas

15 nuevas en `public-map-policy.test.mjs`, `public-map-popup.test.mjs` y `public-images.test.mjs`: transporte/rutas/credenciales, lista de filtros y serialización, manejo de cero, payloads/límites/capas, códigos/enlaces y escape de popup, imágenes diferidas y fallback. Las 37 pruebas anteriores permanecen.

## AG. Total JavaScript

**52 PASS, 0 fallos, 0 omitidas**. Se ejecutó Node con los módulos y tests copiados byte a byte a staging externo, verificando SHA-256 de los 24 archivos para asegurar identidad con la fuente definitiva. Duración final: 448,0299 ms. Staging no es una dependencia de la aplicación.

## AH. PSR-12

`composer format` aplicado; `composer lint` final **PASS en 474 archivos**. `git diff --check` sin errores. Comentarios/PHPDoc/documentación útiles en español; identificadores técnicos en inglés. Después de la suite completa, el usuario solicitó añadir «Iniciar sesión» a la navegación pública. Se incorporó el enlace a la ruta existente `login`, con estilo visible en escritorio y móvil, y se sustituyó la prohibición anterior de ese enlace en la prueba de presentación. Verificación del ajuste: 12 pruebas / 214 aserciones aprobadas, PSR-12 del archivo de prueba aprobado y navegación real al formulario confirmada. No se modificaron reglas de negocio; no se repitió la suite completa por este ajuste de interfaz.

## AI. Rendimiento

Resultados iguales con 1, 100, 1.024 y 2.048 individuos publicados de prueba:

| Operación | Consultas SQL |
| --- | ---: |
| Puntos | 10 |
| Tabla con indicadores/preparación de filtros | 16 |
| Clusters | 6 |
| Calor | 5 |
| Búsqueda | 7 |
| Opciones de inventario | 5 |

Incluye servicio/proyección; excluye creación de fixtures. Con 2.048 registros, los puntos se limitan a 2.000 con truncamiento informado. La prueba de historia/galería compara 1 con 16 registros por dominio, comprueba páginas 15/15/12 y crecimiento acotado de consultas.

`EXPLAIN ANALYZE` con 4.096 puntos públicos y una bbox selectiva usó naturalmente **trees_geometry_gist**, con seqscan/indexscan/bitmapscan habilitados. Planificación **1.471 ms**, ejecución **0.189 ms**, una fila resultante. Son mediciones de fixtures locales, no una garantía de latencia nacional. El filtro público se aplica antes de los agregados.

## AJ. Docker e infraestructura

`docker compose up -d` terminó correctamente tras construir la imagen con EXIF. Resultado de `docker compose ps --all`:

```text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED          STATUS                      PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         26 minutes ago   Up 26 minutes (healthy)     9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     10 days ago      Exited (0) 26 minutes ago   
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        26 minutes ago   Exited (0) 26 minutes ago   
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     9 days ago       Up 13 hours (healthy)       127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       10 days ago      Up 13 hours (healthy)       127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    10 days ago      Up 13 hours (healthy)       5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       26 minutes ago   Up 26 minutes (healthy)     9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       10 days ago      Up 13 hours (healthy)       6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   26 minutes ago   Up 26 minutes (healthy)     9000/tcp
```

PHP **8.4.25**, Laravel **13.31.0**, PostgreSQL **17.5**, PostGIS **3.5.2**, Redis conectado; GD/EXIF disponibles. Nginx solo expone `/public` por **127.0.0.1:8080**; Mailpit por **127.0.0.1:8025**. PHP-FPM9000, PostgreSQL5432 y Redis6379 quedan internos. Compose, redes y estructura Docker de fases anteriores se conservan; el cambio de imagen agrega EXIF para orientación de imágenes derivadas.

El worker procesó `sysforest:probe-queue`: **2026-09-22T00:31:25+00:00**. El scheduler registró ejecución real a **2026-09-22T00:36:02+00:00**; `schedule:list` muestra `sysforest:probe-scheduler` cada minuto con bloqueo de superposición. Son sondas de infraestructura en Redis, sin cambios forestales. Los contenedores init terminan con código 0 por diseño.

## AK. Git

Rama **codex/phase-11**. HEAD **599728f — feat: complete phase 10 geospatial map**, creado únicamente después de validar el baseline aprobado. Cambios F11 sin stage ni commit final: **38 archivos modificados y 70 nuevos**. No se versionan secretos ni artefactos temporales; `.phase*-work/` está ignorado y no existe dependencia de staging. El inventario siguiente refleja `git diff --name-only` y `git ls-files --others --exclude-standard`, incluyendo este informe.

## AL. Archivos creados/modificados

Modificados:

```text
AGENTS.md
CLAUDE.md
Dockerfile
README.md
app/DTOs/TreeReadScope.php
app/Forestry/InventoryInput.php
app/Models/Evidence.php
app/Models/Inventory.php
app/Models/Organization.php
app/Models/Tree.php
app/Models/TreeInspection.php
app/Providers/AppServiceProvider.php
app/Repositories/InspectionQueryRepository.php
app/Repositories/MapOptionRepository.php
app/Repositories/MapScopeRepository.php
app/Repositories/MapTreeRepository.php
app/Repositories/TreeQueryRepository.php
app/Services/ImageMetadataService.php
app/Validation/EvidenceInput.php
app/Validation/OrganizationRules.php
app/Validation/TreeInput.php
app/Validation/TreeInspectionInput.php
bootstrap/app.php
database/seeders/AuthorizationSeeder.php
docs/evidences.md
docs/maintenances.md
docs/map-filters.md
docs/maps.md
public/js/map/api.js
public/js/map/filters.js
public/js/map/layers.js
public/js/map/map.js
public/js/map/popup.js
public/js/map/state.js
resources/views/maps/filters.blade.php
resources/views/maps/index.blade.php
resources/views/partials/institutional-navigation.blade.php
routes/web.php
```

Creados:

```text
app/DTOs/PublicTreeFilterDTO.php
app/Http/Controllers/PublicEvidenceController.php
app/Http/Controllers/PublicMapController.php
app/Http/Controllers/PublicPortalController.php
app/Http/Controllers/PublicTreeController.php
app/Http/Controllers/PublicationController.php
app/Http/Middleware/PublicResponseHeaders.php
app/Http/PublicExceptionRenderer.php
app/Http/Resources/PublicEvidenceResource.php
app/Http/Resources/PublicInspectionResource.php
app/Http/Resources/PublicMaintenanceSummaryResource.php
app/Http/Resources/PublicTreeDetailResource.php
app/Http/Resources/PublicTreeMapResource.php
app/Http/Resources/PublicTreeSummaryResource.php
app/Models/PublicationRevision.php
app/Repositories/PublicReferenceRepository.php
app/Repositories/PublicTreeRepository.php
app/Repositories/PublicVisibilityRepository.php
app/Repositories/PublicationRepository.php
app/Services/PublicEvidenceDeliveryService.php
app/Services/PublicMapService.php
app/Services/PublicPortalService.php
app/Services/PublicTreeFilterService.php
app/Services/PublicTreeService.php
app/Services/PublicationAccessService.php
app/Services/PublicationAuditService.php
app/Services/PublicationReadService.php
app/Services/PublicationService.php
app/Validation/PublicationInput.php
config/public-portal.php
database/migrations/2026_09_21_000017_add_publication_policy.php
docs/phase-11.md
docs/public-data-policy.md
docs/public-portal.md
public/css/public-portal.css
public/js/map/engine.js
public/js/map/popup-content.js
public/js/public/images.js
public/js/public/list.js
public/js/public/map.js
public/js/public/policy.js
public/js/public/popup.js
public/js/public/tree.js
resources/views/maps/filter-fields.blade.php
resources/views/maps/panel.blade.php
resources/views/public/assets.blade.php
resources/views/public/error.blade.php
resources/views/public/gallery.blade.php
resources/views/public/home.blade.php
resources/views/public/indicators.blade.php
resources/views/public/inspection.blade.php
resources/views/public/layout.blade.php
resources/views/public/map.blade.php
resources/views/public/trees/index.blade.php
resources/views/public/trees/show.blade.php
resources/views/publication/index.blade.php
routes/public.php
routes/publication.php
tests/Feature/PublicEvidenceDeliveryTest.php
tests/Feature/PublicHistoryQueryTest.php
tests/Feature/PublicPortalHttpTest.php
tests/Feature/PublicPortalPerformanceTest.php
tests/Feature/PublicPortalPresentationTest.php
tests/Feature/PublicTreeEngineTest.php
tests/Feature/PublicationHttpTest.php
tests/Feature/PublicationTest.php
tests/Frontend/public-images.test.mjs
tests/Frontend/public-map-policy.test.mjs
tests/Frontend/public-map-popup.test.mjs
tests/PublicPortalTestCase.php
```

## AM. Deudas y problemas encontrados

Se conservan separadas las deudas previas: correo de avisos de mantenimiento; advertencia Composer por fijación exacta de JWT 2.9.3; modelo multifuste; estados fenológicos pendientes; anulación formal de inspecciones. No se resolvieron incidentalmente en esta fase.

Durante la integración se corrigieron un fixture con `public_code` desactualizado, una expectativa de booleano de formulario y una aserción de privacidad que confundía la URL pública del paginador con una ruta física. El primer build coincidió con archivos aún en creación; se repitió al completar la integración y terminó correctamente. La suite final completa pasó después de estas correcciones.

El mapa base externo presentó algunas teselas no disponibles durante una revisión; la interfaz distingue ese aviso del resultado forestal y las capas de árboles continuaron funcionando. La disponibilidad del proveedor de teselas sigue siendo externa. No queda un fallo conocido bloqueante de Fase 11.

## AN. Decisiones pendientes

El usuario debe revisar Fase 11 antes del commit final y antes de autorizar Fase 12. No hace falta una decisión adicional para consultar el portal local. Las entidades decidirán qué organizaciones, campañas, árboles, inspecciones y fotos publicar mediante la gestión privada; nada se autorizó automáticamente. Una política futura para observaciones libres o mantenimientos programados requiere aprobación expresa; mientras tanto se mantienen privados. La puesta en Internet y elección/capacidad del proveedor cartográfico no se realizan en esta entrega local.

## Comandos y evidencia reproducible

Ejecutados desde `/home/josev/SYSFOREST`, dentro de Ubuntu-24.04 WSL, mediante pasos separados:

```bash
git status --short --branch
docker compose up -d
docker compose exec -T -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 app php artisan test
git add .
git commit -m "feat: complete phase 10 geospatial map"
git switch -c codex/phase-11
# Tras implementar y verificar la migración reversible en sysforest_testing:
docker compose build app
docker compose up -d
docker compose exec -T app php artisan db:seed --class=AuthorizationSeeder --force
docker compose exec -T -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 -e SYSFOREST_PUBLIC_EXPLAIN=1 -e SYSFOREST_PUBLIC_VISUAL=1 app php artisan test --fail-on-warning --compact --log-junit /tmp/sysforest-phase11-full.xml
docker compose exec -T app composer lint
docker compose exec -T app php artisan sysforest:probe-queue
docker compose exec -T app php artisan schedule:list
docker compose ps --all
git diff --check
# Node en host; copia de verificación con hashes idénticos:
node --test tests/Frontend/*.mjs
```

No ejecutar suites DB en paralelo: comparten `sysforest_testing`. Conservar las banderas de artefactos para reproducir exactamente el número de aserciones registrado. Evidencia técnica disponible al cierre en `/tmp/sysforest-phase11-full.xml`, `/tmp/sysforest-phase11-query-count.json` y `/tmp/sysforest-phase11-explain.json` del contenedor app. Los logs/XML, resultados HTTP, hashes JS y capturas de prueba también se conservaron en el staging externo `.phase11-work` del workspace Windows; no forman parte del despliegue.

Guías operativas: [portal público](public-portal.md), [política de datos](public-data-policy.md), [mapa](maps.md), [filtros](map-filters.md), [evidencias](evidences.md) y [mantenimientos](maintenances.md).

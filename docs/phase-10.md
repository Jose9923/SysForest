# Fase 10 — Mapa general SIGAU

Entrega para revisión en `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Rama **codex/phase-10**, checkpoint **d71c3dd**. La Fase 11 no se inició y los cambios de esta fase permanecen sin commit final.

## Checkpoint y alcance

Antes de implementar se ejecutaron `docker compose up -d`, **1.088 pruebas PHP / 4.868 aserciones**, **7 pruebas JavaScript** y PSR-12 en **404 archivos**, sin fallos. La suite PHP de referencia duró 404,018588 segundos. Se inspeccionó el staging: `.phase9-work/` no existe dentro del repositorio definitivo y `/.phase*-work/` ya estaba ignorado. Los artefactos Windows son externos; SYSFOREST no depende de ellos. Se creó el commit expresamente solicitado **d71c3dd**, `feat: complete phase 9 maintenance management`, y después la rama de trabajo actual.

Se entrega mapa interno real con Leaflet, PostGIS, puntos, clusters, calor de densidad, filtros compartidos, búsqueda y navegación privada. No se agregan migraciones, permisos, semillas de árboles ni cambios de dependencias PHP/Docker. La fuente espacial sigue siendo `trees.geometry`, Point/SRID 4326. Los datos sintéticos se limitaron a `sysforest_testing` y se revirtieron mediante sus transacciones.

## Reporte A–AL

| Punto | Entrega y contrato |
| --- | --- |
| A. Arquitectura | Controller → Service → Repository → PostGIS/Model → Resource JSON o Blade. Un motor forestal compartido, sin consultas en vistas ni decisiones de negocio en JavaScript. |
| B. Rutas Blade | `GET /map` (`maps.index`), navegación institucional «Mapa general»; `POST /map/trees/{tree}/open` reautoriza alcance, selecciona contexto institucional y redirige a la ficha existente. |
| C. Endpoints internos | `GET /api/v1/map/trees`, `/clusters`, `/heat`, `/extent`, `/search`, `/options/{kind}`. Cookie JWT, sesión revocable, cuenta verificada, permisos vigentes y 180 peticiones/minuto por usuario. |
| D. Resources | `MapTreeResource` entrega identidad, código, nombres, estado, emplazamiento, fecha de última inspección autorizada y enlace privado; `MapCellResource` proyecta agrupaciones. Calor: solo geometría y peso, sin identidades. |
| E. DTOs/filtros | `TreeFilterDTO` validado por `TreeFilterService`, `MapTreeFilterDTO` para bbox/zoom/radio y `TreeReadScope` explícito. [Contrato completo](map-filters.md). |
| F. Services | `MapReadService`, `MapFilterService`, `MapAccessService`, `MapTreeNavigationService` y `TreeFilterService`. Los listados Tree/LatestTree reutilizan el mismo motor. |
| G. Repositories/PostGIS | `TreeQueryRepository`, `InspectionQueryRepository`, `MapTreeRepository`, `MapOptionRepository`, `MapScopeRepository`. `ST_Intersects`, `ST_MakeEnvelope`, `ST_DWithin` sobre geography, `ST_Extent`, `ST_X/Y` y agregación SQL; bindings parametrizados con precisión completa. |
| H. Leaflet | 1.9.4 local, Select2 4.0.13 y jQuery 3.7.1 existentes. Sin CDN para ejecutables, sin build adicional. OpenStreetMap usa teselas HTTPS con atribución y referente limitado al origen. |
| I. Cluster | Supercluster 9.1.0 local para hasta 2.000 puntos; para `zoom < 10` o más puntos, cuadrícula agregada en PostgreSQL, hasta 65 × 65 celdas. Zoom, expansión y acceso a individuos; no duplica capas. |
| J. Heatmap | `@linkurious/leaflet-heat` 0.2.6 local, compatible con Leaflet `^1.9.0`. Peso COUNT de individuos dentro de la misma consulta filtrada; densidad relativa del encuadre, nunca riesgo forestal. |
| K. Bbox | west/south/east/north y zoom validados; bordes inclusivos, coordenadas geográficas acotadas, dimensiones positivas, parámetros SQL y protección de precisión decimal/DMS. Debounce de 300 ms, aborto y descarte de respuestas antiguas. Cruce del antimeridiano consulta todas las longitudes con aviso. |
| L. Extents | Extensión de puntos autorizados/filtrados, independiente del viewport; alternativa al territorio real de organización/campaña/departamento. Sin geometrías: vista mundial de contexto, sin punto ficticio. Contornos complejos/inválidos/limitados producen metadata y aviso persistente. |
| M. Filtros territoriales | Departamento, municipio, organización, inventario; referencias autorizadas y cascadas coherentes. La campaña utiliza `inventory_trees` y no multiplica árboles. |
| N. Filtros forestales | Código, texto, nombre común/científico, especie, familia, emplazamiento, hábito, dos orígenes, estado, protegido y patrimonial; `false`/`0` se conservan como filtros reales. |
| O. Inspección | Fecha, madurez, clase diamétrica/altura y condiciones físicas/fitosanitarias. Se consulta la última visita determinista antes de aplicar filtros, con `inspections.view`. |
| P. Mantenimiento | Tipo, estado, prioridad y pendientes/programados mediante un mismo EXISTS institucional sobre una orden. Anulados excluidos; cancelados solo cuando se solicitan expresamente. Permiso `maintenances.view`. |
| Q. ANY/ALL | ANY encuentra al menos una condición de las seleccionadas; ALL exige todas, incluyendo la unión de ambas listas, dentro de una misma última inspección. COUNT DISTINCT/EXISTS evita duplicados. |
| R. LatestInspection | LEFT JOIN LATERAL con `inspection_date DESC, created_at DESC, id DESC`. Sin filtro de visita los árboles sin inspecciones permanecen consultables. No se crea un supuesto estado de anulación de inspecciones. |
| S. Simbología | Siete estados reales, colores y textos configurados en backend; no se equipara muerto/talado/retirado con SoftDelete. Leyenda separada para densidad. |
| T. LayerControl | Selector Leaflet de bases configurables y contornos reales disponibles; inicia con OpenStreetMap. No hay capa satelital fingida ni capas futuras activadas. |
| U. Popup/búsqueda | Texto DOM escapado; consulta paginada por código/nombres. Árbol sin ubicación se encuentra con `geometry: null` y aviso, sin centrarlo en (0, 0). «Ver ficha» usa POST con CSRF y conserva y reautoriza el scope incluso con roles combinados. |
| V. Responsive | Escritorio de 1440 × 1000, tableta de 768 × 1024 y móvil de 390 × 844 revisados. Filtros inicialmente colapsados hasta 1150 px, Select2 usable, mapa con altura útil, sin desbordamiento horizontal. |
| W. Seguridad | JWT/HttpOnly y sesión vigentes, CSRF, UUID, IDOR, 404 ante referencias ajenas, 403 ante alcances forzados, 422 ante bbox o inyección inválidos, XSS neutralizado, límite de tasa con 429 y `Retry-After`, no-store en datos privados y errores sin detalles SQL. |
| X. Tenant | organization_id delimita árbol/inspección/pivot/mantenimiento. Cada operación revalida actor, concesión, cuenta, jerarquía activa y membresía cuando procede. No hay caché compartida de respuestas ni datos derivados de filtros del cliente. |
| Y. DepartmentAdmin | Lee todos sus departamentos autorizados sin seleccionar artificialmente una organización. Abrir ficha activa el contexto institucional existente en modo lectura; no añade membresías ni escritura. |
| Z. SuperAdmin | Lectura nacional explícita sin tenant ficticio. La ficha y cualquier operación institucional conservan sus políticas y contexto; consultar el mapa no otorga escritura nacional implícita. |
| AA. PHP nuevo | 163 casos nuevos, detallados abajo: filtros, espacios, precisión, alcances, endpoints, rendimiento y Blade. |
| AB. JS nuevo | 30 casos nuevos: serialización, URL, bbox, abort/debounce, API privada, capas exclusivas, Supercluster real, calor, avisos y popup seguro con scope. |
| AC. PHP total | **1251 pruebas / 6277 aserciones**, cero fallos/errores/omitidas; 498.282174 segundos. |
| AD. JS total | **37/37 PASS**: 30 nuevas y 7 anteriores de evidencias. |
| AE. PSR-12 | **432 archivos PASS**, formatter aplicado y comprobación `composer lint`; `git diff --check` correcto. |
| AF. GiST | EXPLAIN ANALYZE/BUFFERS sobre 4.096 puntos sintéticos, planificador natural y `enable_seqscan=on`: usa `trees_geometry_gist`, devuelve 1 fila. Plan y bindings en [evidencia de rendimiento](phase-10-performance.json). |
| AG. Query count | **73 consultas con 1 marcador y 73 con 100**, incluye autorización, filtros, contadores, relaciones y Resources. La prueba exige < 80 consultas y un crecimiento ≤ 5. No es prueba de carga concurrente. |
| AH. Docker | `docker compose up -d` PASS; siete servicios healthy, dos inicializadores `Exited 0`. Salida reproducida abajo. |
| AI. Git | `codex/phase-10`, HEAD d71c3dd. Cambios sin commit final, merge ni publicación. |
| AJ. Archivos | **74 archivos: 59 nuevos y 15 modificados**. [Inventario exacto](phase-10-files.txt), con estado por ruta. Sin cambios a pruebas rastreadas previas, Composer, Dockerfile, Compose ni migraciones. |
| AK. Deudas | Correo de mantenimiento; advertencia Composer por pin de JWT; multifuste; estados fenológicos pendientes; anulación formal de inspecciones. Se preservan sin cambios arbitrarios. |
| AL. Portal F11 | DTO/motor/alcance/repositorio espacial reutilizables; requiere Services, autorización y Resources públicos separados con campos aprobados. Ninguna ruta de esta fase es pública. F11 y reportes/exportaciones de F12 no están implementados. |

## Pruebas PHP incorporadas

| Clase | Casos | Aserciones |
| --- | ---: | ---: |
| MapEndpointSecurityTest | 35 | 513 |
| MapPerformanceTest | 2 | 321 |
| MapPresentationTest | 10 | 117 |
| MapScopeSecurityTest | 46 | 118 |
| MapSpatialPrecisionTest | 8 | 23 |
| MapSpatialTest | 35 | 78 |
| TreeFilterEngineTest | 27 | 239 |

Se conservaron las 1.088 pruebas de Fase 9. La suite usa PostgreSQL/PostGIS real y se ejecutó serialmente. Los filtros existentes de bandeja y la consulta histórica de inspecciones conservan sus contratos; la consulta actual aplica filtros después de resolver la última inspección. Se probaron también los cuatro bordes con coordenadas de máxima precisión, radio cero decimal/DMS y precisión de agregación independiente de `precision` de PHP.

Durante la integración se corrigieron revalidaciones duplicadas (190 → 73 consultas), pérdida de precisión en bindings espaciales, scope perdido al abrir fichas de actores con varios roles, selección «No» en Blade y omisión silenciosa de contornos grandes. También se corrigieron fixtures que usaban nombres de ruta erróneos o asignación masiva sobre Tree protegido. El resultado global anterior corresponde al estado posterior a esas correcciones.

La medición de GiST es selectiva; no garantiza que PostgreSQL prefiera ese índice para encuadres mundiales. No se forzó `enable_seqscan=off`, ni se presenta como benchmark sobre cientos de miles de árboles. El presupuesto de 73 consultas sigue siendo una oportunidad de optimización con carga real, conservando la revalidación inmediata de permisos.

## Verificación HTTP y visual

Sobre el entorno local real, SUPER_ADMIN, DEPARTMENT_ADMIN, ORGANIZATION_ADMIN y OFFICIAL obtuvieron 200 en mapa/puntos/clusters/calor/extent/búsqueda/opciones. Los dos primeros accedieron al mapa sin seleccionar organización; los otros seleccionaron su institución. Las respuestas privadas incluyen no-store. Sin sesión, `/map` redirige al login y la API devuelve 401 incluso sin encabezado Accept de JSON. Los cuatro cierres de sesión pasaron. Las credenciales y cookies permanecieron en memoria y no se insertaron árboles.

La revisión visual utilizó HTML Blade y GeoJSON generados por `MapReadService` en transacciones de testing: 10 puntos y 1 árbol sin ubicación. Un servidor temporal de solo lectura reprodujo esos payloads; por eso esta revisión confirma presentación/interacción y no sustituye las pruebas backend de filtros. Se comprobaron mapa real, popup, calor, leyenda, cluster de 10 árboles, expansión, cambio a Puntos, Select2 territorial, chip/URL y tres tamaños de pantalla. Consola sin errores. Los enlaces de fichas y formularios de escritura del snapshot estaban neutralizados; su seguridad se comprobó con pruebas HTTP. Al finalizar se cerró la pestaña, se restauró el viewport y se detuvo el servidor de revisión del puerto 8093.

Node ejecutó copias idénticas de todos los tests frontend, módulos propios y Supercluster, con SHA256 comparado contra el repositorio definitivo; esto evita la limitación de rutas UNC del runtime Windows. Las copias de verificación en `.phase10-work` no son una dependencia del proyecto. Las distribuciones/avisos locales de las bibliotecas también se verificaron contra sus manifiestos SHA256.

## Estado operativo

Comprobación 2026-09-21T22:13:32+00:00: PHP **8.4.25**, Laravel **13.31.0**, PostgreSQL **17.5 (Debian 17.5-1.pgdg110+1)**, PostGIS **3.5.2**, Redis conectado: **True**. Base **sysforest**, 43 permisos y 0 árboles, inspecciones, evidencias o mantenimientos. El worker procesó el probe **2026-09-21T22:05:28+00:00**; scheduler automático **2026-09-21T22:13:00+00:00**, periodicidad cada minuto confirmada mediante `schedule:list`.

Solo se publican **127.0.0.1:8080** (Nginx) y **127.0.0.1:8025** (Mailpit). PostgreSQL 5432, Redis 6379, PHP-FPM 9000 y SMTP 1025 continúan internos. No se modificó la estructura Compose ni se requirió reconstruir la imagen.

~~~text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED       STATUS                       PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         8 days ago    Up About an hour (healthy)   9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     10 days ago   Exited (0) 10 minutes ago    
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        8 days ago    Exited (0) 10 minutes ago    
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     9 days ago    Up 10 hours (healthy)        127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       10 days ago   Up 10 hours (healthy)        127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    10 days ago   Up 10 hours (healthy)        5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       8 days ago    Up 8 minutes (healthy)       9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       10 days ago   Up 10 hours (healthy)        6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   8 days ago    Up About an hour (healthy)   9000/tcp
~~~

## Comandos reproducibles

Desde `/home/josev/SYSFOREST`, en Ubuntu-24.04:

~~~bash
docker compose up -d
docker compose ps -a
docker compose exec -T -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 app php artisan test --log-junit /tmp/sysforest-phase10-final.xml
docker compose exec -T -e SYSFOREST_MAP_EXPLAIN=1 app php artisan test tests/Feature/MapPerformanceTest.php
docker compose exec -T app composer lint
git diff --check
docker compose exec -T app php artisan sysforest:probe-queue
docker compose exec -T app php artisan schedule:list
~~~

Formato aplicado con contenedor desechable y montaje escribible:

~~~bash
docker run --rm --user 1000:1000 --volume /home/josev/SYSFOREST:/var/www/html --workdir /var/www/html --entrypoint composer sysforest-app:local format
~~~

Ejecución JavaScript realizada desde el workspace Windows con copias verificadas:

~~~powershell
node --test .phase10-work/frontend-final/tests/Frontend/*.mjs
~~~

JUnit final: `/tmp/sysforest-phase10-final.xml` dentro de app. XML/log y comprobaciones HTTP están en `C:/Users/josev/OneDrive/Documents/ChatGPT/SysForest/.phase10-work/`; el plan reproducible se conserva en la documentación del repositorio. `SYSFOREST_MAP_VISUAL=1` habilita únicamente snapshots de prueba saneados, nunca fixtures productivos.

## Límites y continuidad

Máximo 2.000 puntos individuales; el servidor informa truncamiento y mantiene contadores completos. Clusters y calor agregan todo el conjunto en el bbox con cuadrícula acotada; no descargan todos los árboles de un país. Las búsquedas retornan 20 resultados por página y las opciones 30, con un máximo de 1.000 páginas. El calor inicial solo representa densidad.

Los contornos territoriales proceden de geometrías cargadas, con un máximo de 20 registros por tipo y 20.000 vértices por geometría. Las omisiones por complejidad/validez y truncamiento se anuncian; no se simplifica ni inventa cartografía. El extent puede usar el territorio completo aunque su contorno no se transporte. El radio métrico está preparado y probado en backend; no se añade un flujo de radio en UI. Las teselas externas requieren conectividad y muestran un aviso de carga si fallan.

Se mantienen las deudas previas: avisos por correo de mantenimiento todavía no implementados; advertencia Composer por la restricción exacta de JWT 2.9.3 (no una nueva evaluación de vulnerabilidades); multifuste y estados discretos de fenología sin reglas aprobadas; anulación formal de inspecciones pendiente. Véanse [Fase 9](phase-9.md), [Fase 5](phase-5.md), [inspecciones](inspections.md) y [dasonomía](dendrometry.md). No se reescriben mediciones ni fórmulas y no se añaden exportaciones, reportes finales o portal público.

La arquitectura técnica está descrita en [maps.md](maps.md), los filtros en [map-filters.md](map-filters.md) y las fronteras institucionales en [multitenancy.md](multitenancy.md). La siguiente fase requiere revisión y aprobación del usuario.

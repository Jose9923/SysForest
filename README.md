# SYSFOREST

Inventario y gestión forestal institucional con mapas y portal ciudadano. El proyecto está en **`/home/josev/SYSFOREST`, Ubuntu-24.04 WSL**; esa carpeta es la fuente de verdad.

SYSFOREST utiliza Laravel 13, PHP 8.4, PostgreSQL/PostGIS y Redis. Mantiene MVC con el flujo `Controller → Service → Repository → Model`; Blade renderiza la interfaz y las plantillas de reportes/PDF. CSV, Excel y GeoJSON reutilizan los servicios, repositorios y filtros. Los comentarios útiles y la documentación se escriben en español; el código PHP sigue PSR-12.

## Inicio local

Ejecuta los comandos en Ubuntu, desde la carpeta del proyecto:

~~~bash
cd /home/josev/SYSFOREST
docker version
docker compose version
bash bin/setup-env
docker compose config --quiet
docker compose build
docker compose up -d
docker compose ps -a
curl --fail --silent --show-error http://localhost:8080/health/ready
~~~

`setup-env` crea `.env` y completa secretos locales ausentes sin imprimirlos ni reemplazar los existentes. PHP y Composer funcionan en contenedores. Se esperan siete servicios continuos saludables y dos inicializadores (`db-init`, `init`) terminados con código 0. La disponibilidad responde HTTP 200 con `{"status":"ok"}`.

En una instalación **ya preparada**, basta `docker compose up -d` para arrancar. No hay que volver a sembrar datos en cada inicio.

En una instalación local nueva, prepara el piloto y los catálogos explícitamente:

~~~bash
docker compose exec -T app php artisan db:seed --class=DatabaseSeeder
~~~

Este seeder prepara el catálogo territorial de Colombia (32 departamentos, Bogotá D.C. y 1.104 opciones municipales), la institución piloto INEM de Villavicencio, los catálogos, cuatro roles, **49 permisos** y el inventario piloto. No genera árboles ficticios ni cuentas. Sincroniza la matriz de permisos de los roles, por lo que debe ejecutarse conscientemente. Para crear las cuatro cuentas opcionales de revisión local:

~~~bash
docker compose exec app php artisan db:seed --class=DevelopmentIdentitySeeder
~~~

Los nombres y correos locales se definen en el seeder. Al crear una cuenta solicita su contraseña mediante entrada oculta y la guarda como hash en la base de datos; no utiliza credenciales de usuarios en `.env`. Consulta [cuentas de desarrollo](docs/development-accounts.md). Repetir ese seeder no cambia contraseñas existentes. Las cuentas normales se incorporan mediante invitaciones, sin registro público abierto.

## Accesos

| Pantalla | Dirección local | Acceso |
| --- | --- | --- |
| Portal ciudadano | [Portal](http://localhost:8080/portal) | Anónimo; solo información publicada. |
| Inicio de sesión | [Iniciar sesión](http://localhost:8080/login) | También disponible en la cabecera del portal. |
| Organizaciones | [Organizaciones](http://localhost:8080/organizations) | Cuenta activa/verificada y alcance autorizado. |
| Trabajo forestal | [Inventarios](http://localhost:8080/inventories), [Árboles](http://localhost:8080/trees) | Organización seleccionada y permiso por operación. |
| Cartografía | [Mapa interno](http://localhost:8080/map), [Mapa público](http://localhost:8080/portal/map) | Alcance privado o universo publicado, respectivamente. |
| Reportes | [Internos](http://localhost:8080/reports), [Públicos](http://localhost:8080/portal/reports) | Mismos filtros del universo correspondiente. |
| Importaciones | [Importaciones](http://localhost:8080/imports) | SUPER_ADMIN, ORGANIZATION_ADMIN u OFFICIAL en una institución autorizada. |
| Auditoría | [Auditoría privada](http://localhost:8080/audit) | SUPER_ADMIN nacional u ORGANIZATION_ADMIN institucional. |
| Infraestructura | [Estado local](http://localhost:8080/) | Disponibilidad, cola y scheduler. |
| Correo de revisión | [Mailpit](http://localhost:8025) | Buzón local; no envía mensajes al exterior por defecto. |

Solo se publican `127.0.0.1:8080` y `127.0.0.1:8025`. PostgreSQL, Redis, SMTP y PHP-FPM permanecen en la red de Compose.

## Capacidades y límites

- Inventarios institucionales y árboles permanentes, vinculados a varias campañas mediante `inventory_trees`; ubicación PostGIS e historiales separados de las inspecciones.
- Inspecciones con cálculo dasométrico, condiciones, fenología y fauna; evidencias privadas y órdenes manuales de mantenimiento.
- Mapa Leaflet con puntos, agrupaciones y densidad; filtros compartidos y última inspección determinista.
- Publicación explícita y privada por defecto. El portal no expone funcionarios, auditoría, notas internas ni originales fotográficos con metadatos.
- Reportes Blade/PDF y exportación CSV, Excel y GeoJSON. Las descargas son síncronas y tienen límites configurados; no hay trabajos de exportación en cola.
- Plantilla `SYSFOREST_IMPORT_V1` y confirmación atómica por lote, por defecto de hasta 100 filas y 5 MiB. Cada fila crea un árbol y una inspección; no actualiza individuos existentes ni importa fotografías.
- Auditoría privada, historial protegido contra modificación/borrado y control de versiones que impide sobrescribir ediciones concurrentes.

La autenticación usa JWT de acceso en cookie HttpOnly y refresh opaco, rotatorio y revocable. `organization_id` delimita los datos institucionales en una base compartida. La selección de organización no concede permisos: cada operación revalida cuenta, asignaciones, alcance y recurso.

## Guías vigentes

Por solicitud expresa del usuario se retiraron las pruebas automatizadas. `bash bin/check` conserva las comprobaciones de PSR-12 y sintaxis de PHP y JavaScript; no ejecuta suites ni escribe en una base de pruebas. Consulta el [alcance de las verificaciones](docs/testing.md).

| Necesidad | Documento |
| --- | --- |
| Cargar departamentos y municipios de Colombia sin duplicar territorios | [Catálogo territorial](docs/territories.md) |
| Consultar la carga de 2.854 árboles de Restrepo, su fecha de referencia y revisiones pendientes | [Carga de Restrepo 2025](docs/restrepo-import.md) |
| Revisar resultados, correcciones y pendientes de aceptación local | [Aceptación del piloto](docs/acceptance-local.md) |
| Renovación de sesión, calendario de captura y cierre de mantenimiento | [Ajustes de usabilidad](docs/usability-fixes.md) |
| Aprender el flujo de trabajo y resolver mensajes habituales | [Guía de uso](docs/user-guide.md) |
| Arrancar, actualizar, diagnosticar y preparar una futura puesta en producción | [Operación](docs/operations.md) |
| Revisar PSR-12, sintaxis y comportamiento manual | [Verificaciones](docs/testing.md) |
| Conocer los 49 permisos y sus ámbitos | [Autorización](docs/authorization.md) y [multi-tenancy](docs/multitenancy.md) |
| Identidad, sesiones, invitaciones y contraseñas | [Autenticación](docs/authentication.md), [recuperación](docs/account-recovery.md) |
| Captura y trazabilidad forestal | [Árboles](docs/tree-domain.md), [inspecciones](docs/inspections.md), [dasonomía](docs/dendrometry.md), [coordenadas](docs/georeferencing.md) |
| Fotos y órdenes de trabajo | [Evidencias](docs/evidences.md), [almacenamiento](docs/file-storage.md), [mantenimientos](docs/maintenances.md) |
| Catálogos y correspondencia con el Excel original | [Catálogos](docs/forestry-catalogs.md), [94 variables](docs/excel-field-mapping.md) |
| Mapas, filtros y privacidad | [Mapa](docs/maps.md), [filtros](docs/map-filters.md), [portal](docs/public-portal.md), [política pública](docs/public-data-policy.md) |
| Formatos de salida y carga institucional | [Reportes](docs/reports-and-exports.md), [campos exportados](docs/exports-field-mapping.md), [importación](docs/import-export.md), [campos importados](docs/import-field-mapping.md) |
| Auditoría y conflictos de edición | [Auditoría](docs/audit.md), [concurrencia](docs/concurrency.md) |
| Estructura técnica | [Arquitectura](docs/architecture.md), [base de datos](docs/database.md) |

Los informes `docs/phase-*.md` conservan resultados históricos de cada entrega. El [cierre de Fase 15](docs/phase-15.md) registra su verificación completa histórica: 1806 pruebas PHP, 64 pruebas JavaScript y 577 archivos PSR-12; las cifras de una fase anterior no acreditan cambios posteriores. El esquema de Compose es local: todavía no representa un despliegue externo.

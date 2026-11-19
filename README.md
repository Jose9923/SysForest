# SYSFOREST

Actualización productiva del 18/11/2026 completada: [claves SQL BIGINT con identidad externa y antecedentes conservados](docs/bigint-identifiers-20261118.md). Se preservaron los dos árboles, el inventario, las visitas y fotografías existentes; 96 comprobaciones HTTPS web/móviles aprobadas. La fuente canónica local también quedó actualizada, con ocho servicios saludables y sus datos preservados.

Actualización 09/11/2026: [filtros de condiciones, foto del árbol completo y paridad móvil](docs/conditions-photo-20261109.md).

Inventario y gestión forestal institucional con mapas y portal ciudadano. El proyecto está en **`/home/josev/SYSFOREST`, Ubuntu-24.04 WSL**; esa carpeta es la fuente de verdad.

SYSFOREST utiliza Laravel 13, PHP 8.4, PostgreSQL/PostGIS y Redis. Mantiene MVC con el flujo `Controller → Service → Repository → Model`; Blade renderiza la interfaz y las plantillas de reportes/PDF. CSV, Excel y GeoJSON reutilizan los servicios, repositorios y filtros. Los comentarios útiles y la documentación se escriben en español; el código PHP sigue PSR-12.

## Instalación base sin datos piloto

`BaseSeeder` prepara el administrador inicial y los catálogos completos, incluidas **143 especies**, sin crear instituciones, inventarios piloto, árboles ni inspecciones. Configura las variables privadas `SYSFOREST_BASE_ADMIN_EMAIL`, `SYSFOREST_BASE_ADMIN_NAME` y `SYSFOREST_BASE_ADMIN_PASSWORD` y ejecuta con la conexión migradora:

```bash
php artisan migrate --seed --seeder=BaseSeeder --force
```

La repetición conserva la contraseña y la asignación del administrador existente válido; no lo reactiva ni restablece. La matriz de permisos de los roles sí se sincroniza con la definición vigente. Consulta el [alcance, configuración privada y comandos Docker](docs/base-seed-20261109.md). Verificación manual aislada aprobada el 09/11/2026; despliegue de esta entrega pendiente.

## Organización del código

Las capas del backend están agrupadas por módulo, como las vistas Blade: `Http/Controllers/Trees`, `Services/Trees`, `Repositories/Trees`, `Models/Trees`, y sus equivalentes de inspecciones, inventarios, riesgo y demás módulos. Consulta la [estructura del proyecto y compatibilidad de autoload](docs/module-organization-20261105.md).

## Producción

La plataforma está disponible en [https://sysforest.varcor.tech](https://sysforest.varcor.tech). Consulta el [despliegue del 4 de noviembre de 2026](docs/production-deployment-20261104.md) para datos trasladados, verificaciones y pendientes, y el [procedimiento productivo](docker/production/README.md) para operar el servidor. Los comandos de la sección siguiente preparan exclusivamente el entorno local.

### Visores privados de bases de datos · 18/11/2026

phpPgAdmin y Mongo Express se gestionan como servicios privados independientes mediante `compose.database-viewers.yml` y `bin/database-viewers install`, accesibles por túnel SSH en `127.0.0.1:18084` y `127.0.0.1:18085`. phpPgAdmin ofrece `sysforest_editor` para crear/editar registros y `sysforest_viewer` para consulta; los accesos se consultan en la terminal privada del servidor con `sudo /opt/sysforest/source/bin/database-viewers credentials`. Sus cuentas abarcan los datos autorizados al operador administrativo, incluidas distintas instituciones, y están separadas de las de la aplicación. El cambio de visor conserva datos, secretos y servicios de negocio. En Windows se abre con `bin/database-viewers-connect.ps1 -SshTarget usuario@servidor`; Linux/WSL dispone de `bin/database-viewers-tunnel`. Consulta [instalación, versiones, recuperación y evidencia del despliegue](docs/database-viewers.md).

La sustitución quedó activada el **18/11/2026**, commit `380a0b1`: 21/21 comprobaciones aisladas y 48/48 HTTP productivas aprobadas, además del acceso por túnel desde Windows y la repetición del instalador. Se conservaron los ocho archivos privados, Mongo Express y el pgAdmin anterior detenido con su volumen. La consulta, el ordenamiento y la exportación de datos CSV/SQL funcionan con BIGINT/IDENTITY; estas exportaciones no son respaldos completos. No hubo reinicios de los siete servicios de negocio durante el corte ni la repetición; el ciclo previo del worker y los límites de verificación constan en la guía enlazada.

La edición de registros quedó habilitada posteriormente, commit `7a5a7099`, mediante `sysforest_editor`; `sysforest_viewer` conserva consulta. Verificada con 27/27 observaciones aisladas de inserción/edición y 21/21 HTTP productivas sin escribir datos de negocio. La repetición conservó nueve secretos, la contraseña y los servicios; hashes de los datos existentes intactos.

## Manuales de la aplicación

Los [manuales de SysForest](docs/manuales.md) reúnen la documentación técnica y los procedimientos de usuario de la edición del 13 de octubre de 2026, contrastada con la implementación revisada:

- [Manual técnico en Word](docs/SysForest_Manual_Tecnico.docx) · [Contenido en texto](docs/manual-tecnico.md).
- [Manual de usuario en Word](docs/SysForest_Manual_Usuario.docx) · [Contenido en texto](docs/manual-usuario.md).

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

`setup-env` crea `.env` y completa secretos locales ausentes sin imprimirlos ni reemplazar los existentes. PHP y Composer funcionan en contenedores. Se esperan ocho servicios continuos saludables, incluido MongoDB, y dos inicializadores (`db-init`, `init`) terminados con código 0. La disponibilidad responde HTTP 200 con `{"status":"ok"}`.

En una instalación **ya preparada**, basta `docker compose up -d` para arrancar. No hay que volver a sembrar datos en cada inicio.

Al actualizar a una versión que incorpora nuevos secretos o dependencias, prepara primero la configuración y reconstruye la imagen:

~~~bash
bash bin/setup-env
docker compose build app
docker compose up -d
~~~

Esto completa, por ejemplo, `MONGO_ROOT_PASSWORD` y `AUDIT_MONGO_PASSWORD` sin sustituir claves existentes. Recrear un contenedor no actualiza una imagen anterior que carece del controlador PHP y del cliente de MongoDB. `.env` debe pertenecer al usuario que ejecuta Compose y tener permisos `600`.

`APP_UID` y `APP_GID` deben corresponder a `id -u` e `id -g` de tu usuario de Ubuntu y ser mayores que cero. Ejecuta la preparación desde tu usuario habitual, sin `sudo`; un UID/GID `0` impide crear el usuario no privilegiado de la imagen.

En una instalación local nueva, prepara el piloto y los catálogos explícitamente:

~~~bash
docker compose exec -T app php artisan db:seed --class=DatabaseSeeder
~~~

Este seeder prepara el catálogo territorial de Colombia (32 departamentos, Bogotá D.C. y 1.104 opciones municipales), la institución piloto INEM de Villavicencio, los catálogos, cuatro roles, **49 permisos** y el inventario piloto. Sincroniza la matriz de permisos de los roles, por lo que debe ejecutarse conscientemente. En `APP_ENV=local`, `db:seed` y `migrate --seed` incluyen además las cuatro cuentas de desarrollo, la preparación de Restrepo y la carga autorizada de **2.854 árboles e inspecciones en 29 lotes**, con su revisión de estados. No generan árboles ficticios. Requieren el paquete privado de Ornato, excluido de Git y de las imágenes; consulta [carga local de Ornato](docs/restrepo-ornato-seed.md#integración-local-con---seed).

Para aplicar migraciones pendientes y ejecutar todos los seeders locales:

~~~bash
docker compose exec -T app php artisan migrate --seed --no-interaction
~~~

La repetición reconoce los lotes completados y los historiales de la corrección, sin duplicarlos ni revertir estados modificados posteriormente. Un archivo ausente o con huella distinta detiene la siembra; no se cambia la partición para eludir importaciones anteriores.

Para ejecutar únicamente la preparación de cuentas:

~~~bash
docker compose exec -T app php artisan db:seed --class=DevelopmentIdentitySeeder --no-interaction
~~~

Por solicitud expresa del usuario, `admin.inem@sysforest.test`, `funcionario.inem@sysforest.test` y `departamento.meta@sysforest.test` tienen la contraseña inicial específica autorizada en el código. El resto de las cuentas locales conserva el valor general anterior. Los nombres y correos se definen en el seeder y `DevelopmentPasswordService::passwordForEmail()` selecciona la clave de cada cuenta; la base almacena únicamente su hash. La documentación no duplica el valor de esa clave. Los seeders de desarrollo no toman credenciales de usuarios de `.env`; la configuración privada de `BaseSeeder` se documenta por separado. Este seeder también ejecuta `DevelopmentPasswordSeeder`, que restablece todas las cuentas existentes, incluidas las ajenas a sus cuatro perfiles, y revoca las sesiones de quienes cambian de contraseña. Ambos seeders se restringen a `local` o `testing`. Consulta [cuentas de desarrollo](docs/development-accounts.md). Las cuentas normales se incorporan mediante invitaciones, sin registro público abierto.

Para restablecer únicamente las contraseñas locales, sin aprovisionar perfiles ni sincronizar roles:

~~~bash
docker compose exec -T app php artisan db:seed --class=DevelopmentPasswordSeeder --no-interaction
~~~

## Accesos

| Pantalla | Dirección local | Acceso |
| --- | --- | --- |
| Portal ciudadano | [Portal](http://localhost:8080/portal) | Anónimo; solo información publicada. |
| Inicio de sesión | [Iniciar sesión](http://localhost:8080/login) | También disponible en la cabecera del portal. |
| Organizaciones | [Organizaciones](http://localhost:8080/organizations) | Cuenta activa/verificada y alcance autorizado. |
| Trabajo forestal | [Inventarios](http://localhost:8080/inventories), [Árboles](http://localhost:8080/trees) | Organización seleccionada y permiso por operación. |
| Cartografía | [Mapa interno](http://localhost:8080/map), [Mapa público](http://localhost:8080/portal/map) | Alcance privado o universo publicado, respectivamente. |
| Reportes | [Internos](http://localhost:8080/reports), [Públicos](http://localhost:8080/portal/reports) | Mismos filtros del universo correspondiente. |
| Gestión del riesgo | [Parametrización institucional](http://localhost:8080/risk) | Metodologías propias por institución; el profesional confirma la prioridad en la inspección. [Contrato y operación](docs/risk-management.md). |
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
| Entender Activo/En observación/Muerto y la corrección de Restrepo | [Estados de Restrepo](docs/restrepo-status-review.md) |
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

Los informes `docs/phase-*.md` conservan resultados históricos de cada entrega. El [cierre de Fase 15](docs/phase-15.md) registra su verificación completa histórica: 1806 pruebas PHP, 64 pruebas JavaScript y 577 archivos PSR-12; las cifras de una fase anterior no acreditan cambios posteriores. El archivo `docker-compose.yml` conserva el entorno local; `compose.production.yml` corresponde al [despliegue externo documentado](docs/production-deployment-20261104.md).

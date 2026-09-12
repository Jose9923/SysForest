# SYSFOREST — Fase 7

**Inventario y gestión forestal inteligente.**

SYSFOREST incorpora inventarios institucionales, individuos forestales permanentes, ubicación PostGIS e inspecciones históricas con dasonomía, condiciones, fenología y fauna, sobre los catálogos, la autenticación y el territorio aprobados. La interfaz se renderiza con Blade. El piloto es la Institucion Educativa INEM Luis Lopez de Mesa, en Villavicencio, Meta, Colombia.

El proyecto definitivo está en **`/home/josev/SYSFOREST`, dentro de Ubuntu-24.04 WSL**. Inventory, Tree y TreeInspection están implementados. Las fotografías/evidencias corresponden a Fase 8, que requiere una nueva aprobación.

El [reporte A–AN de Fase 7](docs/phase-7.md) documenta la entrega actual y sus verificaciones. Consulta [inspecciones](docs/inspections.md) y [dasonomía](docs/dendrometry.md) para sus contratos. El checkpoint de Fase 6 es **89911d8**, validado con 546 pruebas y 2.484 aserciones; su [reporte histórico](docs/phase-6.md) permanece disponible. Los cambios actuales se conservan sin commit final en `codex/phase-7`. La [matriz de 94 campos del Excel](docs/excel-field-mapping.md) y la [trazabilidad de catálogos](docs/forestry-catalogs.md) conservan las fuentes. El [reporte de Fase 4](docs/phase-4.md) mantiene su evidencia histórica. Consulta [autenticación](docs/authentication.md), [autorización](docs/authorization.md), [recuperación e invitaciones](docs/account-recovery.md) y [aislamiento institucional](docs/multitenancy.md) para sus contratos. El cierre global de pruebas de Fase 4 se documenta en su reporte, no se deduce de los resultados focalizados de cada componente.

## Entorno local

Ejecuta estos comandos en la terminal **Ubuntu**, desde el proyecto:

~~~bash
cd /home/josev/SYSFOREST
~~~

Docker y Docker Compose deben estar disponibles para el usuario de Ubuntu. PHP, Composer, PostgreSQL y Redis se ejecutan en contenedores; el script de preparación necesita Bash y OpenSSL.

| Componente | Configuración |
| --- | --- |
| Backend | Laravel 13 y PHP 8.4 FPM |
| Web | Nginx 1.28 |
| Base de datos | PostgreSQL 17 con PostGIS 3.5 |
| Caché, navegación y colas | Redis 7.4 con persistencia AOF |
| Identidad | JWT de acceso y sesiones revocables en PostgreSQL |
| Correo local | SMTP interno y buzón de desarrollo Mailpit |
| Interfaz | Blade, renderizado en servidor |

## Preparación y arranque

Comprueba el entorno y prepara la configuración:

~~~bash
docker version
docker compose version
bash bin/setup-env
docker compose config --quiet
~~~

`setup-env` crea `.env` desde `.env.example` cuando no existe, completa secretos locales ausentes y conserva los valores ya configurados. En `APP_ENV=local` genera también `JWT_SECRET` y las contraseñas de las cuatro cuentas de desarrollo, sin mostrarlas. La configuración real permanece fuera de Git y del contexto de construcción.

Construye la imagen e inicia los servicios:

~~~bash
docker compose build
docker compose up -d
docker compose ps -a
~~~

La imagen instala las dependencias fijadas en `composer.lock`. El primer arranque prepara los roles de PostgreSQL, las bases operativa/de pruebas, PostGIS y las migraciones. **Siete servicios continúan en ejecución; `db-init` e `init` deben terminar con código 0.**

Si Docker devuelve `docker-credential-desktop.exe: exec format error`, el procedimiento para usar una configuración temporal destinada a imágenes públicas está en [el cierre de Fase 2](docs/phase-2.md#r-problemas-y-resoluciones).

Carga el piloto territorial/institucional, los permisos, los catálogos y su inventario piloto:

~~~bash
docker compose exec app php artisan db:seed --class=DatabaseSeeder
~~~

`DatabaseSeeder` es idempotente: conserva las identidades del piloto y sincroniza cuatro roles y 28 permisos. Los seeders forestales agregan códigos ausentes y conservan IDs, nombres, orden y desactivaciones administrativas. Crea el inventario «Inventario Forestal Institucional - Piloto SysForest», con código PILOT-SYSFOREST, sin generar árboles ficticios ni cuentas de usuario.

Para habilitar las cuatro cuentas de revisión local, ejecuta explícitamente:

~~~bash
docker compose exec app php artisan db:seed --class=DevelopmentIdentitySeeder
~~~

Este seeder acepta únicamente `local` o `testing`, exige las cuatro parejas de credenciales válidas y no restablece contraseñas de cuentas existentes. Las variables, los alcances y las condiciones de ejecución están en [cuentas de desarrollo](docs/development-accounts.md).

Abre [iniciar sesión](http://localhost:8080/login), utiliza las credenciales de tu configuración local y entra a [organizaciones](http://localhost:8080/organizations). La creación de cuentas nuevas se realiza mediante invitaciones; no hay registro público abierto.

## Identidad y permisos

La identidad HTTP se valida mediante una cookie JWT **HttpOnly**. El token de acceso dura 10 minutos por defecto. La renovación utiliza un token opaco rotatorio, cuyo hash se persiste en PostgreSQL; la sesión autenticada tiene un vencimiento absoluto de 7 días por defecto. Renovar no prolonga ese vencimiento. Cerrar sesión, revocar una sesión, desactivar una cuenta o restablecer su contraseña afectan a las sesiones según las reglas documentadas en [autenticación](docs/authentication.md) y [recuperación](docs/account-recovery.md).

Hay dos estados distintos:

- La sesión de navegación de Laravel se guarda en **Redis**: mantiene CSRF y la selección `active_organization_id`. No acredita por sí sola la identidad del usuario.
- La tabla **`auth_sessions` de PostgreSQL** mantiene la sesión autenticada, su propietario, expiración y revocación. Cada petición protegida comprueba el JWT y el estado actual de esa sesión y de la cuenta.

El acceso institucional exige además correo verificado y permisos vigentes. Una membresía sin rol no habilita el contexto de trabajo.

| Rol | Ámbito | Capacidades iniciales |
| --- | --- | --- |
| `SUPER_ADMIN` | Nacional | Permisos explícitos del catálogo; no requiere membresías artificiales. |
| `DEPARTMENT_ADMIN` | Departamento asignado | Consulta de organizaciones y territorios de su ámbito. |
| `ORGANIZATION_ADMIN` | Organización de su membresía | Gestión institucional, miembros, invitaciones y roles organizacionales autorizados. |
| `OFFICIAL` | Organización de su membresía | Consulta institucional y territorial; sin administración de miembros. |

La [matriz de autorización](docs/authorization.md#roles-y-permisos) y su [extensión forestal de ocho permisos](docs/multitenancy.md#operaciones-forestales-de-fase-6) es la referencia de cada operación. El perfil y las sesiones propias se protegen por identidad y propiedad. La administración global de usuarios exige autorización nacional.

Al seleccionar una organización, `TenantContextService` determina el modo `organization`, `national` o `department_read`. Reconsulta cuenta, concesión, organización y membresía cuando corresponde. Un administrador nacional puede seleccionar una organización sin pertenecer a ella, y un administrador departamental conserva únicamente lectura. Las operaciones sobre recursos concretos también exigen que su organización coincida con la seleccionada.

## Catálogos forestales nacionales

Abre [Catálogos forestales](http://localhost:8080/admin/catalogs) después de iniciar sesión. No necesitas seleccionar una organización. SUPER_ADMIN puede crear valores, editar etiquetas, activar/desactivar y ordenar; DEPARTMENT_ADMIN, ORGANIZATION_ADMIN y OFFICIAL tienen consulta desde una asignación vigente. Los catálogos no llevan `organization_id`.

Hay 16 tablas nacionales: emplazamientos, dos dimensiones de origen, hábitos, madurez, familias/especies/nombres comunes, categorías/condiciones, evidencia, mantenimiento, motivos de anulación, fauna y reglas versionadas con bandas. Se sembraron 23 condiciones físico-mecánicas y 42 fitosanitarias; las dos columnas de fenología del mismo bloque se documentan para una fase posterior.

La regla `EXCEL_V1` conserva intervalos exactos de 5 cm para DAP y 10 m para altura total, con cuatro estados Fustal. Sus parámetros y bandas están sellados: modificar una fórmula requiere otra versión, sin reescribir resultados históricos. BCMath trabaja con cadenas decimales y precisión de seis decimales; no redondea entradas antes de clasificar. La consulta de reglas de catálogo permanece disponible. Las inspecciones usan CAP/π con precisión interna ampliada, sin clasificar a partir del DAP redondeado; consulta el contrato de dasonomía.

No hay rutas de borrado de catálogos. Los selectores de nuevos registros excluyen valores inactivos y las referencias anteriores se conservan. Los cambios se auditan con actor y sin tenant. Familias, especies y categorías tienen selectores Select2 locales; el servidor valida todas las relaciones.

## Inventarios y árboles

Después de iniciar sesión, selecciona una organización y abre [Inventarios](http://localhost:8080/inventories) o [Árboles](http://localhost:8080/trees). ORGANIZATION_ADMIN administra campañas, árboles, anulaciones y restauraciones; OFFICIAL crea y edita individuos de campañas activas; DEPARTMENT_ADMIN consulta el ámbito seleccionado de su departamento. SUPER_ADMIN mantiene selección institucional para operar, sin membresías artificiales.

El mismo árbol participa en varias campañas mediante `inventory_trees`. El código se genera en backend usando una secuencia por organización. La ficha permite identidad/taxonomía, descripción de ubicación, coordenadas decimales o DMS, GPS opcional, estados e historiales. Leaflet previsualiza la validación del servidor; Select2 busca especies por nombres científicos y comunes. Un guardado obsoleto devuelve 409, conservando la edición anterior.

«Anular» identifica un registro erróneo y conserva UUID/código; muerto, talado o retirado siguen siendo estados de individuos reales. La restauración conserva identidad y vínculos. Los formularios de identidad conservan esta separación; desde la ficha se inicia una inspección con sus mediciones y observaciones históricas.

Consulta [dominio del árbol](docs/tree-domain.md), [georreferenciación](docs/georeferencing.md) y [Fase 6](docs/phase-6.md). Si el datum no fue informado, se conserva NULL y se declara la referencia de trabajo del mapa; no se infiere el datum del GPS. Esta fase no transforma coordenadas de otros datums.

## Servicios

| Servicio | Función y estado esperado |
| --- | --- |
| `postgres` | PostgreSQL/PostGIS persistente; continuo. |
| `redis` | Caché, navegación y cola; continuo. |
| `mailpit` | Recibe correos locales y permite revisarlos; continuo. |
| `db-init` | Prepara roles, bases, PostGIS y permisos; termina en 0. |
| `init` | Migra ambas bases con el rol migrador; termina en 0. |
| `app` | PHP-FPM con el rol de aplicación; continuo. |
| `queue` | Procesa trabajos Redis con `queue:work`; continuo. |
| `scheduler` | Ejecuta `schedule:work`; continuo. |
| `nginx` | Sirve `public` y envía PHP a FPM; continuo. |

Los contenedores PHP incorporan un proceso init para gestionar señales. Es independiente del servicio `init` que ejecuta migraciones.

Los puertos publicados quedan limitados al equipo local:

| Acceso | Dirección |
| --- | --- |
| Aplicación e infraestructura | [localhost:8080](http://localhost:8080) |
| Buzón Mailpit | [localhost:8025](http://localhost:8025) |

PostgreSQL, Redis y PHP-FPM no publican puertos. El correo usa `mailpit:1025` dentro de Compose; SMTP no está publicado en el equipo. Mailpit captura los mensajes de revisión local. La configuración de entrega de correo y HTTPS de producción se resolverá antes del despliegue.

## Configuración y secretos

`.env.example` contiene nombres de variables y valores públicos de referencia. Configura los valores privados exclusivamente en `.env`.

| Variables | Uso |
| --- | --- |
| `APP_URL`, `APP_PORT` | URL y puerto local; deben coincidir. `APP_URL` también interviene en los enlaces y el emisor JWT. |
| `APP_UID`, `APP_GID` | Identidad Linux del usuario; se prepara con el usuario actual. |
| `APP_KEY`, `JWT_SECRET` | Claves privadas de Laravel y firma JWT; no se publican ni se reutilizan como contraseñas. |
| `JWT_TTL`, `AUTH_SESSION_TTL_MINUTES`, `JWT_AUDIENCE` | Vigencia de acceso, sesión autenticada y audiencia esperada. |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | Almacenamiento y duración de la navegación Laravel; distintos de la vigencia de `auth_sessions`. |
| `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` | Política de cookies. El HTTP local usa `false` y `lax`; HTTPS requiere cookies seguras. |
| `DB_DATABASE`, `DB_TEST_DATABASE` | Bases separadas: `sysforest` y `sysforest_testing` inicialmente. |
| `DB_USERNAME`, `DB_PASSWORD` | Cuenta de ejecución de Laravel, inicialmente `sysforest_app`. |
| `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD` | Cuenta de cambios de esquema, inicialmente `sysforest_migrator`. |
| `POSTGRES_USER`, `POSTGRES_PASSWORD` | Cuenta administrativa de preparación, inicialmente `sysforest_admin`. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | `smtp`, `mailpit` y `1025` en el entorno local. |
| `DEV_*_EMAIL`, `DEV_*_PASSWORD` | Cuatro cuentas opcionales, creadas solo al ejecutar el seeder de desarrollo. |
| `AUTH_*_RATE_LIMIT` | Límites de intentos de inicio, renovación, recuperación e invitaciones. |

Los valores de las contraseñas y claves se representan como campos vacíos o marcadores en los ejemplos; nunca como credenciales de acceso compartidas. No imprimas `docker compose config` sin `--quiet` en registros destinados a revisión.

Laravel, el worker y el scheduler utilizan la cuenta de ejecución sin permisos de cambios de esquema. Compose monta los secretos privilegiados solo en los inicializadores que los necesitan; sus variables quedan vacías en los servicios PHP habituales.

`setup-env` no rota valores existentes. Cambiar una contraseña en `.env` no modifica automáticamente la credencial de una base ya inicializada ni la de una cuenta ya creada.

## Comprobaciones y pruebas

Comprueba la disponibilidad de Laravel, PostgreSQL/PostGIS y Redis:

~~~bash
curl --fail --silent --show-error http://localhost:8080/health/ready
~~~

La respuesta esperada es HTTP 200 con `{"status":"ok"}`; una dependencia no disponible produce HTTP 503 con un cuerpo genérico. `/up` mantiene la comprobación de vida de Laravel. La [página inicial](http://localhost:8080/) muestra el estado de infraestructura sin requerir inicio de sesión.

Despacha una tarea real de cola y consulta la programación:

~~~bash
docker compose exec app php artisan sysforest:probe-queue
docker compose exec app php artisan schedule:list
~~~

La página inicial permite revisar la fecha de procesamiento de cola. El scheduler actualiza su comprobación cada minuto. Ver un proceso activo no demuestra que haya completado una tarea.

Ejecuta la suite completa de forma serial sobre la base de pruebas:

~~~bash
docker compose exec app php artisan test --fail-on-warning
~~~

`phpunit.xml` fija `APP_ENV=testing`, `DB_DATABASE=sysforest_testing` y activa `SYSFOREST_RUN_INTEGRATION=1`. Se utiliza PostgreSQL/PostGIS real. `PostgresTestCase` verifica entorno, driver y nombre de base antes de abrir transacciones y revierte los fixtures. No ejecutes suites concurrentes contra la misma base de pruebas.

Si modificas `DB_TEST_DATABASE`, actualiza también las entradas `DB_DATABASE` de `phpunit.xml` para que apunten a esa base separada. `composer test` limpia previamente la caché de configuración. Los resultados consolidados y las limitaciones se registran en [Fase 7](docs/phase-7.md); estos comandos describen el procedimiento, no un resultado ejecutado.

Revisa el formato:

~~~bash
docker compose exec app composer lint
~~~

Para aplicar PSR-12 con un montaje escribible desde la raíz del proyecto:

~~~bash
docker run --rm --user "$(id -u):$(id -g)" --volume "$PWD:/var/www/html" --workdir /var/www/html --entrypoint composer sysforest-app:local format
~~~

## Operación y persistencia

Consulta estado, inicialización, procesos y migraciones:

~~~bash
docker compose ps -a
docker compose logs --tail=100 db-init init
docker compose logs --tail=100 app queue scheduler nginx mailpit
docker compose exec app php artisan migrate:status
~~~

Aplica migraciones incrementales con el rol autorizado:

~~~bash
docker compose run --rm init
~~~

El servicio `app` no tiene permisos para modificar el esquema. Cuando cambien el Dockerfile, las dependencias o la configuración de Compose, reconstruye y aplica los servicios:

~~~bash
docker compose build
docker compose up -d
~~~

Tras cambios en clases que utiliza el worker:

~~~bash
docker compose exec app php artisan queue:restart
~~~

Para detener conservando datos, utiliza `docker compose stop`. `docker compose down` retira contenedores y red, manteniendo los volúmenes. Vuelve a arrancar con `docker compose up -d`. No uses `down -v` para una detención normal: elimina los volúmenes.

| Volumen | Contenido |
| --- | --- |
| `postgres_data` | Datos persistentes de PostgreSQL. |
| `redis_data` | Persistencia AOF de Redis. |
| `storage_data` | Almacenamiento de Laravel y archivos locales de la aplicación. |
| `mailpit_data` | Buzón local de revisión. |

Los volúmenes no sustituyen las copias de seguridad. Los enlaces de los correos locales son credenciales de acción: conserva el buzón de revisión privado. Las carpetas de código se montan como solo lectura en los servicios PHP; se editan en el proyecto WSL. Nginx expone únicamente `public`, no la raíz ni `.env`.

## Documentación

| Documento | Contenido |
| --- | --- |
| [Fase 6](docs/phase-6.md) | Reporte histórico A–AJ, pruebas, rollback y estado de servicios. |
| [Fase 7](docs/phase-7.md) | Reporte A–AN de inspecciones, verificaciones y alcance actual. |
| [Inspecciones](docs/inspections.md) | Historia, condiciones, fenología, fauna, filtros, auditoría y permisos. |
| [Dasonomía](docs/dendrometry.md) | Fórmulas, unidades, precisiones y versiones de clasificación. |
| [Dominio forestal](docs/tree-domain.md) | Identidad, campañas, estados, códigos y anulación. |
| [Georreferenciación](docs/georeferencing.md) | PostGIS, Garmin, DMS y metadatos. |
| [Fase 5](docs/phase-5.md) | Evidencia histórica de catálogos aprobados. |
| [Catálogos forestales](docs/forestry-catalogs.md) | Valores, códigos, fuentes y discrepancias. |
| [94 campos Excel](docs/excel-field-mapping.md) | Trazabilidad completa para las fases posteriores. |
| [Fase 4](docs/phase-4.md) | Evidencia histórica de autenticación y autorización. |
| [Autenticación](docs/authentication.md) | JWT, cookies, renovación y sesiones autenticadas. |
| [Autorización](docs/authorization.md) | Cuatro roles; base de permisos y extensión forestal en multitenancy. |
| [Recuperación e invitaciones](docs/account-recovery.md) | Verificación de correo, contraseñas, invitaciones y entrega local. |
| [Cuentas de desarrollo](docs/development-accounts.md) | Variables y seeder explícito de revisión local. |
| [Multi-tenancy](docs/multitenancy.md) | Selección, revalidación, consultas y aislamiento entre organizaciones. |
| [Arquitectura](docs/architecture.md) y [modelo de datos](docs/database.md) | Diseño conceptual aprobado y módulos previstos. |
| [Fase 2](docs/phase-2.md) y [Fase 3](docs/phase-3.md) | Evidencia histórica de infraestructura y fundamento institucional. |

El flujo del código es `Controller → Service → Repository → Model`. Blade recibe datos preparados; no realiza consultas ni contiene reglas de negocio. Las capacidades actuales llegan hasta Fase 7; los diagramas conceptuales de módulos posteriores no implican que estén implementados.

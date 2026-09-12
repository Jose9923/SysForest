# SYSFOREST — Fase 3

SYSFOREST es un sistema de inventario y gestión forestal institucional. La Fase 3 incorpora territorios, organizaciones, membresías múltiples y contexto tenant explícito sobre la infraestructura aprobada de Fase 2. El proyecto definitivo está exclusivamente en `/home/josev/SYSFOREST`, dentro de Ubuntu-24.04 WSL.

La interfaz utiliza Blade y Select2 para seleccionar municipios. El piloto corresponde a la Institucion Educativa INEM Luis Lopez de Mesa, en Villavicencio, Meta, Colombia. El login definitivo, JWT, roles y módulos forestales corresponden a fases posteriores. El acceso actual es una herramienta técnica temporal de consola local.

El [reporte A–T de Fase 3](docs/phase-3.md) documenta los resultados ejecutados. La [estrategia multi-tenant](docs/multitenancy.md) define los contratos de aislamiento para los módulos posteriores.

## Entorno local

El proyecto se ejecuta desde **Ubuntu 24.04 en WSL**, en `/home/josev/SYSFOREST`. Ejecuta los comandos de este documento en la terminal Ubuntu, no en PowerShell.

El entorno previsto utiliza Docker Engine 29.2.1 y Docker Compose 5.0.2. Docker debe estar disponible para el usuario de Ubuntu. No hace falta instalar PHP, Composer, PostgreSQL ni Redis en Windows: se ejecutan dentro de los contenedores. El script de configuración requiere Bash y OpenSSL.

| Componente | Configuración |
| --- | --- |
| Backend | Laravel 13 y PHP 8.4 FPM |
| Web | Nginx 1.28 |
| Base de datos | PostgreSQL 17 con PostGIS 3.5 |
| Caché y colas | Redis 7.4 con persistencia AOF |
| Interfaz | Blade, renderizado en servidor |

## Revisar la Fase 3

Con el entorno existente iniciado, aplica las migraciones incrementales mediante el rol migrador:

```bash
docker compose run --rm init
```

Carga o actualiza el piloto sin duplicarlo:

```bash
docker compose exec app php artisan db:seed --force
```

Para la primera revisión local, la creación de la cuenta técnica y su membresía se solicitan explícitamente:

```bash
docker compose exec app php artisan sysforest:local-access phase3.local@sysforest.test --organization=INEM-VVC --create-user --join
```

En este equipo esa cuenta técnica ya existe. Para emitir nuevos enlaces basta:

```bash
docker compose exec app php artisan sysforest:local-access phase3.local@sysforest.test --organization=INEM-VVC
```

Abre en el navegador la URL que devuelve Artisan. Solo funciona en `APP_ENV=local`, es de un solo uso, caduca en 10 minutos y habilita una sesión de revisión de 30 minutos. No se imprime ni comparte una contraseña. El enlace concede capacidad técnica de creación y gestión sobre organizaciones con membresía verificada; no concede acceso nacional/departamental. En producción este mecanismo está deshabilitado.

Desde [gestión institucional](http://localhost:8080/organizations) puedes consultar el piloto, abrir su detalle, crear/editar organizaciones y consultar territorios. La página [de acceso](http://localhost:8080/phase-3/access) explica el acceso temporal. Las consultas administrativas permanecen denegadas hasta la Fase 4.

Los seeders no crean usuarios automáticamente, no inventan polígonos y no crean árboles. El estado de infraestructura continúa disponible en [la página inicial](http://localhost:8080/).

## Instalación inicial

Entra en el directorio del proyecto:

```bash
cd /home/josev/SYSFOREST
```

Comprueba que Docker y Compose estén disponibles:

```bash
docker version
```

```bash
docker compose version
```

Prepara la configuración local y sus secretos:

```bash
bash bin/setup-env
```

El script prepara `.env` a partir de `.env.example`, genera las claves locales necesarias y conserva una configuración existente. `.env` es la fuente de las credenciales. Si hay archivos de secretos de una preparación anterior, el script conserva sus valores al migrarlos; los archivos anteriores quedan únicamente como respaldo local.

Valida Compose sin imprimir la configuración resuelta ni las credenciales:

```bash
docker compose config --quiet
```

Construye la imagen de aplicación:

```bash
docker compose build
```

Si este equipo devuelve `docker-credential-desktop.exe: exec format error`, usa una configuración temporal solo para imágenes públicas. El helper de Windows no se modifica; la incidencia y el procedimiento están registrados en [el cierre de Fase 2](docs/phase-2.md#r-problemas-y-resoluciones). Con las imágenes ya construidas, `docker compose up -d` funciona sin esta configuración temporal.

La construcción instala las versiones fijadas en `composer.lock`. Este archivo pertenece al proyecto y debe existir en una instalación normal. El target `base` del Dockerfile se utiliza una sola vez durante la preparación inicial del proyecto para obtener ese lock; no se repite ese procedimiento al instalar ni al arrancar SYSFOREST.

Inicia todos los servicios:

```bash
docker compose up -d
```

Abre [SYSFOREST local](http://localhost:8080). El primer arranque requiere que finalicen la preparación de PostgreSQL y las migraciones. Comprueba su estado con:

```bash
docker compose ps -a
```

Los servicios `db-init` e `init` deben terminar con código `0`; permanecen detenidos después de completar su trabajo. Los otros seis servicios deben continuar en ejecución. No confundas una finalización correcta de los inicializadores con una caída del sistema.

## Servicios y orden de inicio

| Servicio | Función |
| --- | --- |
| `postgres` | Base persistente; utiliza una cuenta administrativa para preparación controlada. |
| `redis` | Caché, sesiones y cola, sin puerto publicado en el equipo. |
| `db-init` | Prepara roles, bases operativa/de pruebas, PostGIS y permisos después de que PostgreSQL esté disponible. |
| `init` | Ejecuta migraciones en ambas bases con el rol migrador; espera a `db-init` y Redis. |
| `app` | Ejecuta PHP-FPM con el rol de aplicación después de las migraciones. |
| `queue` | Procesa trabajos Redis con `queue:work`. |
| `scheduler` | Ejecuta las tareas programadas mediante `schedule:work`. |
| `nginx` | Sirve exclusivamente `public` y envía las peticiones PHP a FPM. |

Los contenedores PHP incorporan un proceso init para gestionar señales y procesos hijos. Es diferente del servicio llamado `init`, cuya función es ejecutar migraciones.

Solo Nginx publica un puerto: **`127.0.0.1:8080`** por defecto. PostgreSQL, Redis y FPM se comunican dentro de la red de Compose y no publican puertos. Esta configuración está destinada al trabajo local; el despliegue en Hostinger se abordará posteriormente.

## Configuración y credenciales

Los ejemplos y comentarios de las variables están en `.env.example`. Edita `.env` para ajustar la instalación local.

| Variables | Uso |
| --- | --- |
| `APP_URL`, `APP_PORT` | URL y puerto local. Si cambias el puerto, actualiza ambos y las URL usadas en tus comprobaciones. |
| `APP_UID`, `APP_GID` | Identidad Linux del usuario que construye/ejecuta la aplicación; `setup-env` utiliza el usuario actual. |
| `APP_KEY` | Clave de Laravel, generada durante la preparación local. |
| `DB_DATABASE`, `DB_TEST_DATABASE` | Bases separadas; valores iniciales `sysforest` y `sysforest_testing`. |
| `DB_USERNAME`, `DB_PASSWORD` | Cuenta de ejecución de Laravel, inicialmente `sysforest_app`. |
| `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD` | Cuenta para cambios de esquema, inicialmente `sysforest_migrator`. |
| `POSTGRES_USER`, `POSTGRES_PASSWORD` | Cuenta administrativa de preparación, inicialmente `sysforest_admin`. |
| `MAIL_MAILER` | `log` en esta fase; no se envían correos reales. |

Las cuentas administrativa, migradora y de ejecución tienen funciones separadas. Laravel, el worker y el scheduler usan la cuenta de ejecución, sin permisos para crear bases, roles ni cambiar el esquema. El inicializador de migraciones recibe la cuenta migradora. La preparación de PostGIS y roles corresponde a `db-init`.

Compose toma los secretos privilegiados desde variables de `.env` y los monta como archivos de Docker secrets únicamente en los servicios que los necesitan. Las variables privilegiadas quedan vacías en los procesos habituales de aplicación, cola y scheduler. No se incluyen secretos reales en Git ni en la imagen de aplicación. `.env` y cualquier respaldo local anterior de secretos deben conservarse junto con los datos persistentes y mantenerse privados.

`setup-env` no es un procedimiento de rotación de contraseñas: ejecutarlo nuevamente conserva la configuración. Cambiar el contenido de `.env` no equivale por sí solo a cambiar las credenciales de una base ya inicializada.

## Comprobaciones

Estos comandos describen el procedimiento de verificación; no constituyen un registro de resultados ejecutados.

Los resultados de infraestructura inicial están en [el reporte de Fase 2](docs/phase-2.md); la verificación actual y el fundamento institucional están en [el reporte de Fase 3](docs/phase-3.md).

Comprueba la disponibilidad HTTP de Laravel, PostgreSQL/PostGIS y Redis:

```bash
curl --fail --silent --show-error http://localhost:8080/health/ready
```

La respuesta esperada con las dependencias disponibles es `{"status":"ok"}` y HTTP 200. Si falla alguna dependencia, el endpoint devuelve HTTP 503 sin publicar detalles internos de la conexión.

Despacha un trabajo real para el worker:

```bash
docker compose exec app php artisan sysforest:probe-queue
```

Consulta la página inicial para comprobar que cambió la fecha de procesamiento de la cola. El scheduler ejecuta una comprobación cada minuto; su fecha debe actualizarse por ejecución programada. La presencia de un proceso activo no sustituye la comprobación de que procesó una tarea.

Consulta la programación:

```bash
docker compose exec app php artisan schedule:list
```

## Pruebas y estilo

Ejecuta la suite completa, incluidas las pruebas de PostgreSQL/PostGIS y Redis:

```bash
docker compose exec app php artisan test
```

Para fallar también ante advertencias:

```bash
docker compose exec app php artisan test --fail-on-warning
```

`phpunit.xml` fija `APP_ENV=testing` y `DB_DATABASE=sysforest_testing` tanto en `env` como en `server`, con `force=true`, para evitar que las variables heredadas de Docker prevalezcan. Activa además `SYSFOREST_RUN_INTEGRATION=1`. No se utiliza SQLite.

`tests/PostgresTestCase.php` verifica entorno, driver y nombre de base antes de iniciar transacciones. Los fixtures se revierten al terminar cada prueba. Si cambias `DB_TEST_DATABASE`, actualiza también las dos entradas `DB_DATABASE` de `phpunit.xml` para que coincidan con esa base separada. No sustituyas la base de pruebas por la operativa. `composer test` sigue disponible y limpia previamente la caché de configuración.

Comprueba el formato PSR-12 sin modificar los archivos:

```bash
docker compose exec app composer lint
```

Para aplicar el formato desde la imagen local con un montaje escribible:

```bash
docker run --rm --user "$(id -u):$(id -g)" --volume "$PWD:/var/www/html" --workdir /var/www/html --entrypoint composer sysforest-app:local format
```

Los PHPDoc del proyecto se redactan en español. El código montado en los servicios es de solo lectura; las modificaciones se realizan en los archivos del proyecto, desde el editor del host.

## Operaciones frecuentes

Ver los últimos mensajes de inicialización:

```bash
docker compose logs --tail=100 db-init init
```

Ver los últimos mensajes de aplicación y procesos:

```bash
docker compose logs --tail=100 app queue scheduler nginx
```

Consultar las migraciones:

```bash
docker compose exec app php artisan migrate:status
```

Ejecutar las migraciones con el rol autorizado, por ejemplo tras añadir una migración en una fase posterior:

```bash
docker compose run --rm init
```

No ejecutes las migraciones desde `app`: ese servicio utiliza intencionalmente el rol sin permisos de cambio de esquema.

Cuando cambien el Dockerfile o las dependencias bloqueadas, reconstruye la imagen:

```bash
docker compose build
```

Aplica la imagen y configuración de servicios:

```bash
docker compose up -d
```

Después de modificar clases utilizadas por el worker, solicita su reinicio ordenado:

```bash
docker compose exec app php artisan queue:restart
```

Detén los servicios conservando los contenedores y datos:

```bash
docker compose stop
```

También puedes retirar los contenedores y la red conservando los volúmenes:

```bash
docker compose down
```

Para volver a iniciar, basta con `docker compose up -d`.

## Persistencia y ubicación de archivos

| Volumen | Contenido |
| --- | --- |
| `postgres_data` | Datos y configuración persistente de PostgreSQL. |
| `redis_data` | Persistencia AOF de Redis. |
| `storage_data` | Archivos de Laravel y almacenamiento local de la aplicación. |

No añadas `-v` a `docker compose down` para una detención normal: esa opción elimina los volúmenes del proyecto. Los volúmenes persistentes no sustituyen una copia de seguridad. La estrategia de respaldo y restauración para un entorno de producción se definirá antes del despliegue.

Las carpetas de código y configuración se montan en los contenedores PHP como solo lectura. Las dependencias Composer se instalan dentro de la imagen. Nginx no expone la raíz del proyecto ni `.env`; su raíz pública es `/var/www/html/public`.

## Estructura y documentación

| Ruta | Contenido |
| --- | --- |
| `app/` | Controladores, servicios, repositorios y componentes PHP de la fase. |
| `resources/views/` | Vistas Blade. |
| `routes/` | Rutas web y programación de tareas. |
| `database/` | Migraciones y estructura de datos disponible en cada fase. |
| `tests/` | Pruebas unitarias, de funcionalidad y de infraestructura. |
| `docker/` | Nginx, PHP, inicialización PostgreSQL y scripts de contenedores. |
| `bin/setup-env` | Preparación idempotente de variables y secretos locales. |

La [arquitectura](docs/architecture.md) documenta MVC, el flujo Controller → Service → Repository → Model y los reportes mediante Blade. El [modelo de datos](docs/database.md) contiene el ERD conceptual y las relaciones previstas. Ambos documentos conservan el diseño conceptual aprobado. Las capacidades implementadas actualmente se describen en [Fase 3](docs/phase-3.md) y [multi-tenancy](docs/multitenancy.md).

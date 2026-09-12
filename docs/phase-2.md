# Cierre de Fase 2 — SYSFOREST

**Fecha:** 12 de septiembre de 2026. **Proyecto:** `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL.
**Resultado técnico:** entorno construido y verificado; queda pendiente la revisión del usuario. No se inició la Fase 3.

Se ejecutó realmente `docker compose up -d` desde el proyecto, sin prefijos de configuración alternativos. Los seis servicios permanentes quedaron saludables y los inicializadores terminaron con código 0. Se comprobó también un arranque posterior conservando bases y volúmenes.

Las [salidas de diagnóstico](phase-2-evidence.txt) acompañan este reporte. Los resultados son una captura de esta sesión, no una garantía de disponibilidad cuando Docker Desktop esté detenido. Los comandos siguientes se ejecutan en Ubuntu desde el directorio del proyecto.

## A. Archivos creados/modificados

Se conservó el proyecto existente, su repositorio y sus claves locales. No se volvió a ejecutar la inicialización de Laravel ni las Fases 0 y 1.

| Grupo | Archivos relevantes |
| --- | --- |
| Entorno | `Dockerfile`, `docker-compose.yml`, `.dockerignore`, `.env.example`, `.gitignore`, `bin/setup-env` |
| Dependencias y estilo | `composer.json`, **`composer.lock`**, `pint.json`, `phpunit.xml` |
| PHP-FPM | `docker/php/php.ini`, `fpm.conf`, `entrypoint.sh`, `migrate.sh`, `fpm-healthcheck.sh`, `process-healthcheck.php` |
| Web | `docker/nginx/default.conf`, `routes/web.php`, `resources/views/system-status.blade.php`, `public/images/sysforest-logo.jpeg` |
| PostgreSQL | `docker/postgres/bootstrap.sh`, `roles.sql`, `database.sql`, `permissions.sql` |
| MVC e infraestructura | `app/Http/Controllers/SystemStatusController.php`, `app/Services/SystemStatusService.php`, `app/Repositories/InfrastructureRepository.php`, `app/DTOs/InfrastructureStatus.php` |
| Procesos | `app/Jobs/InfrastructureProbeJob.php`, `app/Console/Commands/ProbeQueueCommand.php`, `ProbeSchedulerCommand.php`, `routes/console.php` |
| Laravel | `config/database.php`, `cache.php`, `queue.php`, `session.php`; comentarios en español en los otros archivos de configuración, modelo, proveedor, factoría, migraciones y seeder del esqueleto |
| Pruebas | `tests/Unit/SystemStatusServiceTest.php`, `tests/Feature/SystemStatusTest.php`, `InfrastructureProbeCommandTest.php`, `InfrastructureIntegrationTest.php`; pruebas iniciales conservadas |
| Documentación | `README.md`, `AGENTS.md`, `CLAUDE.md`, `docs/architecture.md`, `docs/database.md`, este reporte y sus evidencias |

`vendor/` quedó instalado localmente e incluido mediante Composer en la imagen; se excluye de Git. `.env` conserva las claves originales y permisos 600. Los archivos anteriores de `docker/secrets/` se conservan como respaldos privados, excluidos de Git y del build.

El repositorio está en `codex/phase-2`, todavía sin commit. Los archivos aparecen como nuevos; no hay cambios previos del usuario sobrescritos.

## B. Árbol relevante y estructura Docker

```text
/home/josev/SYSFOREST/
├── Dockerfile                 # base PHP 8.4 y runtime Laravel
├── docker-compose.yml
├── .env.example
├── composer.json / composer.lock / pint.json / phpunit.xml
├── bin/setup-env
├── app/
│   ├── Http/Controllers/SystemStatusController.php
│   ├── Services/SystemStatusService.php
│   ├── Repositories/InfrastructureRepository.php
│   ├── DTOs/InfrastructureStatus.php
│   ├── Console/Commands/
│   └── Jobs/InfrastructureProbeJob.php
├── config/
├── database/migrations/
├── docker/
│   ├── nginx/default.conf
│   ├── php/
│   └── postgres/
├── resources/views/system-status.blade.php
├── public/images/sysforest-logo.jpeg
├── routes/web.php / console.php
├── tests/Unit/ y tests/Feature/
└── docs/
    ├── architecture.md
    ├── database.md
    ├── phase-2.md
    └── phase-2-evidence.txt
```

Orden de inicio: PostgreSQL saludable → `db-init` (roles, bases, extensión) → `init` (migraciones con cuenta migradora; también espera a Redis) → app/queue/scheduler → Nginx cuando FPM responde.

Red bridge: `sysforest_backend`. Volúmenes persistentes: `sysforest_postgres_data`, `sysforest_redis_data`, `sysforest_storage_data`. PHP se ejecuta con UID/GID 1000 y recibe las fuentes como montajes de solo lectura. La imagen incluye dependencias de desarrollo para las pruebas de esta fase.

## C. docker compose ps

Salida real:

```text
NAME                    IMAGE                    COMMAND                  SERVICE     CREATED         STATUS                    PORTS
sysforest-app-1         sysforest-app:local      "sysforest-entrypoin…"   app         3 minutes ago   Up 3 minutes (healthy)    9000/tcp
sysforest-nginx-1       nginx:1.28-alpine        "/docker-entrypoint.…"   nginx       26 hours ago    Up 6 minutes (healthy)    127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5   "docker-entrypoint.s…"   postgres    26 hours ago    Up 11 minutes (healthy)   5432/tcp
sysforest-queue-1       sysforest-app:local      "sysforest-entrypoin…"   queue       3 minutes ago   Up 3 minutes (healthy)    9000/tcp
sysforest-redis-1       redis:7.4-alpine         "docker-entrypoint.s…"   redis       26 hours ago    Up 11 minutes (healthy)   6379/tcp
sysforest-scheduler-1   sysforest-app:local      "sysforest-entrypoin…"   scheduler   3 minutes ago   Up 3 minutes (healthy)    9000/tcp
```

`docker compose ps -a` confirmó además:

| Contenedor temporal | Estado |
| --- | --- |
| `sysforest-db-init-1` | Exited (0) |
| `sysforest-init-1` | Exited (0) |

No son servicios caídos: terminan después de preparar la infraestructura.

## D. Puertos expuestos al host

Solo **`127.0.0.1:8080 → nginx:80/tcp`**. Acceso local: [SYSFOREST](http://localhost:8080).

## E. Puertos internos

| Servicio | Puerto |
| --- | --- |
| Nginx | 80/tcp |
| PHP-FPM app | 9000/tcp |
| PostgreSQL | 5432/tcp |
| Redis | 6379/tcp |

Queue y scheduler heredan el metadato `EXPOSE 9000` de la imagen PHP, que aparece en `ps`; no ejecutan FPM ni publican ese puerto. Todos se comunican por DNS de Compose, sin `network_mode: host`.

## F. PHP

`docker compose exec -T app php -v`: **PHP 8.4.25**, Zend Engine/OPcache 8.4.25. FPM real confirmado mediante `docker compose top app` y su comprobación FastCGI.

## G. Laravel

`docker compose exec -T app php artisan --version`: **Laravel Framework 13.31.0**.
`composer validate --strict`: correcto. `composer.lock` fija 106 paquetes; no se vuelve a resolver al arrancar.

## H. PostgreSQL

Consulta ejecutada:

```sql
SELECT version();
```

Resultado: **PostgreSQL 17.5 (Debian 17.5-1.pgdg110+1), 64-bit**.

Roles comprobados: `sysforest_admin` es administrador; `sysforest_migrator` y `sysforest_app` no son superusuarios, no crean roles/bases y no tienen BYPASSRLS. El usuario de aplicación tiene CRUD sobre tablas Laravel y **no** tiene CREATE en el esquema público.

La base `sysforest_testing` es exclusiva para pruebas. Es una segunda base técnica dentro del mismo servicio; el diseño funcional conserva una sola base operativa multi-tenant.

## I. PostGIS

```sql
SELECT PostGIS_Version();
SELECT extname FROM pg_extension WHERE extname = 'postgis';
SELECT PostGIS_Full_Version();
```

Resultados: `3.5 USE_GEOS=1 USE_PROJ=1 USE_STATS=1`, extensión `postgis`, versión completa **3.5.2 dea6d0a**.

La prueba de integración utilizó Laravel → PostgreSQL → PostGIS para crear un punto SRID 4326 y obtuvo `POINT(1 2)`. En los siguientes arranques PostgreSQL informó `extension "postgis" already exists, skipping`, confirmando inicialización idempotente.

## J. Redis

`redis-cli ping`: **PONG**. Servidor **7.4.11**; cliente PHP `phpredis`.
La prueba real de Laravel escribió una clave temporal, leyó su valor y la eliminó. Redis almacena también las marcas verificadas del worker y scheduler. Persistencia AOF activada.

## K. Worker real

Proceso observado, con usuario 1000:

```bash
php artisan queue:work redis --sleep=2 --tries=3 --timeout=60 --max-time=3600
```

Se ejecutó desde app:

```bash
docker compose exec -T app php artisan sysforest:probe-queue
```

El contenedor queue registró `InfrastructureProbeJob RUNNING` y `DONE`, con finalización en **3.46 ms**. Redis guardó **2026-09-12T14:08:51+00:00**. La marca se escribe al procesar el job, no al despacharlo.

`retry_after=120` supera el timeout del worker de 60 segundos; `after_commit=true` evita publicar antes de confirmar una transacción.

## L. Scheduler real

Proceso observado:

```bash
php artisan schedule:work
```

`schedule:list` mostró `* * * * * php artisan sysforest:probe-scheduler`. La tarea utiliza `withoutOverlapping()`. Los logs confirmaron ejecuciones automáticas a las 14:07, 14:08, 14:09 y 14:10 UTC del 12 de septiembre. Marca verificada: **2026-09-12T14:10:00+00:00**. No se utilizó una ejecución manual como sustituto de esta evidencia.

## M. HTTP y Nginx

Petición real desde Windows a `http://localhost:8080/`: **HTTP 200**, `text/html; charset=utf-8`, título `SYSFOREST · Infraestructura local`, logo y estados de PostgreSQL/PostGIS/Redis.

`GET /health/ready`: **HTTP 200**, `{"status":"ok"}`, `Cache-Control: no-store, private`.

`nginx -T` confirmó `root /var/www/html/public` y `SCRIPT_FILENAME /var/www/html/public/index.php`. `nginx -t` pasó. Solo `index.php` puede ejecutarse por FastCGI.

| Solicitud | Resultado |
| --- | --- |
| `/.env` | 403 |
| `/composer.json` | 404 |
| `/artisan` | 404 |
| `/docker-compose.yml` | 404 |
| `/test.PHP` | 404 |

La vista recibe el DTO del Controller → Service → Repository. No contiene consultas. Las comprobaciones técnicas no inventan un Model de negocio; los futuros módulos mantienen el flujo hasta sus modelos Eloquent.

Nginx utiliza un upstream con resolución periódica de `app:9000` mediante el DNS interno de Docker (`127.0.0.11`). Así puede seguir un cambio de dirección del contenedor de aplicación. La dirección corresponde al resolver de Docker, no a una IP fija de PostgreSQL, Redis ni PHP. La directiva [resolve de Nginx](https://nginx.org/en/docs/http/ngx_http_upstream_module.html#resolve) es compatible con la imagen 1.28 utilizada.

## N. Migraciones

`docker compose exec -T app php artisan migrate:status`:

```text
0001_01_01_000000_create_users_table   [1] Ran
0001_01_01_000001_create_cache_table   [1] Ran
0001_01_01_000002_create_jobs_table    [1] Ran
```

Las migraciones se ejecutaron con el rol migrador en ambas bases. Un nuevo arranque informó `Nothing to migrate` en ambas. Son tablas iniciales del esqueleto Laravel; no existen todavía tablas forestales ni módulos de la Fase 3.

## O. Pruebas

Comando final, con integración real activada:

```bash
docker compose exec -T -e SYSFOREST_RUN_INTEGRATION=1 -e DB_DATABASE=sysforest_testing app php artisan test --display-warnings --fail-on-warning
```

**13 pruebas aprobadas, 55 aserciones, 0 advertencias, ninguna omitida. Duración: 0.47 s. Exit 0.**

Incluye errores parciales de dependencias, HTTP/Blade, respuesta 503 sin filtrar datos de conexión, comandos/jobs y conexiones reales a PostgreSQL/PostGIS y Redis. Las pruebas unitarias usan array/sync; las dos de integración exigen `SYSFOREST_RUN_INTEGRATION=1`.

## P. PSR-12 y sintaxis

Se aplicó `composer format` usando PHP 8.4 y un montaje escribible del proyecto. Después:

```bash
docker compose exec -T app composer lint
```

**PASS: 38 archivos PHP, preset PSR-12.** Comentarios y PHPDoc propios en español, sin comentar cada línea. Identificadores técnicos en inglés.

También pasaron `composer validate --strict`, `docker compose config --quiet`, `nginx -t` y la comprobación individual de sintaxis de los scripts Bash/sh.

## Q. Variables relevantes sin secretos

```dotenv
APP_NAME=SYSFOREST
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost:8080
APP_PORT=8080
APP_LOCALE=es
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=sysforest
DB_TEST_DATABASE=sysforest_testing
DB_USERNAME=sysforest_app
DB_MIGRATOR_USERNAME=sysforest_migrator
POSTGRES_USER=sysforest_admin
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=120
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
MAIL_MAILER=log
```

`APP_KEY`, `DB_PASSWORD`, `DB_MIGRATOR_PASSWORD` y `POSTGRES_PASSWORD` se generan/conservan en `.env`; permanecen vacíos en el ejemplo y no se muestran aquí. Compose suministra las claves privilegiadas mediante [secrets de entorno](https://docs.docker.com/reference/compose-file/secrets/). App/queue/scheduler anulan las variables privilegiadas heredadas de `env_file`.

No se implementaron JWT ni secretos JWT. Las cookies Secure se habilitarán con HTTPS; HTTP está limitado al equipo local en esta fase.

La revisión de seguridad examinó 89 archivos no ignorados sin encontrar contraseñas locales. Git excluye `.env` y sus respaldos. Se comprobó el contenido nominal de `/run/secrets`, sin leer valores en la salida: `init` recibe solo `migrator_password`, `db-init` ambos y `postgres` solo `postgres_password`; app, queue, scheduler, Nginx y Redis no reciben secretos privilegiados.

## R. Problemas y resoluciones

1. **Docker Desktop no arrancaba por sockets Windows residuales.** Se conservaron copias de los directorios temporales `Docker/run` y `docker-secrets-engine`, y se inició Desktop de nuevo. No se eliminaron imágenes, volúmenes ni datos del proyecto. En esta continuación no se modificó `/etc/wsl.conf` ni la configuración global de Docker.
2. **Helper de credenciales Windows no ejecutable desde WSL.** Se descargaron imágenes públicas con una configuración temporal. Compose/Buildx requirió propagar `DOCKER_CONFIG` al subproceso. Las credenciales globales se conservaron. Procedimiento reproducible únicamente cuando aparezca ese error:

   ```bash
   docker_public_config="$(mktemp -d /tmp/sysforest-docker-public.XXXXXX)"
   ```
   ```bash
   DOCKER_CONFIG="$docker_public_config" docker compose build app
   ```
   ```bash
   DOCKER_CONFIG="$docker_public_config" docker compose pull postgres redis nginx
   ```
   ```bash
   docker compose up -d
   ```

   La configuración temporal no contiene credenciales y no sirve para registros privados. No se requiere al arrancar con las imágenes locales preparadas.
3. **Contenedores detenidos tras reinicio del equipo.** Docker reportó bind mounts WSL temporales de archivos aún no disponibles. Ejecutar `docker compose up -d` desde Ubuntu restableció los servicios, sin recrear el proyecto ni modificar el host.
4. **Nueve advertencias de PHPUnit por `.env` ausente en la imagen.** Se añadió un archivo vacío durante el build; la configuración real sigue inyectada por Compose. No se copiaron secretos a la imagen, no se modificó vendor ni se silenciaron warnings. La suite final pasa con `--fail-on-warning`.
5. **Defaults del esqueleto y permisos de copia.** Se establecieron pgsql/Redis por defecto y DNS internos, se normalizaron permisos 755/644 conservando `.env` en 600, y se aplicó PSR-12.
6. **Fuente de credenciales.** Se trasladaron los valores administrativos existentes a `.env` sin regenerarlos; los servicios operativos reciben solo la cuenta de aplicación. Los archivos anteriores permanecen como respaldo privado.
7. **Interrupción previa de herramientas.** La revisión automática de aprobación alcanzó su límite de uso; los comandos rechazados no se ejecutaron. Se retomó con autorización del usuario y se completaron las verificaciones pendientes.
8. Se intentó una opción CLI `--without-tty` que la versión instalada no admite; se retiró esa opción. Las pruebas finales usaron los argumentos registrados en la sección O.

## S. Deuda y límite de alcance

No quedan comprobaciones obligatorias de Fase 2 pendientes. La revisión y aceptación corresponden al usuario.

- El helper global de credenciales de WSL sigue siendo una incidencia del equipo para futuros builds/pulls: existe el procedimiento temporal verificado, sin alterar ajustes globales.
- Los servicios requieren Docker Desktop y la distribución WSL disponibles; tras reiniciar el equipo puede ser necesario ejecutar el comando de arranque desde Ubuntu.
- Es un entorno local, sin HTTPS ni despliegue. Endurecimiento de producción, revisión/actualización de imágenes con versiones fijadas, respaldo/restauración y CI quedan para su etapa.
- Git está inicializado, sin commit ni remoto configurado en esta sesión.
- Multi-tenancy operativo, territorios, autenticación JWT, autorización, módulos forestales y reportes se mantienen en sus fases posteriores. No se avanzó a la Fase 3.

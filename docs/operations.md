# Operación local y preparación de despliegue

El entorno entregado es Docker Compose dentro de Ubuntu-24.04 WSL, en `/home/josev/SYSFOREST`. Todos los comandos de esta guía se ejecutan desde esa carpeta en una terminal Ubuntu. No se ha realizado un despliegue externo.

## Preparar y arrancar

Docker y Docker Compose deben estar disponibles para el usuario de Ubuntu. `bin/setup-env` necesita Bash y OpenSSL. PHP y Composer se ejecutan en la imagen PHP 8.4; no se necesitan instalaciones PHP en Windows o Ubuntu.

~~~bash
cd /home/josev/SYSFOREST
docker version
docker compose version
bash bin/setup-env
docker compose config --quiet
docker compose build
docker compose up -d
docker compose ps -a
~~~

`setup-env` crea `.env` desde `.env.example` si falta y completa los secretos locales ausentes. Conserva los valores existentes. No imprimas `.env` ni la salida completa de `docker compose config` en registros compartidos: utiliza `--quiet` para validar.

La imagen instala el `composer.lock` existente y conserva dependencias de desarrollo, incluido Pint. Las suites automatizadas se retiraron por solicitud del usuario; no se incluyen sus archivos ni configuración en la imagen. `db-init` crea o comprueba los roles, bases, extensión PostGIS y privilegios; `init` aplica migraciones incrementales a las bases operativa y de pruebas. No ejecutan seeders automáticamente.

Para una instalación local nueva, sigue los seeders explícitos del [README](../README.md#inicio-local). `AuthorizationSeeder` sincroniza los roles y permisos definidos en el checkout, incluidos riesgo y carbono; no es un comando de diagnóstico. `DevelopmentIdentitySeeder` prepara cuentas solamente en local/testing y llama al seeder de contraseñas de desarrollo autorizado. No ejecutarlo en producción ni como una comprobación de salud; consulta [cuentas de desarrollo](development-accounts.md).

## Servicios y persistencia

Los nombres siguientes corresponden al proyecto Compose predeterminado `sysforest`:

| Servicio | Contenedor | Estado esperado y función |
| --- | --- | --- |
| `postgres` | `sysforest-postgres-1` | Saludable; PostgreSQL 17/PostGIS 3.5 persistente. |
| `mongodb` | `sysforest-mongodb-1` | Saludable; MongoDB 8.0 para consulta privada de auditoría. |
| `redis` | `sysforest-redis-1` | Saludable; Redis 7.4 para caché, navegación y cola. |
| `mailpit` | `sysforest-mailpit-1` | Saludable; SMTP y buzón de revisión local. |
| `app` | `sysforest-app-1` | Saludable; PHP-FPM y Laravel. |
| `queue` | `sysforest-queue-1` | Saludable; `queue:work redis`, tres intentos, timeout de 60 s. |
| `scheduler` | `sysforest-scheduler-1` | Saludable; `schedule:work`. |
| `nginx` | `sysforest-nginx-1` | Saludable; sirve exclusivamente `/public`. |
| `db-init` | `sysforest-db-init-1` | Terminado con código 0; preparación privilegiada de PostgreSQL. |
| `init` | `sysforest-init-1` | Terminado con código 0; migraciones con el rol migrador. |

Solo Nginx (`127.0.0.1:8080`, configurable con `APP_PORT`) y Mailpit (`127.0.0.1:8025`) publican puertos. PostgreSQL, MongoDB, Redis, FPM y SMTP (`mailpit:1025`) permanecen internos. El correo local se captura en Mailpit; sus enlaces de verificación, invitación y recuperación son credenciales de acción y el buzón debe permanecer privado.

| Volumen lógico de Compose | Contenido |
| --- | --- |
| `postgres_data` | Base operativa, base de pruebas, historiales y recibos de auditoría. |
| `mongo_data` | Proyección privada de auditoría, usuarios e índices de MongoDB. |
| `redis_data` | Persistencia AOF de Redis. |
| `storage_data` | Archivos de aplicación, evidencias privadas, derivados y temporales. |
| `mailpit_data` | Mensajes del buzón local. |

Los nombres físicos de los volúmenes llevan el prefijo del proyecto Compose. Los directorios de código se montan como solo lectura en los servicios PHP; los cambios se realizan en la fuente WSL.

## Diagnóstico sin modificar datos forestales

~~~bash
docker compose ps -a
docker compose logs --tail=100 db-init init
docker compose logs --tail=100 app queue scheduler nginx
curl --fail --silent --show-error http://localhost:8080/health/ready
docker compose exec -T app php -v
docker compose exec -T app php artisan --version
docker compose exec -T app php artisan migrate:status
docker compose exec -T redis redis-cli ping
docker compose exec -T app php artisan schedule:list
~~~

`/health/ready` comprueba Laravel, PostgreSQL/PostGIS y Redis: responde 200 con `{"status":"ok"}` o 503 genérico. `/up` comprueba vida de Laravel. Una conexión Redis correcta devuelve `PONG`.

Para comprobar que el worker **procesa** una tarea técnica, despáchala y revisa su fecha en [Estado local](http://localhost:8080/):

~~~bash
docker compose exec -T app php artisan sysforest:probe-queue
~~~

El scheduler ejecuta `sysforest:probe-scheduler` cada minuto y `sysforest:cleanup-report-downloads` cada hora, ambos sin solapamiento. Con `AUDIT_STORE=mongodb`, también entrega la auditoría cada diez segundos mediante `sysforest:audit-sync`, sin solapamiento. Espera al siguiente minuto y comprueba que avanza su fecha en Estado local. El healthcheck de un proceso activo no acredita por sí solo el procesamiento de una tarea.

Los registros pueden contener datos de operación: revisa y redacta información sensible antes de compartirlos. Nginx omite query strings y referers para evitar registrar tokens de enlaces. Los errores HTTP generales no muestran SQL ni rutas internas, incluso si se activara debug; la configuración de referencia mantiene `APP_DEBUG=false`.

## Auditoría en MongoDB

La auditoría se confirma primero junto con la operación en PostgreSQL. MongoDB recibe después una proyección privada y sirve `/audit` cuando está al día. Si la entrega está pendiente, falta cobertura por conteo o MongoDB falla, la pantalla consulta el historial SQL y muestra un aviso. `/health/ready` conserva sus comprobaciones de PostgreSQL/PostGIS y Redis: no acredita la disponibilidad de MongoDB ni detiene operaciones por una caída del almacenamiento de consulta.

`bash bin/setup-env` añade las variables nuevas que falten y genera `MONGO_ROOT_PASSWORD` y `AUDIT_MONGO_PASSWORD` sin mostrar sus valores. Conserva secretos y decisiones existentes, incluido un `AUDIT_STORE` explícito. La configuración de referencia habilita `AUDIT_STORE=mongodb`; `AUDIT_STORE=postgres` suspende la entrega y mantiene la consulta SQL. Los valores de conexión están en `config/audit.php` y `.env.example`. No incluyas contraseñas en argumentos de terminal ni copies URI con credenciales a registros compartidos.

El usuario `sysforest_audit_app` tiene únicamente `find` e `insert` sobre `sysforest_audit.activity_logs`. La aplicación no puede actualizar ni borrar documentos. MongoDB recibe su credencial administrativa mediante un secreto; Compose anula las variables administrativas heredadas por los servicios PHP. El servicio permanece en la red interna, sin puerto publicado.

El inicializador `docker/mongo/bootstrap.js` configura usuarios, validador estricto e índices solo cuando MongoDB arranca sobre un volumen nuevo. Una modificación de usuario, contraseña, validador o índice sobre un volumen existente requiere actualizar la base con credenciales administrativas y coordinar sus consumidores; editar `.env`, reconstruir la imagen o reiniciar el contenedor no ejecuta de nuevo ese inicializador. `setup-env` no rota claves ya creadas.

Después de reconstruir la imagen y aplicar las migraciones incrementales mediante `init`, se puede iniciar y comprobar la entrega:

~~~bash
docker compose up -d mongodb app queue scheduler nginx
docker compose exec -T app php artisan sysforest:audit-status
docker compose exec -T app php artisan sysforest:audit-sync
docker compose exec -T app php artisan sysforest:audit-status
~~~

El primer `audit-status` puede terminar con código distinto de cero mientras existan pendientes. Muestra el destino, el número de entradas canónicas de origen, pendientes, última confirmación UTC, disponibilidad y conteo canónico de MongoDB; no imprime documentos ni secretos. Cuando MongoDB está habilitado, devuelve error si no está disponible, los conteos no coinciden o quedan pendientes. Un estado correcto no constituye una verificación criptográfica de todo el contenido.

La entrega normal incluye el historial anterior que aún no tenga recibo. Procesa hasta diez lotes por llamada; `AUDIT_BATCH_SIZE` permite configurar entre 1 y 1000 entradas por lote, con 500 por defecto. Se puede ampliar una ejecución con `--max-batches=100`, o dejar que el scheduler continúe en las siguientes ejecuciones. La tabla `audit_deliveries` guarda únicamente la fuente, UUID y fecha de confirmación. El reintento verifica la identidad `source:id` y el hash del documento antes de confirmar: no crea otra copia del mismo registro.

Si la conexión falla, los registros SQL y los pendientes se conservan. Comprueba el estado del servicio y la configuración sin publicar sus secretos, restablece MongoDB y vuelve a ejecutar `audit-sync` o espera al scheduler. Los avisos de entrega omiten excepciones del driver porque pueden contener credenciales o datos privados.

### Recuperación del almacenamiento de consulta

Un MongoDB restaurado puede carecer de documentos que PostgreSQL ya marcó como entregados. En ese caso no basta con la sincronización normal: ejecuta la reproducción del historial. Conserva PostgreSQL y sus recibos; no vacíes tablas ni elimines triggers para forzar otra entrega.

1. Recupera MongoDB desde una copia compatible o prepara un volumen nuevo mediante el inicializador, conservando las credenciales de los consumidores. Este paso debe realizarse en el destino de recuperación previsto, sin borrar volúmenes operativos.
2. Con PostgreSQL disponible y `AUDIT_STORE=mongodb`, ejecuta la reproducción completa con un límite suficiente:

   ~~~bash
   docker compose exec -T app php artisan sysforest:audit-sync --replay --max-batches=100000
   docker compose exec -T app php artisan sysforest:audit-status
   ~~~

3. Comprueba que no quedan pendientes, que los conteos canónicos coinciden y que `/audit` permite consultar el detalle y el alcance institucional esperado. Conserva el resultado en el informe de recuperación.

`--replay` empieza desde el principio en cada llamada y verifica también registros con recibo. Tiene un máximo de 100000 lotes; si alcanza el límite antes de terminar, devuelve error e informa que la recuperación es parcial. Aumentar el límite vuelve a recorrer lo anterior de forma idempotente. Un conflicto de hash requiere investigar la versión de la proyección y el documento de destino; no se resuelve sobrescribiendo automáticamente el historial. No hay TTL ni borrado automático de auditoría.

La descripción del modelo, fuentes y controles está en [Auditoría privada](audit.md). Este procedimiento documenta la recuperación prevista; su existencia no acredita un ensayo integral de restauración.

## Actualización institucional de carbono en segundo plano

La migración aditiva `000031_create_carbon_refresh_runs` incorpora el registro durable del trabajo. `POST /carbon/refresh` solicita la ejecución y devuelve la página; no recalcula todo el historial en la petición HTTP. El panel **Carbono almacenado** y `GET /carbon/refresh/status` muestran estado, visitas procesadas/total, estimaciones agregadas y motivo de terminación, con autenticación web, permiso `inspections.view`, alcance institucional y respuesta `no-store`. Sin esta migración, el panel informa que la actualización está pendiente y deshabilita la solicitud.

El trabajo `RefreshCarbonEstimates` usa Redis, requiere el servicio `queue` y procesa lotes de hasta 25 visitas o 15 segundos con transacciones breves por visita. Tiene tres intentos automáticos. El scheduler ejecuta `sysforest:dispatch-carbon-refreshes` cada minuto para recuperar despachos pendientes. Mantener ambos servicios activos; un contenedor saludable no acredita que el cursor avance.

Solo existe una tarea `queued` o `running` por organización. Se revalida el permiso `carbon.manage` del solicitante en cada visita. Si pierde acceso, la tarea termina `cancelled`; si se publica otra referencia, se cancela para evitar continuar silenciosamente con una configuración distinta. Un administrador autorizado puede solicitar una nueva ejecución. `completed` y `failed` son los otros estados finales. Los resultados confirmados se conservan y una repetición no duplica instantáneas con las mismas entradas.

Durante una ejecución parcial pueden coexistir referencias históricas y nuevas. Revisar tarea, cobertura y procedencia antes de distribuir un informe. Ante un fallo, comprobar logs de `queue`/`scheduler`, conectividad Redis y mensaje del panel antes de volver a solicitar. No borrar instantáneas ni ejecuciones para forzar la recuperación. No activar el backfill CLI a la vez que un trabajo web activo.

Los perfiles bibliográficos provisionales no acreditan validación profesional local. El reporte y sus descargas distinguen estimaciones provisionales y revisadas y advierten sobre la cobertura; revisar estos datos junto con el total. No completar mediciones ni fustes para aumentar cobertura. Consultar [carbono](carbon-estimation.md) y [riesgo](risk-management.md) para los contratos y límites; los [manuales](manuales.md) describen los procedimientos.

El 26 de octubre de 2026 se aplicó la migración 31 en el piloto local (batch 20), se solicitó `queue:restart` y se reinició el scheduler. Esa activación no ejecutó un recálculo sobre visitas operativas. Otras instalaciones requieren su propio despliegue y comprobación; no se acredita un ensayo integral de recuperación.

## Actualizaciones y cambios de configuración

Antes de actualizar un entorno con datos, conserva una copia recuperable y revisa las migraciones y la compatibilidad de la versión de código. No uses `migrate:fresh`, `db:wipe` ni borrado de volúmenes para actualizar.

Con PostgreSQL y Redis ya disponibles, aplica migraciones pendientes mediante el servicio autorizado:

~~~bash
docker compose run --rm --no-deps init
~~~

Ese servicio migra **ambas bases**, y termina con error si son la misma. `app`, `queue` y `scheduler` usan `sysforest_app`, sin permisos para alterar esquema. El rol migrador y la credencial administrativa solo se montan en los inicializadores que los necesitan; no se debe intentar resolver un fallo de migración elevando los permisos del rol de ejecución.

Si cambian Dockerfile, dependencias, scripts incorporados a la imagen o configuración de servicios:

~~~bash
docker compose build
docker compose up -d
~~~

Después de modificar clases usadas por el worker, solicita su reinicio al terminar el trabajo actual:

~~~bash
docker compose exec -T app php artisan queue:restart
~~~

Si cambió la programación o código mantenido por procesos largos, reinicia los servicios afectados durante una ventana apropiada y vuelve a comprobar su salud:

~~~bash
docker compose restart app queue scheduler
docker compose ps -a
~~~

Los CSS y JavaScript propios, incluidas las dependencias de módulos ES, se sirven con `Cache-Control: no-cache` para revalidar los archivos sin hash en su URL. Se conservan ETag, Last-Modified y las cabeceras de seguridad. Esta política no cambia el caché del portal, reportes ni bibliotecas de `vendor`. Una pestaña con una copia anterior todavía fresca puede necesitar una recarga forzada inicial; en las siguientes cargas el navegador ya recibe la política nueva. La directiva utilizada es [`expires -1` de Nginx](https://nginx.org/en/docs/http/ngx_http_headers_module.html#expires).

Si cambias `.env`, `docker compose up -d` recrea los servicios cuya configuración cambió; un simple `restart` no recarga las variables inyectadas por Compose. `setup-env` no rota secretos existentes. Cambiar una contraseña en `.env` tampoco equivale a cambiarla en una base o cuenta ya creada; planifica y verifica cada rotación con sus consumidores.

`APP_URL` debe corresponder al acceso real y se usa como emisor JWT salvo `JWT_ISSUER` explícito. El entorno HTTP local usa `SESSION_SECURE_COOKIE=false`; un futuro entorno HTTPS debe utilizar cookies seguras. Los límites de evidencias, importaciones y reportes están en `.env.example` y sus respectivos archivos `config/*.php`. Aumentar filas de importación o tamaños requiere volver a medir tiempos, memoria y límites PHP/Nginx; no basta aumentar una sola variable.

## Detener, respaldar y recuperar

~~~bash
docker compose stop
docker compose up -d
~~~

`stop` conserva contenedores y volúmenes. `docker compose down` elimina contenedores y red, pero conserva los volúmenes; `down -v` elimina también los volúmenes y no es una detención normal.

Los volúmenes no son una estrategia de copias. Una recuperación completa debe coordinar la base PostgreSQL con los archivos privados de `storage_data`, la versión del código/`composer.lock` y las claves necesarias para leer los datos cifrados. Incluye MongoDB y sus credenciales en el plan de respaldo o reserva el tiempo y espacio para reconstruir su proyección desde el historial SQL mediante `audit-sync --replay`; conservar únicamente MongoDB no recupera los datos de negocio ni las relaciones de autorización. Copiar solamente el directorio del repositorio no conserva árboles, auditoría ni fotografías. Las copias deben preservar permisos y quedar fuera de `public`.

Antes de considerar listo un procedimiento de respaldo, prueba su restauración en un entorno separado, con SMTP externo deshabilitado y sin reutilizar puertos ni volúmenes operativos. Comprueba PostGIS, migraciones, cuentas/ámbitos, integridad de auditoría y referencias a fotos. Define también el tratamiento de colas para evitar reprocesar trabajos restaurados. No se entrega aquí un script de respaldo automático ni se afirma haber realizado un ensayo integral de recuperación.

Los historiales protegidos y las migraciones de auditoría no permiten asumir que un rollback de esquema sea seguro. Una reversión requiere analizar compatibilidad de código/datos o recuperar una copia verificada; no se deben desactivar triggers para eliminar historial.

## Preparación de una futura puesta en producción

El Compose actual sirve para operación local y pruebas: incluye la base de pruebas, herramientas de desarrollo, Mailpit y publicación en loopback. Abrir sus puertos al exterior no completa un despliegue de producción. Antes de ese trabajo deben resolverse y verificarse:

- Dominio, HTTPS, terminación TLS/proxy y configuración coherente de URL, emisor JWT y cookies seguras.
- Imagen y dependencias de ejecución, configuración `production`, exclusión de herramientas y cuentas de revisión, gestión y rotación de secretos.
- Correo real de verificación/invitación/recuperación y límites de entrega; Mailpit permanece destinado a revisión local.
- Almacenamiento privado persistente, copias coordinadas, ensayo de restauración y objetivos de recuperación.
- Monitoreo de disponibilidad, fallos de cola, scheduler, espacio y registros; política de retención para auditoría y datos privados.
- Revisión de carga y tiempos con el volumen previsto, límites de importación/exportación, publicación y autorización institucional.

Estas decisiones requieren un entorno y requisitos de operación concretos. La documentación no crea infraestructura externa ni acredita pruebas que todavía no se han ejecutado.

## Problemas frecuentes

| Síntoma | Comprobación |
| --- | --- |
| `db-init` o `init` termina con error | Revisa únicamente sus logs y variables requeridas sin publicar secretos. No sustituyas migraciones incrementales por un borrado de base. |
| `docker-credential-desktop.exe: exec format error` | Consulta la resolución local documentada en [Fase 2](phase-2.md#r-problemas-y-resoluciones). |
| Nginx devuelve 502/503 | Comprueba `app`, PostgreSQL y Redis y revisa `/health/ready`. |
| Auditoría muestra un aviso de respaldo | Ejecuta `sysforest:audit-status`; comprueba pendientes, cobertura y disponibilidad de MongoDB. Después de restaurarlo, usa `audit-sync --replay`. |
| Carbono aparece sin estimaciones después de actualizar el código | Un `git pull` no carga referencias ni recalcula datos. Revisa la existencia de perfiles por institución; la precarga bibliográfica documentada usa `CarbonSpeciesReferenceSeeder`. Después utiliza **Actualizar estimaciones** o el CLI institucional autorizado. No ejecutes `DatabaseSeeder` para resolver únicamente carbono. La [activación local del 26/10/2026](carbon-estimation.md#activación-solicitada-de-cálculos-faltantes--26102026) registra 701 resultados provisionales y sus pendientes. |
| El worker está saludable pero la sonda no avanza | Revisa logs de `queue`, conexión Redis y despacho; distingue proceso activo de trabajo completado. |
| El correo no aparece en una bandeja externa | La configuración local lo entrega a Mailpit en el puerto 8025. |
| Cambié una clave de desarrollo y no puedo entrar | Revisa si se ejecutó el seeder de desarrollo autorizado, que restablece las cuentas locales y revoca sus sesiones al cambiar la clave. Para operación normal utiliza recuperación o cambio de contraseña. |

La [guía de verificación](testing.md) documenta `bash bin/check`: PSR-12 y sintaxis PHP/JavaScript, sin ejecutar suites ni consultar bases de datos. La revisión funcional es manual; no introduzcas datos forestales ficticios en la base operativa para completar capturas.

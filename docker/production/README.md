# Despliegue de producción

`compose.production.yml` es independiente de `docker-compose.yml`. No incluye Mailpit, bases de pruebas ni carga automática de catálogos, usuarios o inventarios. La imagen usa el target `production` del Dockerfile y conserva el target `runtime` para desarrollo. Los contenedores PHP ejecutan como `sysforest`; solo los inicializadores reciben la credencial administrativa o migradora. PostgreSQL, MongoDB, Redis y PHP-FPM no publican puertos.

## Preparación

La configuración privada debe quedar fuera del repositorio. Para una instalación nueva:

```bash
bash bin/production-env /etc/sysforest/sysforest.env https://sysforest.example.com
```

El generador exige una URL HTTPS, crea claves aleatorias sin mostrarlas y rechaza reemplazar archivos existentes. No rota bases ni usuarios. En una migración, conservar `APP_KEY` cuando deban recuperarse sesiones o payloads cifrados; sustituirla requiere un análisis específico. El secreto JWT puede renovarse coordinando la revocación de sesiones. Los archivos privados no se incorporan a la imagen. Revisar SMTP en el archivo generado: el destino inicial impide entregas y debe configurarse antes de habilitar invitaciones o recuperación de contraseña. No utilizar el driver `log` para mensajes con enlaces de acción.

Todos los comandos siguientes deben ejecutarse desde el directorio de la versión que se desplegará:

```bash
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml config --quiet
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml build app
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml up -d postgres mongodb redis
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml --profile maintenance run --rm --no-deps db-init
```

`db-init` prepara únicamente la base operativa, PostGIS y roles separados, reutilizando los permisos SQL vigentes. No migra esquema ni inserta entidades. Al invocarlo con credenciales cambiadas actualiza las claves PostgreSQL: no debe tratarse como una comprobación de salud. El inicializador Mongo se ejecuta solo sobre un volumen nuevo y conserva el rol de aplicación limitado a `find` e `insert`.

## Activación y rotación de SMTP

La instalación de `sysforest.varcor.tech` utiliza Brevo desde el 4 de noviembre de 2026; el generador conserva su destino inicial sin entrega para futuras instalaciones. El estado y la evidencia están en [el informe de producción](../../docs/production-deployment-20261104.md#correo-transaccional-con-brevo).

Configurar en el env privado `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_HOST=smtp-relay.brevo.com`, `MAIL_PORT=587`, `MAIL_FROM_ADDRESS=notificaciones@varcor.tech` y `MAIL_FROM_NAME=SYSFOREST`. Añadir `MAIL_USERNAME` y `MAIL_PASSWORD` únicamente por el canal privado. Usar el login SMTP y la clave SMTP de Brevo, no la contraseña de acceso al panel ni una clave API. Con `smtp` y puerto 587, el transporte negocia STARTTLS; mantener la verificación del certificado.

**Eliminar `MAIL_URL` del entorno y de cualquier asignación Compose que lo inyecte.** No escribir `MAIL_URL=` ni `MAIL_URL=''`: el valor vacío sobreescribe el transporte en Laravel 13 y causa `Unsupported mail transport []`. `MAIL_ENCRYPTION=tls` no sustituye `MAIL_SCHEME` en esta configuración.

Tras respaldar el env privado y validar con `config --quiet`, recrear `app`, `queue` y `scheduler` con `up -d --no-deps --force-recreate`; un simple `restart` conserva las variables anteriores. Comprobar en cada servicio la construcción de `EsmtpTransport`, host/puerto/remitente y presencia de credenciales mediante una salida filtrada, sin volcar secretos. Verificar salud HTTP/HTTPS y realizar un envío autorizado; comprobar por separado aceptación del transporte y recepción del destinatario.

Las dos aplicaciones comparten el cupo de la cuenta gratuita de Brevo: 300 envíos por día en total. El archivo privado conserva la clave; revisar su vencimiento en el panel para planificar la rotación conjunta. No imprimir payloads de notificaciones ni enlaces de acción al diagnosticar colas o rechazos.

## Bibliotecas públicas y comprobación de entrega

El paquete de despliegue debe conservar completo `public/vendor`, con sus licencias y metadatos: jQuery, Select2 y su traducción al español, Leaflet y sus cinco imágenes, Supercluster, Leaflet Heat y Chart.js. La dependencia PHP de Composer está en `vendor` de la raíz; excluirla del paquete no debe excluir también `public/vendor` por compartir el nombre de carpeta.

Después de `COPY public`, el target `production` comprueba que existen y tienen contenido los 14 archivos JS, CSS y PNG requeridos. Si falta alguno, el build termina con el nombre del recurso. Esta comprobación no sustituye la del directorio publicado: Nginx monta `./public` del release como solo lectura, independientemente de la copia incluida en la imagen PHP.

Antes de publicar, comparar las rutas y SHA-256 de todos los archivos de `public/vendor` entre el origen y el paquete; después, repetir la comparación con el directorio del release que monta Nginx. `public/vendor/manifest.json` cubre parte de las dependencias; no incluye todos los recursos cartográficos y no basta por sí solo para acreditar una entrega completa.

Después de publicar, verificar por HTTPS los 14 recursos utilizados: estado **200**, `Content-Type` de CSS (`text/css`), JavaScript (`application/javascript` o `text/javascript`) y PNG (`image/png`), y SHA-256 del cuerpo igual al archivo de origen. Una página HTML devuelta por el fallback de Laravel indica que el recurso no se está sirviendo; no debe aceptarse como CSS o JavaScript ni deshabilitar `nosniff` para ocultar el problema. Las rutas PHP y los archivos privados deben seguir bloqueados.

Completar la revisión abriendo el mapa operativo, el mapa público, las gráficas y un selector buscable: comprobar las capas, los marcadores y la ausencia de errores de dependencias en la consola. La salud de `/health/ready` no acredita la presencia de estas bibliotecas.

## Compatibilidad de MongoDB con Ubuntu 26.04

Producción fija `mongo:8.0.34-noble`. El VPS reporta `7.0.0-38-generic`, aunque `/proc/version_signature` acredita el kernel upstream corregido `7.0.14`. MongoDB 8.0 interpreta la numeración Ubuntu y puede rechazar el arranque por [SERVER-131779](https://jira.mongodb.org/browse/SERVER-131779). La corrección específica figura para 8.0.37, todavía pendiente de publicación al verificar este despliegue.

Se aplica únicamente al contenedor productivo `GLIBC_TUNABLES=glibc.pthread.rseq=1`, alternativa temporal documentada por los [mantenedores de la imagen Docker](https://github.com/docker-library/mongo/discussions/748). Devuelve RSEQ a glibc y evita la ruta de cachés por CPU de TCMalloc; puede reducir rendimiento. No cambia los permisos de auditoría ni requiere borrar volúmenes. No sustituye actualizar un kernel realmente afectado. Retirar el ajuste solo después de validar la corrección en una versión 8.0 publicada y comprobar arranque, salud, estabilidad y auditoría con el kernel del servidor. Las [notas oficiales de MongoDB](https://www.mongodb.com/docs/manual/release-notes/8.0/) describen los kernels afectados y las versiones disponibles.

## Datos y migraciones

En una instalación nueva, ejecutar las migraciones explícitamente. Si se traslada una base existente, restaurar primero la copia autorizada con propietario y privilegios coherentes; luego aplicar solo las migraciones pendientes:

```bash
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml --profile maintenance run --rm --no-deps migrate
```

El servicio `migrate` afecta únicamente `DB_DATABASE`. Los servicios normales no lo ejecutan al arrancar. No ejecutar `DatabaseSeeder` para desplegar: contiene instituciones e inventarios piloto incluso fuera del entorno local. Los catálogos y la primera cuenta administrativa necesitan un procedimiento explícito adecuado a instalación nueva o traslado.

Preservar `storage_data` y sus originales, derivados y journals privados, con propietario acorde al UID/GID de la imagen. Nginx no monta ese volumen. No copiar Redis del entorno local a producción: podría reproducir tareas, notificaciones y sesiones. Al restaurar MongoDB, conservar el usuario restringido creado en el destino y los índices; la comprobación posterior debe conciliar la proyección con el historial SQL.

## Arranque y proxy HTTPS

```bash
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml up -d app queue scheduler nginx
docker compose --env-file /etc/sysforest/sysforest.env -f compose.production.yml ps
curl --fail --silent --show-error http://127.0.0.1:18080/health/ready
```

Nginx escucha exclusivamente en `127.0.0.1:18080`. Se requiere un proxy HTTPS del host, por ejemplo Caddy con `network_mode: host`, que reenvíe a esa dirección y sobrescriba `X-Real-IP` con `{remote_host}`. La red Docker tiene subred `172.28.240.0/24` y gateway `172.28.240.1`; comprobar que no se solape con otra red antes de crearla. Nginx confía únicamente en ese gateway y loopback para la dirección del cliente y declara HTTPS/puerto 443 a PHP. Mantener esta relación al modificar redes o proxy; no publicar directamente el puerto HTTP ni confiar en cabeceras arbitrarias del cliente.

La ruta `/health/ready` verifica Laravel/PostgreSQL/PostGIS/Redis. MongoDB requiere además `sysforest:audit-status`. La salud del proceso de cola o scheduler no demuestra la entrega de trabajos. Los límites iniciales están ajustados para un VPS de 16 GiB y pueden revisarse en la configuración privada; Redis utiliza `noeviction` para no expulsar tareas cuando alcance su límite. La imagen PHP conserva límites actuales de fotos e importaciones.

## Actualización y recuperación

Respaldar PostgreSQL y archivos privados de forma coordinada, además de MongoDB o de su procedimiento de reconstrucción. Probar la restauración en un destino separado. Conservar secretos necesarios fuera del repositorio y una copia de la versión desplegada. Las copias y originales no deben estar en `public` ni en capas de imagen.

Al cambiar código, construir otra imagen y recrear `app`, `queue` y `scheduler`: OPcache productivo no comprueba cambios en archivos. Si cambia `.env`, recrear los servicios para cargarlo; `restart` no lo sustituye. No usar `down -v`, `migrate:fresh` ni borrar historiales para actualizar. La rotación de secretos Mongo exige cambiar explícitamente la cuenta existente; no vuelve a ejecutarse el inicializador al reiniciar un volumen ya preparado.

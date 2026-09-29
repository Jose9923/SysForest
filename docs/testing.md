# Pruebas y verificación

Ejecuta los comandos desde `/home/josev/SYSFOREST` en Ubuntu-24.04 WSL con el entorno Compose preparado. Las pruebas usan PHP 8.4 en contenedor y PostgreSQL/PostGIS real. Los resultados históricos se conservan en los informes `phase-*.md`; una ejecución anterior no sustituye verificar la versión modificada.

## Comando de verificación

La comprobación reproducible del checkout se inicia con:

~~~bash
bash bin/check
~~~

Ejecuta PSR-12, JavaScript y la suite PHP, en ese orden. También admite `bash bin/check lint`, `bash bin/check frontend` y `bash bin/check php`. Requiere `flock`, Docker/Compose y la imagen local preparada para PHP/formato; la parte JavaScript requiere Node 18.13 o superior en Ubuntu/WSL. No instala dependencias, levanta servicios, migra bases ni limpia caché automáticamente.

Antes de PHPUnit comprueba las dos fuentes de configuración XML, ausencia de configuración Laravel cacheada, `DB_TEST_DATABASE`, conexión real a **`sysforest_testing`**, PostGIS y Redis. Este runner fija expresamente ese nombre: si se necesita otra base de pruebas, hay que revisar también su contrato; cambiar únicamente `.env` no lo adapta. La ejecución PHP rechaza advertencias de PHPUnit y suites vacías, incompletas, riesgosas u omitidas. La ejecución JavaScript rechaza fallos, suites vacías y casos omitidos o pendientes (`todo`).

Los logs, el código de salida y el JUnit PHP quedan en `storage/app/checks/<ejecución>/` del checkout, excluido de Git. Un bloqueo evita dos ejecuciones de `bin/check` sobre el mismo checkout. **No coordina invocaciones directas de PHPUnit ni otros scripts**: tampoco deben ejecutarse simultáneamente contra esa base. Las secciones siguientes explican las comprobaciones individuales y la revisión visual adicional.

## Aislamiento de datos

`phpunit.xml` fija `APP_ENV=testing`, driver `pgsql`, base `sysforest_testing` y `SYSFOREST_RUN_INTEGRATION=1`. Fija tanto variables de entorno como valores de `$_SERVER`. Sustituye correo, caché y sesiones por adaptadores de pruebas, y utiliza cola síncrona.

`PostgresTestCase` comprueba que el entorno, driver y base coincidan con `DB_TEST_DATABASE` antes de abrir transacciones. Si cambias ese nombre en `.env`, actualiza las **dos** entradas `DB_DATABASE` de `phpunit.xml`; las bases de operación y pruebas deben ser distintas. No omitas esa protección para hacer pasar una prueba.

Ejecuta las suites de base **en serie**, nunca dos procesos de suite contra la misma base. Los casos de concurrencia crean sus propias conexiones coordinadas y necesitan controlar el orden de bloqueos.

La mayoría de los fixtures se revierten con transacciones. El caso de refresh concurrente necesita una identidad confirmada compartida entre procesos: conserva `refresh-concurrency@sysforest.test` únicamente en la base de pruebas, sin roles, desactivada, sin verificar, sin sesiones y con contraseña aleatoria al finalizar. Sus eventos permanecen porque la auditoría es inmutable. No se amplían privilegios ni se desactivan triggers para limpiar fixtures.

## PHP

Una ejecución completa ordinaria:

~~~bash
docker compose exec -T app php artisan test --fail-on-warning
~~~

Si se trabajó con caché de configuración, límpiala antes para que PHPUnit pueda aplicar su configuración:

~~~bash
docker compose exec -T app php artisan config:clear
~~~

`composer test` también limpia esa caché antes de invocar Artisan. El rol `app` no puede migrar: cualquier migración pendiente se aplica previamente mediante `docker compose run --rm --no-deps init`, con las dependencias ya disponibles. No uses `migrate:fresh` para preparar la suite de este entorno persistente.

Para concentrar la comprobación en un cambio, utiliza archivos o filtros reales, por ejemplo:

~~~bash
docker compose exec -T app php artisan test tests/Feature/AuditReadTest.php --fail-on-warning
docker compose exec -T app php artisan test --filter=ImportWorkflowTest --fail-on-warning
docker compose exec -T app php artisan test --filter=CatalogReferenceConcurrencyTest --fail-on-warning
~~~

Después ejecuta la regresión apropiada al alcance. Un subconjunto aprobado no equivale a una suite completa aprobada. Registra pruebas, aserciones, fallos, errores, advertencias y omitidas; no sumes ejecuciones solapadas para producir un total de cobertura.

Para obtener JUnit de la ejecución:

~~~bash
docker compose exec -T app php artisan test --fail-on-warning --log-junit=/tmp/sysforest-tests.xml
docker compose cp app:/tmp/sysforest-tests.xml /tmp/sysforest-tests.xml
~~~

El primer `/tmp` pertenece al contenedor; el destino de `compose cp` pertenece a Ubuntu. Usa nombres distintos si necesitas conservar varias ejecuciones.

## Artefactos técnicos y visuales opcionales

Las banderas siguientes habilitan escritura de artefactos por pruebas existentes; no deben activarse en el tráfico normal ni crean datos de demostración en la base operativa:

| Variable | Artefacto |
| --- | --- |
| `SYSFOREST_MAP_EXPLAIN=1` | Planes y mediciones de consultas del mapa interno. |
| `SYSFOREST_PUBLIC_EXPLAIN=1` | Planes y mediciones de consultas públicas. |
| `SYSFOREST_MAP_VISUAL=1` | HTML de presentación del mapa interno. |
| `SYSFOREST_PUBLIC_VISUAL=1` | HTML de presentación del portal. |
| `SYSFOREST_VISUAL_SNAPSHOTS=1` | Presentación de árboles, inspecciones, evidencias y mantenimientos. |
| `SYSFOREST_REPORT_VISUAL=1` | Presentación de reportes. |
| `SYSFOREST_IMPORT_VISUAL_SNAPSHOTS=1` | Presentación de importación. |
| `SYSFOREST_AUDIT_VISUAL=1` | Presentación de auditoría. |

Por ejemplo, la regresión con evidencias de mapa y portal utilizada en el cierre de Fase 14:

~~~bash
docker compose exec -T \
  -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 \
  -e SYSFOREST_PUBLIC_EXPLAIN=1 -e SYSFOREST_PUBLIC_VISUAL=1 \
  app php artisan test --fail-on-warning --compact
~~~

Los planes espaciales se miden con datos sintéticos dentro de `sysforest_testing`, sin forzar el uso del índice cambiando el planificador. Habilitar una bandera de artefactos no convierte una medición local en una prueba de carga de producción.

## JavaScript

Las pruebas JavaScript usan el ejecutor integrado de Node y los archivos del proyecto, sin instalar dependencias npm:

~~~bash
node --version
node --test tests/Frontend/*.test.mjs
~~~

Node no viene en la imagen PHP. La suite admite Node 18.13 o superior y obtiene `File` desde `node:buffer`; no depende de que exista como global del host. Las pruebas importan `public/js` y la distribución local de Supercluster, por lo que deben conservarse sus rutas relativas. Si se utiliza una copia temporal para ejecutarlas, verifica que esos archivos sean idénticos a la fuente WSL mediante SHA-256 y registra esa condición.

En Node 18.19.1, la ejecución comprobada muestra `ExperimentalWarning` por `buffer.File`. Ese aviso del runtime permanece visible y no representa un fallo de las pruebas. El runner conserva el resultado real de Node y comprueba su resumen; no oculta el aviso ni afirma que PHPUnit y Node traten las advertencias del mismo modo.

La suite cubre estados, peticiones, capas, popups, política pública de mapas, imágenes y enlaces/selección de reportes. Las reglas de negocio forestal continúan en PHP; las pruebas de JavaScript no sustituyen autorización, transacciones ni cálculo del servidor.

## PSR-12 y dependencias

Comprueba formato sin modificar archivos:

~~~bash
docker compose exec -T app composer lint
~~~

Para aplicar el formatter al código fuente, utiliza un montaje escribible con la identidad del usuario de Ubuntu:

~~~bash
docker run --rm --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" --workdir /workspace \
  --entrypoint /var/www/html/vendor/bin/pint sysforest-app:local \
  --preset psr12 --cache-file=/tmp/sysforest-pint.cache
~~~

El comando usa las dependencias de la imagen y escribe únicamente en el checkout montado; no necesita `vendor` en Ubuntu. Los servicios habituales mantienen su montaje de código como solo lectura. Después revisa el diff y vuelve a comprobar `bash bin/check lint`, que revisa el checkout completo.

La consulta de avisos de dependencias requiere acceso a sus fuentes:

~~~bash
docker compose exec -T app composer audit --locked
~~~

Registra fecha y resultado. Un fallo de red no equivale a ausencia de avisos. La comprobación no instala versiones nuevas ni justifica modificar `composer.lock` sin revisar compatibilidad.

## Revisión responsive y accesibilidad

Verifica pantallas representativas en escritorio y móvil, incluyendo un ancho de 390 px y zoom de texto. Como mínimo, revisa navegación e inicio de sesión, listados/formularios, filtros de mapa, reportes, importaciones y auditoría, según el cambio. Comprueba foco visible, etiquetas, orden de teclado, mensajes de validación, botones alcanzables y controles Select2.

Una tabla puede desplazarse horizontalmente dentro de su contenedor; la página completa no debe ensancharse por una tabla, un enlace largo o contenido oculto. Los mapas deben conservar filtros y controles utilizables sin ocultar acciones esenciales.

Los HTML generados por pruebas sirven para validar presentación con datos sintéticos. No prueban por sí solos cookies, navegación autenticada ni transacciones. Combina esa revisión con pruebas HTTP y del Service; no insertes árboles, usuarios o publicaciones ficticias en la base operativa para rellenar capturas.

El informe de cierre debe separar: suite PHP, suite JavaScript, formatter, comprobaciones de servicios, revisión visual y limitaciones de lo que realmente se verificó.

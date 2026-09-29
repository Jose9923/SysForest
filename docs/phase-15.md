# Fase 15 — Pruebas, documentación y refinamiento responsive

**Estado: completada y verificada en el entorno local. Fecha de verificación: 2026-09-28T20:36:20+00:00.**

La Fase 15 continúa el cierre aprobado de Fase 14 en `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL, rama `codex/phase-15`. Se conserva la arquitectura Laravel 13/PHP 8.4, MVC `Controller → Service → Repository → Model`, Blade, PostgreSQL/PostGIS, Redis y Docker Compose.

La entrega facilita repetir las comprobaciones del proyecto, corrige problemas de presentación y navegación demostrados y consolida las guías vigentes. No añade módulos forestales, migraciones, permisos ni datos operativos de demostración. Los cambios recibidos de Fases 12–14 permanecen conservados; este informe describe únicamente el delta respecto al checkpoint de Fase 14.

## Verificación reproducible

Se incorpora `bin/check`:

~~~bash
cd /home/josev/SYSFOREST
bash bin/check
~~~

El modo predeterminado ejecuta PSR-12, JavaScript y PHP en serie. Los modos `lint`, `frontend` y `php` permiten comprobar cada parte. El script no instala dependencias, inicia servicios, migra bases ni limpia configuración automáticamente.

Antes de PHP comprueba la configuración XML en sus dos fuentes, la ausencia de configuración Laravel cacheada, la conexión real a `sysforest_testing`, PostGIS y Redis. Esa base está fijada expresamente: no se acepta sustituirla por una base operativa ni adaptar el nombre cambiando solo `.env`. Se rechazan advertencias de PHPUnit y suites vacías, incompletas, riesgosas u omitidas. JavaScript rechaza fallos, suites vacías y casos omitidos o pendientes.

Los resultados se guardan fuera de Git en `storage/app/checks/<ejecución>/`: logs, estado de salida y JUnit PHP. `flock` impide dos ejecuciones de este runner dentro del mismo checkout; no coordina otros scripts ni invocaciones directas de PHPUnit. Todas las suites que usan la misma base deben seguir ejecutándose en serie. Durante la regresión se intentó una segunda ejecución del runner: fue rechazada con código 1 antes de iniciar pruebas, comprobando esa exclusión.

Pint utiliza las dependencias de la imagen y analiza el checkout completo montado como solo lectura. No requiere `vendor` en Ubuntu. Las pruebas JavaScript usan Node 18.13 o superior; se verificaron con Node 18.19.1. El fixture de evidencias importa `File` desde `node:buffer`, eliminando la dependencia de un global ausente en Node 18. El aviso experimental que ese runtime muestra permanece visible y no se presenta como un fallo de las pruebas.

## Interfaz, teclado y formularios

Los cambios de presentación incluyen:

- Cabecera institucional que adapta filas y nombres largos sin ensanchar la página, y formularios de membresías que distribuyen sus campos en el espacio disponible en tabletas.
- Enlace **Saltar al contenido** al inicio del layout privado y destino principal enfocable.
- Contenedores de tablas de usuarios, sesiones, invitaciones, taxonomía y condiciones accesibles mediante teclado, con nombres de región y foco visible. Las cabeceras de catálogo declaran su ámbito de columna.
- Asociación de los errores del componente Blade `field` con su control mediante `aria-describedby` y `aria-invalid`, conservando ayudas previas, valores cero y escape de texto, sin repetir atributos.
- Helper compartido de Select2 que conserva etiquetas, ayudas, errores y obligatoriedad en el control visible y en su buscador. Las etiquetas permiten enfocar el control; se respetan IDs existentes y campos deshabilitados. Incluye el caso con `aria-label` y sin elemento `label`.
- Títulos en español de los controles de acercar y alejar en la previsualización Leaflet de ubicación.

Estas correcciones afectan la presentación y las asociaciones accesibles de los controles. Las decisiones forestales, consultas y autorización permanecen en los Services y Repositories.

### Revisión visual realizada

Los anchos se expresan como viewport / ancho útil del documento (`clientWidth`) / ancho de contenido (`scrollWidth`). Que los dos últimos coincidan confirma ausencia de desbordamiento horizontal de la página en ese caso; una tabla puede conservar desplazamiento dentro de su propio contenedor.

| Pantalla y origen | Dimensiones comprobadas | Resultado observado |
| --- | --- | --- |
| Perfil, fixture Blade | 320 / 305 / 305 | Se corrigió el ancho de contenido anterior de 425 px; Tab alcanza el enlace de salto y Enter enfoca el contenido principal. La tabla permite desplazamiento con flechas dentro de su región. |
| Membresías, fixture Blade | 768 / 753 / 753 | Se corrigió el desbordamiento anterior de 795 px y el solapamiento de cabecera; nombre largo ajustado y formulario en dos columnas con acción debajo. |
| Captura de inspección, fixture Blade | 390 / 375 / 375 y 320 / 305 / 305 | Sin desbordamiento horizontal del documento. |
| Captura de árbol, fixture Blade | 320 / 305 / 305 | Sin desbordamiento; controles Leaflet «Acercar»/«Alejar» y nombre accesible correcto en Select2. |
| Usuarios, fixture Blade | 320 / 305 / 305 | Sin desbordamiento del documento; tabla dentro de un contenedor de 219 px con contenido de 706 px y `tabindex="0"`. |
| Taxonomía, fixture Blade | 320 / 305 / 305 | Sin desbordamiento horizontal del documento. |
| Mapa público real, `/portal/map` | 390 / 375 / 375 y 1440 / 1425 / 1425 | Sin desbordamiento; etiquetas Departamento, Municipio, Organización e Inventario correctas. |

En el mapa público real también se verificó el buscador con rol `searchbox` y nombre «Departamento» mediante `aria-labelledby`; Escape cerró el desplegable. Los probes temporales de diagnóstico quedaron ausentes. Al terminar se restauró/cerró el navegador temporal y se detuvo el servidor de previsualización.

La revisión combina pantallas disponibles del entorno con fixtures de presentación. Los seis fixtures de perfil, usuarios, membresías, taxonomía, captura de árbol e inspección renderizan las vistas Blade con modelos en memoria: se comprobaron **0 consultas SQL durante el renderizado y ningún modelo persistido**. Se retiran los tokens CSRF y se anula el envío de sus formularios, manteniendo controles editables para revisión visual; no incluyen credenciales de sesión. Sirven para revisar estructura, texto largo, dimensiones, foco y controles; no acreditan un flujo autenticado de negocio.

La autorización, los filtros, las transacciones y la separación institucional se comprueban mediante las suites PHP/HTTP sobre la base aislada, no mediante esos HTML estáticos. La revisión visual y las pruebas de atributos no constituyen una certificación WCAG ni una auditoría completa con todas las combinaciones de lectores de pantalla y navegadores.

## Revalidación de CSS y JavaScript

Los archivos propios bajo `/css` y `/js` no tienen hash en la URL. Nginx les aplica `Cache-Control: no-cache` mediante `expires -1`, conservando ETag y Last-Modified para revalidación condicional. La regla mantiene antes los bloqueos de PHP y archivos ocultos y devuelve 404 para assets inexistentes. No cambia la política de rutas del portal, reportes ni bibliotecas bajo `/vendor`.

Se verificaron **7 respuestas HTTP HEAD** el 2026-09-28 a las 20:14:10 UTC, registradas en `.phase15-work/assets-results.json` del entorno de trabajo:

| Solicitud | Resultado observado |
| --- | --- |
| `/js/map/filters.js` | 200 y `Cache-Control: no-cache`. |
| Mismo JavaScript con `If-None-Match` | 304 y `Cache-Control: no-cache`. |
| `/css/institutional.css` | 200 y `Cache-Control: no-cache`. |
| Mismo CSS con `If-None-Match` | 304 y `Cache-Control: no-cache`. |
| `/js/phase15-missing.js` | 404. |
| `/js/.phase15.js` | 403. |
| `/js/phase15.php/probe.js` | 404. |

Las respuestas conservan `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` y `Referrer-Policy: no-referrer`. Una pestaña que ya conserve un archivo anterior considerado fresco puede necesitar una recarga forzada inicial; la nueva política se aplica cuando recibe la respuesta actualizada.

## Documentación consolidada

El README ahora organiza inicio, accesos y contratos vigentes sin mezclar resultados históricos con capacidades pendientes. Se crean tres guías:

- [Uso](user-guide.md): acceso, organización activa, captura, mantenimientos, importación, publicación, reportes, auditoría y conflictos de edición.
- [Operación](operations.md): servicios, persistencia, diagnóstico, migraciones con el rol autorizado, actualización, secretos y preparación de un futuro despliegue.
- [Pruebas](testing.md): runner, aislamiento, suites manuales, artefactos opcionales, formatter y revisión visual.

La [matriz de autorización](authorization.md) contiene los **49 permisos** reales del seeder: SUPER_ADMIN tiene 49, DEPARTMENT_ADMIN 10, ORGANIZATION_ADMIN 41 y OFFICIAL 22. Son concesiones iniciales; cada Service sigue exigiendo alcance, estado, propiedad y permisos específicos. Se retiraron frases obsoletas que indicaban que Fase 14 no se había iniciado. Los informes de las fases anteriores conservan sus resultados originales.

Se validaron 49 enlaces locales del parche documental y los archivos de pruebas citados. La documentación distingue expresamente las pruebas JavaScript en Node de la revisión en navegador y diferencia el tratamiento de avisos de Node del rechazo de advertencias PHPUnit.

## Resultados de pruebas

| Comprobación | Resultado |
| --- | --- |
| Suite PHP completa final | 1806 pruebas, 11138 aserciones; 0 errores, 0 fallos, 0 advertencias, 0 omitidas; 17:40.270. |
| `FieldAccessibilityTest`, comprobación focal | 3 pruebas y 39 aserciones aprobadas; renderiza Blade real sin escribir datos forestales. |
| Helper Select2 | Cinco casos nuevos integrados en la suite JavaScript: etiqueta simple, búsqueda múltiple, creación tardía del buscador, IDs/controles deshabilitados y `aria-label`. |
| Suite JavaScript en la ejecución global | 64 pruebas aprobadas; 0 omitidas y 0 pendientes. |
| PSR-12 en la ejecución global | 577 archivos aprobados. |
| Revalidación y bloqueos de assets | 7 respuestas verificadas, todas con el estado y las cabeceras previstos. |
| `git diff --check` | Aprobado, sin errores de whitespace. |
| Revisión visual | Casos de escritorio y móvil aprobados según la tabla; alcance y límites descritos arriba. |

Evidencia de la ejecución final: `storage/app/checks/20260928T201701Z-LhgdiW/`. Los resultados focales no se suman al total global, porque se solapan. El runner no activa las banderas históricas de escritura de artefactos de mapa/portal: puede haber diferencias de aserciones respecto al cierre anterior por esas comprobaciones de escritura opcionales, sin omitir casos de prueba.

### Comandos utilizados

~~~bash
bash bin/check
docker compose config --quiet
docker compose exec -T nginx nginx -t
docker compose exec -T nginx nginx -s reload
docker compose exec -T app php artisan sysforest:probe-queue
docker compose exec -T app php artisan schedule:list
docker compose ps -a
git diff --check
~~~

Los logs incluyen excepciones deliberadas de los casos de imágenes inválidas, fallo del portal y error de descarga (`PRIVATE-SIMULATED-PATH`); comprueban respuestas controladas sin exponer trazas, rutas ni secretos. No son fallos de la suite.

Las pruebas con base se ejecutaron en serie sobre `sysforest_testing`. Las protecciones de auditoría y los privilegios del rol de aplicación se conservaron. No se utilizaron `migrate:fresh`, borrado de volúmenes ni relajación de triggers para preparar la comprobación.

## Entorno al cierre

| Componente | Resultado |
| --- | --- |
| Fecha de lectura | 2026-09-28T20:36:20+00:00 |
| PHP / Laravel | 8.4.26 / 13.31.0 |
| PostgreSQL / PostGIS | 17.5 (Debian 17.5-1.pgdg110+1) / 3.5.2 |
| Redis | Conexión comprobada mediante PING. |
| Compose | Siete servicios saludables; db-init e init terminaron con código 0. |
| Datos operativos conservados | 1 árbol, 1 inspección, 112 eventos de auditoría; 0 lotes y 0 filas de importación. |
| Temporales de descarga pendientes | 0 archivos .part. |

### `docker compose ps -a`

~~~text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED       STATUS                      PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         5 hours ago   Up 56 minutes (healthy)     9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     2 weeks ago   Exited (0) 56 minutes ago   
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        5 hours ago   Exited (0) 56 minutes ago   
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     2 weeks ago   Up 6 hours (healthy)        127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       2 weeks ago   Up 6 hours (healthy)        127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    2 weeks ago   Up 6 hours (healthy)        5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       5 hours ago   Up 56 minutes (healthy)     9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       2 weeks ago   Up 6 hours (healthy)        6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   5 hours ago   Up 56 minutes (healthy)     9000/tcp
~~~

La sonda despachada al worker fue procesada a las `2026-09-28T20:21:54+00:00`. La sonda periódica del scheduler avanzó a `2026-09-28T20:36:02+00:00`. `schedule:list` confirma la sonda cada minuto y la limpieza de temporales de reportes cada hora, sin solapamiento.

Los puertos locales siguen siendo `127.0.0.1:8080` para la aplicación y `127.0.0.1:8025` para Mailpit. PostgreSQL, Redis, FPM y SMTP permanecen en la red de Compose. No se ha publicado el entorno en un servidor externo.

## Archivos y límites de la entrega

La fase contiene **9 archivos nuevos, 23 modificados y 0 eliminados**. No se modificaron dependencias, migraciones ni configuración de permisos. Los archivos de trabajo y resultados temporales permanecen fuera del código versionable.

La lista detallada se conserva en [archivos de Fase 15](phase-15-files.txt), comparada contra el checkpoint recibido de Fase 14; un `git diff` contra el último commit también contiene cambios previos de Fases 12–14 y no representa por sí solo esta entrega.

La puesta en producción continúa requiriendo definición y verificación de HTTPS, dominio, correo real, secretos, monitoreo, capacidad y recuperación. No existe en esta entrega un respaldo automatizado ni un ensayo integral de restauración verificado. El entorno local, las guías y las pruebas no se presentan como sustitutos de esos trabajos.

La rama queda en `codex/phase-15`, con los cambios conservados sin commit y sin publicar el repositorio. Los informes y fuentes anteriores se mantienen. Esta entrega completa la última fase del plan original; no se ha iniciado un despliegue externo.

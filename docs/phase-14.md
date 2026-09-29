# SYSFOREST — Fase 14: auditoría, seguridad y concurrencia

Fase autorizada expresamente por el usuario el 28 de septiembre de 2026. Fuente definitiva: `/home/josev/SYSFOREST` en Ubuntu-24.04 WSL, rama `codex/phase-14`. Se conservaron los cambios de las Fases 12 y 13 y se creó un checkpoint de 811 archivos fuente, sin secretos ni almacenamiento. No se inició la Fase 15 ni se creó un commit final.

## Resultado funcional

- Consulta privada de auditoría en `/audit`, con detalle, filtros y comparación de valores en Blade. SUPER_ADMIN tiene alcance nacional; ORGANIZATION_ADMIN consulta únicamente su institución autorizada. Los otros dos roles no reciben `audit.view`.
- Captura transaccional de actor, institución, acción, recurso, fecha, valores anteriores/posteriores y correlación generada por el servidor. La proyección excluye credenciales y atributos internos sensibles.
- Historial consolidado de siete fuentes existentes. Se conservan las revisiones y se evitan duplicados mediante su vínculo explícito al evento. Los registros previos sin comparación de valores se presentan como tales; no se fabricó un backfill.
- Auditoría inmutable en PostgreSQL, con conservación de actor e institución mediante FK RESTRICT. Protección adicional de inventarios y sus participaciones contra borrado físico o modificación histórica.
- Versiones obligatorias para organizaciones y catálogos administrativos, sumadas a las protecciones forestales existentes. Conflictos HTTP 409 sin sobrescribir cambios del primer editor.
- Bloqueos compartidos de referencias de catálogo, familia y clasificación durante la captura. Una desactivación concurrente espera a que finalice la transacción.
- Respuestas de error generales seguras incluso con `APP_DEBUG=true`, protección de cabeceras y manejo seguro de correos de tipo compuesto en el rate limiter, seguido de su rechazo mediante FormRequest con HTTP 422.

Se mantiene Controller → Service → Repository → Model/datos → Blade. No hay reglas de negocio nuevas en JavaScript ni consultas en Blade. Los contratos completos están en [auditoría](audit.md) y [concurrencia](concurrency.md).

## Migraciones y archivos

Se aplicaron correctamente en la base operativa y en `sysforest_testing`:

1. `2026_09_28_000020_extend_security_event_audit`.
2. `2026_09_28_000021_add_administrative_lock_versions`.

Las migraciones agregan columnas, índices y restricciones; no se ejecutó `migrate:fresh`, no se eliminaron datos existentes ni se deshabilitaron triggers. El inventario de archivos de esta fase se compara contra el checkpoint recibido de Fase 13, porque el diff de Git también contiene cambios aprobados aún sin commit de fases anteriores: [lista de archivos](phase-14-files.txt).

## Verificación

| Comprobación | Resultado |
| --- | --- |
| Regresión PHP completa final | **1803 pruebas / 11104 aserciones, PASS**, 1525.83 s; cero fallos, errores u omisiones. |
| Verificación focal previa de los Services ajustados | **186 pruebas / 1409 aserciones, PASS**, 102.47 s; cero fallos, errores u omisiones. |
| JavaScript existente | **59 pruebas, 59 PASS**, sin omisiones; 36 archivos de fuente/fixtures/assets verificados por SHA-256. |
| PSR-12 | **576 archivos, PASS** en la comprobación final. |
| Auditoría Composer sobre lockfile | Código 0; sin avisos de seguridad ni paquetes abandonados. |
| `git diff --check` y `docker compose config --quiet` | PASS. |
| Rutas de auditoría | Dos rutas GET/HEAD privadas: índice y detalle. |

Las primeras baterías focales incluyeron 30 pruebas de errores seguros / 561 aserciones, 21 de lectura / 60 aserciones y una repetición de integración de 141 pruebas / 627 aserciones. Estos resultados no se suman a la suite completa porque contienen casos repetidos. La evidencia JUnit y logs se conserva en el workspace Windows, `.phase11-work/sysforest-phase14-*.{xml,log}`.

La última corrección conserva motivos completos y el tipo de cambio de ubicación en la consulta unificada. También rechaza fechas con año 0000 antes de generar SQL, porque el parser PHP las acepta y PostgreSQL no. Tras verificar focalmente auditoría, ubicaciones, estados, sesiones, invitaciones y presentación, se repitió la suite completa sobre el estado final.

Las pruebas de base se ejecutaron serialmente en `sysforest_testing`. Se verificaron acceso nacional/institucional, revocación posterior a la lectura, IDOR, filtros tipados, escape de HTML, redacción de campos, paginación estable, datos anteriores/nuevos, rollback, autor original, vínculos a revisiones, integridad SQL y conflictos entre usuarios. Los cuatro casos de referencias usan conexiones PostgreSQL independientes y comprueban la espera real de bloqueo mediante `pg_stat_activity`.

Las pantallas Blade de índice nacional, índice institucional, detalle e historial sin snapshots se inspeccionaron con datos de prueba. Se verificó escritorio y móvil de 390 px; la tabla tiene desplazamiento propio y no amplía la página después de corregir la etiqueta accesible. El servidor temporal de QA se cerró y se restableció el viewport. Las pruebas HTTP usan JWT y permisos reales de fixtures; las vistas de QA no acreditan por sí mismas autorización.

## Activación y entorno

Se comparó el seeder contra la matriz operativa antes de ejecutarlo. El único delta era agregar `audit.view` a SUPER_ADMIN y ORGANIZATION_ADMIN; no había permisos retirados ni cambios en OFFICIAL/DEPARTMENT_ADMIN. Se ejecutó `AuthorizationSeeder` y se verificaron los permisos resultantes. La funcionalidad está disponible desde el enlace **Auditoría** en la navegación privada.

```bash
docker compose exec app php artisan db:seed --class=AuthorizationSeeder --force
```

La autorización procede de la aprobación explícita de Fase 14 y de los alcances de auditoría definidos por el usuario. No se creó un rol nuevo ni se publicaron registros de auditoría.

Comprobación final: `2026-09-28T19:41:28+00:00`.

| Componente | Resultado |
| --- | --- |
| PHP | 8.4.26 |
| Laravel | 13.31.0 |
| PostgreSQL | 17.5 (Debian 17.5-1.pgdg110+1) |
| PostGIS | 3.5.2 |
| Redis | PING correcto |
| Worker | Prueba técnica procesada: 2026-09-28T19:40:35+00:00 |
| Scheduler | Última sonda: 2026-09-28T19:41:01+00:00 |
| Programación | Sonda cada minuto; limpieza de temporales de reportes cada hora. |
| Puerto web | `127.0.0.1:8080` |
| Correo de desarrollo | `127.0.0.1:8025` |
| Permisos registrados | 49 |
| Lotes / filas de importación operativos | 0 / 0 |
| Temporales de exportación pendientes | 0 |

`docker compose ps --all`:

```text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED       STATUS                          PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         4 hours ago   Up About a minute (healthy)     9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     2 weeks ago   Exited (0) About a minute ago   
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        4 hours ago   Exited (0) About a minute ago   
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     2 weeks ago   Up 5 hours (healthy)            127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       2 weeks ago   Up 5 hours (healthy)            127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    2 weeks ago   Up 5 hours (healthy)            5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       4 hours ago   Up About a minute (healthy)     9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       2 weeks ago   Up 5 hours (healthy)            6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   4 hours ago   Up About a minute (healthy)     9000/tcp
```

Archivos de esta fase frente al checkpoint: **32 creados, 79 modificados, 0 eliminados**.

La estructura Docker, los puertos y los nombres de servicios se mantienen. Nginx expone únicamente `/public`; PostgreSQL y Redis continúan sin puerto publicado al host. Los servicios persistentes son app, nginx, postgres, redis, queue, scheduler y mailpit. `db-init` e `init` terminan con código 0.

Después de completar la regresión, `docker compose up -d` terminó correctamente y se reiniciaron app, queue y scheduler para cargar el código final. La sonda de cola se procesó y el scheduler registró una nueva ejecución. La comprobación HTTP devolvió 200 para `/health/ready` y `/portal`; el acceso anónimo a `/audit` devolvió 302 hacia `/login`.

## Comandos reproducibles

Desde `/home/josev/SYSFOREST`:

```bash
docker compose run --rm --no-deps init
docker compose exec -T \
  -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 \
  -e SYSFOREST_PUBLIC_EXPLAIN=1 -e SYSFOREST_PUBLIC_VISUAL=1 \
  app php artisan test --fail-on-warning
docker compose exec app php artisan route:list --path=audit --except-vendor
docker compose exec app composer audit --locked --format=json --no-interaction
docker compose up -d
docker compose ps --all
git diff --check
```

Formato con la dependencia local de desarrollo:

```bash
docker run --rm --user 1000:1000 \
  --mount type=bind,src=/home/josev/SYSFOREST,dst=/var/www/html \
  --workdir /var/www/html --entrypoint vendor/bin/pint \
  sysforest-app:local --preset psr12 --test
```

Pruebas focalizadas disponibles: `AuditCaptureTest`, `AuditReadTest`, `AuditPresentationTest`, `AuditSnapshotTest`, `AdministrativeConcurrencyTest`, `CatalogReferenceConcurrencyTest` y `SystemErrorSecurityTest`. Las pruebas JavaScript se ejecutaron con `node --test tests/Frontend/*.test.mjs` sobre copias temporales cuya igualdad con la fuente se comprobó por SHA-256. No se instalaron paquetes nuevos.

## Problemas encontrados y límites

- La revisión automática inicialmente interpretó que no había aprobación de Fase 14. Se pidió aclaración y el usuario respondió «confirmo». Los parches bloqueados en contexto de subagentes se inspeccionaron y se aplicaron desde la raíz con revisión aprobada y comandos pequeños.
- Catorce fallos focalizados iniciales correspondían a fixtures del contrato anterior: versiones ausentes/default no hidratado y mocks del método de catálogo/auditoría. Se corrigieron las pruebas conservando sus aserciones; la repetición focal pasó.
- La primera regresión completa detectó dos fallos: faltaba el enlace de regreso en el error de conflicto de inspección y la prueba espacial todavía esperaba el contrato de metadatos anterior. Se recuperó el enlace fijo y escapado y se actualizó la comprobación exacta de metadatos. La verificación focal y la regresión completa final documentadas arriba incluyen ambas correcciones.
- Un test de refresh concurrente intentaba borrar su auditoría al terminar. Ahora conserva un actor fijo exclusivamente en `sysforest_testing`, sin roles, desactivado, sin verificar, con clave aleatoria y sin sesiones al finalizar. Sus eventos permanecen inmutables. El rol de aplicación no tiene CREATE sobre la base de pruebas; no se amplió ese privilegio ni se relajó el historial para limpiar fixtures. Los conteos del seeder de desarrollo se verifican como incrementos respecto al estado inicial.
- La revisión encontró que una revocación administrativa atribuía eventos de sesión al titular. Se corrigió para conservar administrador y usuario afectado por separado. También se auditan individualmente las invitaciones sustituidas dentro de la misma transacción.
- La revisión final detectó motivos abreviados y ausencia del tipo de cambio de ubicación en la proyección central. Se conservan ahora hasta 5000 caracteres de motivo, con pruebas multibyte, manteniendo los límites forestales actuales y las etiquetas de corrección, traslado y trasplante.
- El índice de auditoría presentó un desbordamiento móvil debido a una etiqueta accesible con posición absoluta. Se confinó al enlace dentro del contenedor con scroll y se verificó nuevamente.
- Los eventos antiguos no garantizan snapshots ni identificador tipado del recurso; se muestra la información realmente disponible. Los nombres de usuario e institución son actuales y así se indican. No se realizó una prueba de penetración externa ni una certificación de seguridad.

La Fase 15 queda pendiente de la revisión y autorización del usuario.

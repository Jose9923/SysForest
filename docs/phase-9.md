# Fase 9 — Mantenimientos e intervenciones

Entrega técnica para revisión. La base es el cierre de Fase 8, commit **f750f66**, recibido en `main`. Se trabaja en **codex/phase-9**, sin commit final de esta fase. La autorización del usuario permite mantenimiento manual e integración de evidencias; no se inició Fase 10.

El proyecto definitivo permanece en `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Antes de incorporar los cambios se verificó la línea base: **949 pruebas PHP y 4.229 aserciones**, todas aprobadas.

## Funcionalidad entregada

Maintenance representa una orden institucional creada manualmente para un Tree. Reutiliza los once tipos nacionales de intervención; añade prioridad, fecha programada, responsable, notas, ejecución, resultado, verificación e historial. Una inspección no genera órdenes automáticamente. El Excel conserva sus 94 variables y no recibe campos forestales inventados por este módulo.

Cada orden tiene UUID y código humano institucional `MNT-000001`. La reserva utiliza `organization_sequences` con nombre `maintenance`, independiente de la secuencia de árboles y dentro de la transacción de creación. El código no se reutiliza al anular ni depende del autor de la orden.

La creación siempre queda pendiente, aunque se informe fecha o responsable. El flujo explícito es:

| Estado | Destinos disponibles | Requisitos principales |
| --- | --- | --- |
| Pendiente | Programado, asignado, cancelado | Fecha para programar, responsable vigente para asignar, motivo para cancelar. |
| Programado | Asignado, cancelado | Responsable vigente o motivo de cancelación. |
| Asignado | Programado, en ejecución, cancelado | Reprogramar retira la asignación; iniciar revalida al responsable. |
| En ejecución | Ejecutado, cancelado | Ejecutar exige responsable vigente, fecha no futura y resultado. |
| Ejecutado | Verificado | Permiso administrativo específico. |
| Verificado o cancelado | Ninguno | Se conservan los datos operativos. |

Las prioridades son Baja, Normal, Alta y Urgente; Normal es el valor inicial. No introducen plazos ni alarmas automáticas. Las fechas son de calendario y el límite de fecha futura se compara con el día UTC del servidor; una ejecución puede preceder a la fecha programada. Las reglas completas están en [maintenances.md](maintenances.md).

La edición general permite corregir tipo, prioridad, programación, responsable y notas en estados abiertos. Resultado y fecha realizada se registran al ejecutar. Las órdenes ejecutadas, verificadas y canceladas conservan sus datos operativos. No hay reapertura implícita ni cambios automáticos de estado, ubicación o mediciones de Tree, incluso para tipos como tala o traslado.

## Arquitectura y persistencia

Se conserva **Controller → Service → Repository → Model**. MaintenanceController coordina Requests y respuestas Blade. MaintenanceService implementa los casos de uso; MaintenanceWriteService coordina transacciones, locks y versión; MaintenanceWorkflowService valida transiciones; MaintenanceAuditService registra revisiones; MaintenanceReadService prepara filtros, acciones y diferencias para la interfaz. MaintenanceInput comparte reglas entre HTTP y servicios internos.

MaintenanceRepository concentra consultas y escrituras. MaintenanceReferenceRepository resuelve tipos, responsables y etiquetas históricas; las etiquetas se obtienen en dos consultas en lote a partir de revisiones ya autorizadas. MaintenanceRevisionRepository y MaintenanceStatusHistoryRepository mantienen los dos historiales. Las vistas no consultan tablas y JavaScript no decide transiciones ni permisos.

Migration `2026_09_21_000015_create_maintenance_tables.php` crea:

- `maintenances`: identidad, organización, árbol, tipo, código, estado, prioridad, fechas, responsable, notas, resultado, autores, versión y anulación lógica.
- `maintenance_revisions`: una revisión por escritura con actor, organización, versión, evento y valores anteriores/nuevos.
- `maintenance_status_histories`: estado anterior/nuevo, motivo, actor, versión y momento de transición.

Los triggers protegen identidad, autoría original y datos operativos finalizados. UUID, organización, Tree, código, created_by y created_at son inmutables. Las órdenes rechazan hard delete y ambos historiales son append-only. CHECK y FK reales protegen estados, prioridades, versiones, datos mínimos y referencias institucionales; `(organization_id,assigned_to)` referencia la membresía del responsable en esa organización.

La migración `2026_09_21_000016_add_maintenance_owner_to_evidences.php` añade `maintenance_id`, FK compuesta e índice de galería. Evidence conserva exactamente un propietario entre Tree, TreeInspection y Maintenance mediante `num_nonnulls(tree_id,tree_inspection_id,maintenance_id)=1`. Se amplían el trigger de identidad y la resolución/autorización del propietario.

En **sysforest_testing** se verificaron aplicación, rollback de dos pasos y reaplicación. Además, una prueba comprueba que el rollback de la migración de evidencias se niega mientras existan fotografías de mantenimiento, incluidas anuladas: no las elimina ni reasigna. Este resultado no equivale a una reversión segura de una base operativa con datos.

## Autorización y aislamiento

Se incorporan `maintenances.view/create/update/transition/verify/void/restore`: siete permisos nuevos, para un catálogo de **43 permisos**. SuperAdmin requiere tenant explícito; OrganizationAdmin administra su organización; Official consulta, crea, corrige y avanza órdenes institucionales; DepartmentAdmin solo consulta dentro de su ámbito. La verificación, anulación del registro y restauración corresponden a administradores autorizados.

MaintenancePolicy intersecta permisos, contexto institucional y acceso al Tree. El navegador no decide organización, código ni autoría. Las consultas por UUID se restringen al tenant. El índice exige también permiso de lectura de árboles y oculta órdenes de árboles anulados a quienes no pueden consultar su historial. El indicador interno que permite incluirlos procede del Service autorizado, no de los filtros del cliente.

Un responsable nuevo debe tener cuenta activa y verificada, membresía vigente y rol OFFICIAL u ORGANIZATION_ADMIN en la institución. Las referencias históricas se conservan aunque después se revoquen; asignar, iniciar y ejecutar revalidan su elegibilidad. No se crean membresías artificiales para un SuperAdmin. Los funcionarios pueden colaborar sobre órdenes de colegas: created_by permanece estable y cada cambio identifica al actor real.

## Concurrencia, auditoría y ciclo de vida

El orden de bloqueo es actor → organización → Tree → Maintenance; las fotografías continúan con Evidence. La comprobación del responsable utiliza locks compartidos NOWAIT sobre cuenta, membresía, asignación y rol para evitar esperar por otra cuenta que esté aguardando la misma organización. Un conflicto revierte la operación; una versión obsoleta devuelve **409** y conserva el primer cambio. La versión se valida antes de convertirla a entero.

Orden, reserva del código, transición, revisión y evento de seguridad se confirman en una transacción. La cobertura incluye rollback cuando falla auditoría. Los eventos son `maintenance.created`, `maintenance.updated`, `maintenance.status_changed`, `maintenance.voided` y `maintenance.restored`. security_events conserva el UUID del recurso, sin archivos, secretos ni rutas físicas.

Cancelar es una decisión operativa con motivo y mantiene visible la orden. Anular corrige un registro erróneo, requiere motivo y autorización administrativa, y conserva estado, resultado, revisiones y fotografías. Restaurar conserva UUID, código, autor y estado; exige Tree no anulado. Nunca restaura evidencias anuladas de manera independiente.

## Interfaz y fotografías

La navegación institucional incluye Mantenimientos. Hay listado paginado de veinte, filtros por Tree, tipo, estado, prioridad, responsable, fecha programada, texto y anuladas; formulario contextual al árbol; detalle con acciones, historia, diferencias legibles y galería. La ficha de Tree muestra hasta diez órdenes no anuladas y enlaza al listado. Select2 facilita tipo y responsable. Los textos se escapan y se conserva el valor textual «0».

Las fotografías utilizan el almacenamiento privado y el flujo de Fase 8. Se reutilizan `EVD_MAINTENANCE_BEFORE` y `EVD_MAINTENANCE_AFTER`; cada archivo conserva su tipo y propietario exclusivo. No se copia automáticamente a Tree o TreeInspection, ni se exige una cantidad arbitraria de fotos para ejecutar una orden. Una orden ejecutada o verificada puede conservar y recibir evidencias independientes, sujetas a sus propios permisos y versiones.

El acceso a imágenes exige también autorización sobre Maintenance y su Tree. Árbol u orden anulados bloquean nuevas cargas; la lectura histórica conserva sus restricciones. MIME real, límites, originales, checksum, entrega privada, journal y compensación reutilizan las garantías descritas en [file-storage.md](file-storage.md).

## Pruebas y formato ejecutados

La suite PHP completa terminó con **1.088 pruebas y 4.868 aserciones**, **0 fallos, 0 errores y 0 pruebas omitidas**. Duración registrada: **402,045917 segundos**. Frente a Fase 8 son **139 pruebas y 639 aserciones adicionales**. Se conservaron las pruebas anteriores y no se cambiaron dependencias.

| Archivos nuevos de pruebas | Casos | Cobertura principal |
| --- | ---: | --- |
| MaintenanceDomainTest y MaintenanceRepositoryTest | 74 | Flujo, responsables, tipos históricos, identidad, constraints, secuencia, auditoría, rollback, filtros e historial. |
| MaintenanceHttpSecurityTest | 25 | Roles, IDOR, revocación, CSRF real, versiones, contexto explícito y restricciones del Tree. |
| MaintenanceEvidenceTest | 28 | Tercer propietario, FK tenant, rollback protegido, rutas privadas, permisos y ciclo de vida de fotografías. |
| MaintenancePresentationTest | 12 | Formularios, acciones por rol/estado, filtros, escaping, etiquetas históricas y relaciones precargadas. |
| **Total nuevo: cinco archivos** | **139** | **Incluidos en la suite completa.** |

La ejecución focal de dominio y repositorio registró **74 casos y 163 aserciones**. Las verificaciones anteriores de Fase 8 permanecen cubiertas por la suite global; estos números no incluyen pruebas Node ni constituyen una validación de cámara física.

PSR-12: **404 archivos PHP, PASS**. `git diff --check` también terminó correctamente. El fixture compartido nuevo es `tests/MaintenanceTestCase.php`; sus registros pertenecen a la base de pruebas y sus transacciones.

Las pruebas JavaScript existentes de evidencias registraron **7/7, PASS**. Node en Windows no pudo acceder a la ruta UNC del proyecto WSL, por lo que se utilizaron copias exactas del test y de `public/js/evidences.js` en `.phase9-work/frontend`, conservando sus rutas relativas. No se modificó JavaScript para obtener ese resultado.

El reporte JUnit de la ejecución PHP completa está en `/tmp/sysforest-phase9-final.xml`, dentro del contenedor app. Las copias de XML y log están en `C:/Users/josev/OneDrive/Documents/ChatGPT/SysForest/.phase9-work/sysforest-phase9-final.xml` y `sysforest-phase9-final.log`; son artefactos de verificación fuera del proyecto definitivo.

## Correcciones y límites comprobados

Durante la integración se reforzó el índice para no mostrar órdenes de árboles anulados sin acceso histórico. Se amplió el trigger del responsable para revalidar también una transición SQL directa de pendiente a asignado; la regresión desactiva la cuenta y exige SQLSTATE23514. Las referencias históricas de auditoría se presentan con nombres y fechas mediante consultas en lote, con valores escapados. Estas correcciones forman parte del estado cubierto por la suite final.

No se atribuye a esta entrega una prueba nueva de concurrencia de mantenimiento con múltiples procesos confirmando filas permanentes. El diseño utiliza la secuencia institucional transaccional existente, bloqueo pesimista y control de versión; las pruebas registradas cubren los conflictos y rechazos concretos descritos.

## Entorno y comprobación operativa

La inspección anterior a la aplicación operativa registró PHP **8.4.25**, Laravel **13.31.0**, PostgreSQL **17.5**, PostGIS **3.5.2** y comprobación Redis `ping=1`. En ese momento existían **36 permisos**, correspondientes a Fase 8, y **0 árboles, 0 inspecciones y 0 evidencias** en la base operativa. No se presentan esos valores previos como una validación posterior de Fase 9.

La aplicación en **sysforest** confirmó la migración000015 en **66,80 ms** y la000016 en **7,76 ms**, ambas PASS. AuthorizationSeeder terminó en **83 ms**, PASS. `docker compose up -d` y el reinicio explícito de app, queue y scheduler finalizaron correctamente.

La inspección posterior del **21 de septiembre de 2026, 21:04:35 UTC** confirmó PHP8.4.25, Laravel13.31.0, PostgreSQL17.5, PostGIS3.5.2, Redis `true` y **43 permisos**. Los conteos operativos fueron **0** para árboles, inspecciones, evidencias, mantenimientos, revisiones de mantenimiento e historial de estados de mantenimiento. No se incorporaron fixtures forestales permanentes.

`docker compose ps -a` mostró siete servicios saludables y dos inicializadores finalizados con salida0:

| Contenedores | Estado confirmado |
| --- | --- |
| sysforest-app-1, sysforest-nginx-1, sysforest-postgres-1 | Healthy |
| sysforest-redis-1, sysforest-mailpit-1, sysforest-queue-1, sysforest-scheduler-1 | Healthy |
| sysforest-init-1, sysforest-db-init-1 | Exited(0) |

Los únicos puertos publicados continúan limitados a localhost: **8080** para Nginx y **8025** para Mailpit. PostgreSQL5432, Redis6379 y PHP-FPM9000 son internos. `curl --fail http://localhost:8080/health/ready` terminó con salida0 y cuerpo `{"status":"ok"}`.

El worker procesó el probe real de cola a las **21:03:54 UTC**. El scheduler ejecutó automáticamente su probe a las **21:04:00 UTC**; `schedule:list` confirmó la periodicidad de un minuto. La ejecución manual de una tarea no se usó como sustituto de esa comprobación automática.

## Verificación HTTP y visual

Con los cuatro roles SUPER_ADMIN, DEPARTMENT_ADMIN, ORGANIZATION_ADMIN y OFFICIAL se verificó acceso HTTP200 a `/maintenances`, `/maintenances?date_from=&date_to=`, `/maintenances?date_to=2026-09-21`, `/trees` y `/inspections`. El cierre de sesión pasó en todos los casos. Estas consultas operativas no crearon fixtures; las credenciales y cookies se mantuvieron en memoria. Las restricciones de escritura por rol están verificadas por las suites HTTP, no se deducen del éxito de estas consultas.

La revisión visual utilizó snapshots Blade con datos de prueba y formularios desactivados: escritorio **1440×1000**, tableta **768×1024** y móvil **390×844**. Listado, formulario, detalle, timeline, acciones y galería se comprobaron sin desbordamiento horizontal. Select2 se verificó en móvil. La comprobación final móvil del historial y la galería también pasó. Al terminar se cerró la pestaña temporal, se restauró el viewport y se detuvo el servidor de preview8092. Esta revisión no simuló una escritura operativa y no se probó una cámara física.

## Comandos de reproducción

Desde `/home/josev/SYSFOREST`, dentro de Ubuntu-24.04 WSL:

~~~bash
# Arranque y estado de todos los servicios.
docker compose up -d
docker compose ps -a

# Aplicación operativa con el servicio migrador y base explícita.
docker compose run --rm --no-deps -T -e DB_DATABASE=sysforest init php artisan migrate --force --no-interaction
docker compose run --rm --no-deps -T -e DB_DATABASE=sysforest init php artisan db:seed --class=AuthorizationSeeder --force --no-interaction

# Recargar aplicación, worker y scheduler sin eliminar volúmenes.
docker compose restart app queue scheduler
curl --fail http://localhost:8080/health/ready

# Suite PHP completa y artefacto JUnit; utiliza sysforest_testing.
docker compose exec -T app php artisan test --log-junit /tmp/sysforest-phase9-final.xml

# Formato y verificación PSR-12 del proyecto.
docker run --rm --user 1000:1000 --volume /home/josev/SYSFOREST:/var/www/html --workdir /var/www/html --entrypoint composer sysforest-app:local format
docker compose exec -T app composer lint
git diff --check

# Despachar el probe y comprobar su procesamiento y programación.
docker compose exec -T app php artisan sysforest:probe-queue
docker compose exec -T app php artisan schedule:list
docker compose logs --tail 30 queue scheduler
~~~

La reversibilidad se comprobó en la base de pruebas, sin fotografías de mantenimiento que debieran conservarse. Para repetir esa comprobación controlada se utiliza exclusivamente `DB_DATABASE=sysforest_testing`:

~~~bash
docker compose run --rm --no-deps -T -e DB_DATABASE=sysforest_testing init php artisan migrate --force --no-interaction
docker compose run --rm --no-deps -T -e DB_DATABASE=sysforest_testing init php artisan migrate:rollback --step=2 --force --no-interaction
docker compose run --rm --no-deps -T -e DB_DATABASE=sysforest_testing init php artisan migrate --force --no-interaction
~~~

El rollback protegido rechaza la operación si existe Evidence con maintenance_id; estos comandos no describen una eliminación de datos operativos. Para reproducir la ejecución Node realizada, desde el workspace Windows que contiene las copias exactas:

~~~powershell
node --test .phase9-work/frontend/tests/Frontend/evidences.test.mjs
~~~

## Estado de archivos y dependencias

El [inventario completo de archivos](phase-9-files.txt) registra **74 archivos: 43 nuevos y 31 modificados**, incluido este informe. La comprobación `git diff --name-only f750f66 -- tests composer.json composer.lock docker-compose.yml Dockerfile` devolvió salida vacía para esos archivos rastreados: se conservaron pruebas previas, dependencias, Dockerfile y definición Compose. Las nuevas suites están documentadas arriba y no sustituyen pruebas anteriores.

## Deuda y decisiones pendientes

Los avisos de mantenimiento por correo no están implementados. Deben definirse disparadores, destinatarios y reglas de envío; la infraestructura Mail/Queue existente no equivale a notificaciones funcionales de este módulo. Tampoco se incorpora calendario externo, recurrencia automática, cuadrillas, costes, órdenes generadas desde inspecciones ni cambios automáticos del árbol.

Quedan fuera mapas generales, portal público, importaciones, reportes y exportaciones. Reabrir órdenes finalizadas, exigir un verificador distinto del ejecutor, asociar prioridades a plazos o imponer fotografías obligatorias requiere una decisión explícita adicional y su trazabilidad. Los límites heredados de evidencias —sin HEIC, miniaturas, extracción EXIF, S3 operativo ni purga física— permanecen documentados.

La rama queda para revisión, sin commit final, merge ni inicio de Fase 10. Las reglas funcionales vigentes están en [maintenances.md](maintenances.md); el cierre de referencia anterior se conserva en [phase-8.md](phase-8.md).

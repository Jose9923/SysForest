# Fase 8 — Evidencias fotográficas

Entrega para revisión. La Fase 7 se cerró en **8fcb2d5** con el mensaje exacto `feat: complete phase 7 forestry inspections`. La rama actual es **codex/phase-8**, sin commit final de Fase 8. No se inició Fase 9.

Proyecto definitivo: `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Petición aprobada SHA-256: `56336832ad8b6a0dc7ede637dfa3eefe6fbbec0e13298f2a9ec4d48c0f507957`.

Antes del checkpoint se inspeccionó Git; `.phase7-work/` no existía dentro del proyecto y `/.phase*-work/` ya estaba ignorado. No se borraron archivos previos. `docker compose up -d` terminó en 0, **752 pruebas / 3.207 aserciones** pasaron y PSR-12 aprobó **338 archivos**. Después se crearon el commit y la rama solicitados.

## A. Tablas y migraciones nuevas

`evidences` y `evidence_revisions`, mediante `2026_09_12_000014_create_evidence_tables.php`. Aplicación, rollback de un paso y reaplicación aprobados exclusivamente en `sysforest_testing`; después el servicio `init` aplicó la migración operativa. No se ejecutó migrate:fresh ni rollback sobre datos operativos.

## B. Modelo Evidence

UUID, organización, tipo, propietario explícito, disk/path relativos, nombre original saneado, MIME/extensión/tamaño calculados, dimensiones, SHA-256, caption/notes/captured_at, metadata técnica, autores, lock_version y SoftDeletes con deleted_by/deletion_notes. Asignación masiva protegida y tabla explícita `evidences`. Identidad, propietario, cargador y procedencia física inmutables también en PostgreSQL.

## C. Relación con Tree

Tree tiene `evidences()` para fotografías permanentes propias. Su ficha contiene esa galería y, por separado, la de la última inspección. No se añadieron columnas de imagen a trees ni se duplican fotografías automáticamente.

## D. Relación con TreeInspection

TreeInspection tiene `evidences()` para fotografías de la visita. Su ficha integra la misma galería Blade. La fauna fotográfica no se guarda como BLOB/path en inspection_fauna ni se vincula a especies individuales inexistentes en el dominio.

## E. Restricción de propietario

`CHECK (num_nonnulls(tree_id, tree_inspection_id) = 1)` impone exactamente un propietario. Las FK `(organization_id,tree_id)` y `(organization_id,tree_inspection_id)` garantizan pertenencia institucional. Hay pruebas de Tree solo, Inspection sola, ambos, ninguno y cruces de tenant en aplicación y DB.

## F. EvidenceTypes reutilizados

Se reutilizan los doce tipos nacionales: Individuo completo, Sistema radicular, Fuste, Ramas, Copa, Follaje, Flores/frutos, Afectación fitosanitaria, Fauna, Mantenimiento antes, Mantenimiento después y Otro. Cada archivo exige exactamente un tipo. Disponibilidad activa al crear/cambiar; referencias históricas inactivas conservables. Se controla mediante validación, FOR SHARE y trigger DB.

## G. FOTO FAUNA

**CN4 / variable 92** → Evidence de TreeInspection con **EVD_FAUNA**. Admite cero, una o varias fotografías por inspección y coexiste con las categorías de fauna registradas.

## H. FOTO INDIVIDUO

**CO4 / variable 93** → Evidence de TreeInspection con **EVD_WHOLE_TREE**, según la matriz aprobada. Documenta la visita histórica. Las fotografías permanentes del Tree son registros adicionales explícitos, no copias automáticas.

## I. Filesystem y disco

Disco lógico `evidences`, inicialmente local y privado. Services encapsulan Laravel Filesystem; Model conserva disk/path. `serve=false` en evidences y en el disco local padre deshabilita endpoints genéricos fuera de Policy. No se creó storage:link ni public/uploads. S3-compatible requerirá instalar/configurar su adaptador y copiar claves relativas; no está anunciado como conexión ya operativa.

## J. Persistencia Docker

Se reutiliza **sysforest_storage_data**, montado en `/var/www/html/storage` y compartido por app/queue/scheduler. Prueba real: base temporal aislada `sysforest_phase8_persistence`, creada solo tras comprobar ausencia y marcada con el UUID de ejecución; 1 Tree, 1 Inspection y 2 Evidence confirmados. JPEG de **3.899 bytes** y PNG de **953 bytes**, ambos **320×160**, creados como imágenes técnicas identificadas.

Se ejecutó **`docker compose restart`** sobre los nueve servicios, seguido de **`docker compose up -d`**, ambos con salida 0. Desde nuevos contenedores app y queue se verificaron filas completas, disk/path, metadata, capturas, autores, tamaños, streams y SHA-256 idénticos. Directorios **0700**, archivos **0600**, UID/GID **1000**. No se utilizó Storage::fake en esta comprobación.

Después se verificaron el marcador y los conteos y se retiraron únicamente la base creada y los archivos conocidos del directorio aislado. No hubo DROP FORCE, down -v ni eliminación de volúmenes generales. La base operativa conserva **0 árboles, 0 inspecciones y 0 evidencias ficticias**. Procedimiento y hashes en [file-storage.md](file-storage.md).

## K. Estructura física de rutas

Dentro del disco privado: `organizations/{organization_uuid}/{operation_uuid}.{jpg|png|webp}`. Raíz local: `/var/www/html/storage/app/private/evidences`. Journals de intentos: `.pending/{operation_uuid}.json`. El nombre original nunca determina la ruta. El HTML solo recibe `/evidences/{uuid}/view` y `/download`.

## L. MIME permitidos

`image/jpeg`, `image/png`, `image/webp`. Se valida MIME real con fileinfo, cabecera, marcadores del contenedor y decodificación GD. Sufijo coherente con el contenido; rechazo de dobles extensiones ejecutables, falsificaciones y corrupción detectable. SVG, HEIC, GIF y otros formatos quedan fuera de esta fase.

## M. Tamaño máximo

Configuración central: **10 MiB por archivo, 5 archivos y 50 MiB por lote**. PHP: upload_max_filesize **20M**, post_max_size **55M**, max_file_uploads **5**; Nginx client_max_body_size **55m**. Los límites técnicos admiten el lote y su multipart; el servidor valida siempre. Protección de decodificación: máximo configurable **20 millones de píxeles** y estimación conservadora de memoria disponible, con PHP **256M**. No se trata de un límite biológico.

## N. Metadata almacenada

MIME, extensión canónica, tamaño real, dimensiones y JSONB con versión de validación, original_preserved y exif_extracted=false. No se inventan cámara, fecha ni GPS. EXIF no está instalado; el original conserva su metadata/orientación para el navegador. Captured_at es opcional, declarado explícitamente en UTC con precisión de minuto; nunca procede de mtime. PostGIS continúa siendo la ubicación oficial del Tree.

## O. Checksum

SHA-256 calculado por servidor sobre los bytes originales y almacenado como 64 caracteres hexadecimales. La prueba de reinicio comprobó su igualdad. Se permiten archivos idénticos con distintos UUID/rutas; no se impuso una política de rechazo de duplicados.

## P. Services

EvidenceService, EvidenceWriteService, EvidenceOwnerService, EvidenceReadService, EvidenceDeliveryService, EvidenceAuditService, ImageMetadataService, EvidenceStorageService, EvidenceStorageJournal y EvidenceRecoveryService. Responsabilidades y contratos en [evidences.md](evidences.md) y [file-storage.md](file-storage.md).

## Q. Repositories

EvidenceRepository, EvidenceTypeRepository y EvidenceRevisionRepository. Consultas por propietario, organización, tipo, activos/anulados, fechas y lotes de árboles/inspecciones; resúmenes de seis y páginas de veinte. Eager loading de tipos, autores y propietarios. La reconciliación comprueba referencias físicas incluyendo anuladas y usa advisory locks PostgreSQL verificados con dos conexiones reales.

## R. Controllers

EvidenceController delgado: index/create/store/show/update/destroy/restore/view/download. No procesa imágenes ni consulta tablas. Hay doce rutas privadas, asociadas a propietarios explícitos y UUID. Entrega de archivo mediante EvidenceDeliveryService después de autorizar.

## S. Requests

StoreEvidenceRequest, UpdateEvidenceRequest, VoidEvidenceRequest y RestoreEvidenceRequest, con reglas compartidas en EvidenceInput. Campos internos y procedencia protegidos; organización y autoría se determinan en servidor. Errores por archivo, validación de tipo, fechas y versión estricta. Los filtros se validan en el Service de lectura.

## T. Policies

EvidencePolicy intersecta permiso, tenant y acceso al propietario. La carga requiere además Tree raíz no anulado; aplica igualmente a evidencias de inspección. No exige permiso Tree.update ni campaña activa. Evidencias anuladas y sus originales requieren permiso de restauración además del acceso al propietario.

## U. Permisos

Cinco nuevos: evidences.view/create/update/delete/restore; **36 permisos totales**. OrganizationAdmin gestiona y restaura; Official consulta, carga, corrige y anula dentro de su organización, incluso archivos de colegas. DepartmentAdmin solo lee su departamento. SuperAdmin opera con tenant seleccionado, sin membresía artificial.

## V. Blade y galería

Galería reutilizable integrada en Tree y TreeInspection; vistas de índice, carga y detalle con edición, ciclo de vida y auditoría. Tipos, fecha, caption, autor privado y acciones autorizadas. DTO explícito sin disk/path/EXIF; no hay consultas en Blade. Las tarjetas usan el original controlado con lazy loading; no se generan miniaturas todavía.

## W. Carga múltiple

Cada `items[i]` lleva file, evidence_type_id y descripciones opcionales. Selección, preview, quitar, Select2 individual y errores por tarjeta. El lote se valida completamente antes de escribir. XHR muestra progreso total real y conserva selección ante 422; no reintenta automáticamente una respuesta ambigua. Sin JavaScript se admite una imagen por formulario.

## X. Móvil y cámara

Revisión visual en navegador integrado: **1440×1000, 768×1024 y 390×844**, para galería, detalle y cola de carga. Sin desbordamiento horizontal; Select2 abierto y opciones legibles en móvil, previews y botones visibles. Las páginas visuales usaron fixtures saneados con imágenes técnicas, sin credenciales ni transporte de subida activo. La lógica JS se verificó además con siete pruebas Node del código real y dobles DOM/XHR. No se afirma haber probado una cámara física o un selector nativo de teléfono; su comportamiento depende del dispositivo.

## Y. SoftDelete

Anulación con deleted_at/deleted_by/deletion_notes, incremento de versión y auditoría. Sale de la galería normal y conserva el archivo. No se reutilizó el catálogo de motivos de anulación de Tree ni se agregó un recolector de retención.

## Z. Restauración

OrganizationAdmin/SuperAdmin autorizados pueden restaurar si el archivo existe, con el mismo UUID/path/uploaded_by. Si falta, se informa en español y la anulación permanece. Restaurar Tree no restaura fotografías anuladas de forma independiente.

## AA. Auditoría

evidence_revisions append-only: actor, organización, UUID de evidencia, propietario, tipo, versiones y valores descriptivos anteriores/nuevos. Eventos uploaded/updated/voided/restored, más security_events con resource_id. Auditoría y escritura en una transacción; sin binarios, tokens ni rutas físicas.

## AB. Bloqueo optimista

lock_version se valida antes de convertir a entero. Una edición obsoleta devuelve **409** y «La evidencia fue modificada por otro usuario.»; conserva el primer cambio. Misma protección en anulación/restauración, con orden común de locks.

## AC. Seguridad de archivos y atomicidad

Ruta UUID generada, original_name saneado, MIME/contenido reales, dimensiones/memoria antes de GD, nombres/captions escapados y almacenamiento fuera de public. Entrega con MIME fijo, nosniff, CSP restrictiva, Cache-Control private/no-store y attachment al descargar. Sin ETag ni 304 que eludan autorización; errores HTTP no exponen stack traces.

Un journal privado se registra antes de escribir; DB y auditoría se confirman juntas. Ante fallo se comprueba bajo advisory lock si existe una referencia confirmada, incluyendo SoftDeletes, antes de compensar. DB indisponible o commit incierto conservan el archivo y el intento. `php artisan evidences:reconcile-upload UUID` recupera solo ese intento registrado; no escanea ni purga evidencias. No se promete atomicidad distribuida perfecta ni detección universal de políglotas. Los originales permanecen no ejecutables y privados; saneamiento por recodificación y variantes públicas son decisiones futuras.

## AD. Aislamiento tenant

Pruebas HTTP A/B sobre carga a Tree/Inspection ajenos, lectura por UUID, descarga, cambio de tipo y anulación; rechazadas. FK compuestas impiden cruces también en DB. Pruebas de revocación de permisos/membresía, propietario inaccesible, departamentos ajenos y SuperAdmin sin contexto. No se confía en organization_id del navegador.

## AE. Pruebas nuevas

| Suite nueva | Pruebas | Aserciones |
| --- | ---: | ---: |
| EvidenceAuthorizationTest | 18 | 103 |
| EvidenceDomainTest | 36 | 102 |
| EvidenceHttpSecurityTest | 26 | 374 |
| EvidencePresentationTest | 10 | 111 |
| EvidenceRepositoryTest | 53 | 219 |
| EvidenceStorageServiceTest | 18 | 37 |
| ImageMetadataServiceTest | 36 | 76 |
| **Total PHP nuevo** | **197** | **1022** |

Las siete pruebas Node cubren selección, tipo por archivo, eliminación de preview, FormData, errores 422, progreso y fallo de transporte, sin agregar dependencias.

## AF. Total de pruebas y aserciones

**949 pruebas PHP / 4.229 aserciones: PASS**, 0 errores, 0 fallos y 0 omitidas; tiempo JUnit **328.45 s**. Más **7 pruebas JavaScript: PASS** (no sumadas al total PHPUnit).

Comando final ejecutado dentro de Ubuntu-24.04:

~~~bash
docker compose exec -T app php artisan test --compact --log-junit /tmp/sysforest-phase8-release.xml
~~~

El log y el JUnit de esta ejecución se conservaron fuera del proyecto definitivo en `.phase8-work/sysforest-phase8-release.log` y `.xml` del workspace Windows.

Se conservaron intactos los **50 archivos de pruebas preexistentes** y composer.json/composer.lock (`git diff --name-only 8fcb2d5 -- tests composer.json composer.lock` no lista archivos rastreados modificados). La suite completa vuelve a cubrir las 752 pruebas aprobadas; no se redujeron pruebas ni se ocultaron regresiones.

## AG. PSR-12

`composer format` y `composer lint`: **372 archivos PHP, PASS**. `git diff --check`: salida 0. Comentarios, PHPDoc y documentación en español; identificadores técnicos en inglés. No se añadieron comentarios obvios por línea.

## AH. Matriz de las 94 variables

**94/94 soportadas**. Se conservaron los 94 encabezados, las siete fórmulas de la fila fuente y las 65 condiciones. «Soportada» significa que cada variable dispone de destino funcional dentro del modelo normalizado; no exige una columna por variable en una única tabla. CN/CO son funcionales mediante Evidence y pruebas reales de ambos destinos, no placeholders.

## AI. Docker Compose y servicios

~~~text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED          STATUS                      PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         22 minutes ago   Up 22 minutes (healthy)     9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     35 hours ago     Exited (0) 21 minutes ago   
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        22 minutes ago   Exited (0) 21 minutes ago   
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     6 hours ago      Up 22 minutes (healthy)     127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       35 hours ago     Up 22 minutes (healthy)     127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    35 hours ago     Up 22 minutes (healthy)     5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       22 minutes ago   Up 22 minutes (healthy)     9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       35 hours ago     Up 22 minutes (healthy)     6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   22 minutes ago   Up 22 minutes (healthy)     9000/tcp
~~~

Siete servicios saludables y dos inicializadores Exited(0). Puertos publicados: **127.0.0.1:8080 → Nginx:80**, **127.0.0.1:8025 → Mailpit:8025**. PHP-FPM9000, PostgreSQL5432 y Redis6379 son internos. Nginx expone exclusivamente public.

PHP **8.4.25**, Laravel **13.31.0**, PostgreSQL **17.5**, PostGIS **3.5.2**. Redis PONG y conexión desde Laravel aprobada; `/health/ready` devuelve `{"status":"ok"}`. Worker procesó InfrastructureProbeJob a **23:18:40 UTC**, DONE; scheduler ejecutó su tarea minutely a **23:19:00 UTC**, DONE. `schedule:list` confirma su configuración. No se añadieron tareas pesadas de imágenes ni un scheduler de eliminación.

HTTP real después del reinicio: login/selección/logout y módulos anteriores aprobados con los cuatro roles; preview200 para SuperAdmin/OrganizationAdmin/Official y403 para DepartmentAdmin. Las credenciales y cookies permanecieron en memoria; no se exportaron.

## AJ. Git status

Rama **codex/phase-8**, HEAD **8fcb2d5**. **70 archivos: 23 modificados y 47 nuevos**. Sin commit final, merge ni publicación. Temporales de generación/pruebas permanecen fuera del proyecto definitivo y no son dependencias de la aplicación.

## AK. Archivos creados/modificados

[Inventario completo](phase-8-files.txt). Incluye migración, modelos, relaciones, repositorios, servicios, policy, Requests/controller, comando de reconciliación, configuración/entorno, límites PHP/Nginx, RBAC, vistas/assets, pruebas y documentación. Dockerfile y docker-compose.yml conservan su estructura: se reutilizan imagen y volumen compartido existentes; cambian las configuraciones PHP/Nginx consumidas por ellos.

## AL. Deuda técnica e incidencias

Se conserva exactamente **php-open-source-saver/jwt-auth:2.9.3** y la advertencia conocida del pin; sin actualización arbitraria de dependencias. No hay miniaturas, extracción EXIF, HEIC/SVG, conexión S3 operativa, portal público ni retención destructiva. La recodificación de originales y derivados públicos requiere una decisión específica. Una interrupción puede dejar un journal pendiente que debe reconciliarse; no se borra ante incertidumbre de DB.

Durante la integración se corrigieron el nombre explícito de tabla Evidence, fixtures de tipos/archivos temporales, fecha desconocida en la proyección y expectativas de mensajes seguros. Se deshabilitaron las rutas genéricas del disco padre y se validó el límite total del lote. Se añadieron tres regresiones para normalizar fechas de captura y filtros opcionales vacíos al invocar directamente los Services. La revisión documental aclaró que no existe is_public y que las comprobaciones de marcadores no garantizan detectar todo contenido añadido. Las comprobaciones finales corresponden al estado corregido.

## AM. Decisiones pendientes

Política de publicación y saneamiento de variantes; proveedor de almacenamiento futuro; retención y eliminación física definitiva; soporte HEIC/EXIF/miniaturas; validación de cámara en dispositivos concretos. Se mantienen las decisiones forestales pendientes de Fase 7, incluida la anulación formal de una inspección. Ninguna impide el flujo privado de evidencias entregado.

## AN. Preparación para maintenance_id de Fase 9

Agregar maintenance_id nullable, FK `(organization_id,maintenance_id)`, UNIQUE correspondiente del propietario y CHECK `num_nonnulls(tree_id,tree_inspection_id,maintenance_id)=1`. Extender resolución/autorización del propietario, trigger de identidad, repositorios, auditoría y galería. No se creó Maintenance ni una relación polimórfica sin integridad. **La Fase 9 queda pendiente de aprobación.**

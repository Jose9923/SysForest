# Almacenamiento privado de evidencias

## Contrato y configuración

Evidence es una entidad independiente. Guarda disk y path relativos; Tree, TreeInspection y Maintenance no tienen columnas de imagen ni conocen una ruta física. EvidenceStorageService encapsula Laravel Filesystem y EvidenceStorageJournal registra intenciones recuperables. Los Controllers y Repositories no escriben archivos.

La petición Fase 8 tiene SHA-256 `56336832ad8b6a0dc7ede637dfa3eefe6fbbec0e13298f2a9ec4d48c0f507957`. La semántica del instrumento y sus propietarios se documenta en [la matriz](excel-field-mapping.md).

| Clave de config/evidence.php | Variable de entorno | Valor inicial |
| --- | --- | --- |
| disk | EVIDENCE_DISK | evidences |
| max_size_mb | EVIDENCE_MAX_SIZE_MB | 10 |
| max_files | EVIDENCE_MAX_FILES | 5 |
| max_batch_size_mb | EVIDENCE_MAX_BATCH_SIZE_MB | 50 |
| max_pixels | EVIDENCE_MAX_PIXELS | 20000000 |
| allowed_mimes | EVIDENCE_ALLOWED_MIMES | image/jpeg,image/png,image/webp |

Los tamaños llamados MB en la configuración se convierten a MiB: 1 MiB = 1.048.576 bytes. La política inicial permite hasta cinco archivos de 10 MiB, con un máximo total de 50 MiB. EvidenceInput valida cantidad y entradas de cada elemento; ImageMetadataService vuelve a comprobar el tamaño real de cada archivo. El servicio coordinador comprueba el total antes de escribir.

El disco evidences se define en config/filesystems.php con driver local, root dentro de storage/app/private/evidences, visibility y directory_visibility privadas, serve=false y throw=true. El disco local padre también tiene serve=false para impedir rutas genéricas de entrega o carga firmada fuera de la autorización de evidencias. No se crea storage:link para evidencias, URL pública ni directorio public/uploads. No se debe configurar este disco hacia un directorio servido por Nginx.

El alias disk persistido es estable. Para usar almacenamiento S3 compatible se cambia el driver/configuración de ese alias y se incorpora su adaptador de Filesystem si falta; también deben transferirse los objetos existentes preservando sus claves. No se reescriben Tree, Evidence ni sus servicios. Cambiar solo el nombre del disco sin migrar las referencias no traslada archivos.

## Propietario Maintenance en Fase 9

Las imágenes de una orden utilizan el mismo disco privado evidences, las mismas claves generadas por organización/UUID y el mismo protocolo de journal. No se crea un directorio público ni se duplica el archivo al asociarlo con su árbol raíz. Los tipos nacionales EVD_MAINTENANCE_BEFORE y EVD_MAINTENANCE_AFTER sólo describen la fotografía; su selección no cambia los límites, la validación real ni el procedimiento de recuperación.

La autorización resuelve tenant → Tree → Maintenance antes de cargar y vuelve a comprobarla bajo bloqueo dentro de la transacción. La lectura histórica de una orden anulada conserva su referencia a Evidence y requiere autorización del propietario. referencedStoragePath incluye evidencias anuladas de cualquiera de los tres propietarios, por lo que la reconciliación sigue protegiendo originales confirmados aunque la orden esté anulada.

La extensión no modifica EvidenceStorageService, EvidenceStorageJournal, ImageMetadataService ni EvidenceRecoveryService. La migración rechaza su rollback si hay evidencias de mantenimiento; no elimina archivos para resolver dependencias. El ensayo real de persistencia documentado más abajo corresponde a Fase 8 y conserva su alcance original de Tree/TreeInspection.

## Persistencia y permisos en Docker

storage_data monta /var/www/html/storage en app, queue y scheduler. Los tres ejecutan con el usuario/grupo sysforest definidos en Dockerfile. El volumen conserva originales y journals entre restart y down/up sin eliminar volúmenes. Nginx solo monta public y no recibe el volumen de almacenamiento. No se utiliza chmod 777.

La política HTTP F8 requiere upload_max_filesize=20M, post_max_size=55M y client_max_body_size=55m. El límite PHP por archivo es una barrera superior; la aplicación impone 10 MiB. Los 55 MiB del request dejan margen para los cinco archivos y su formulario multipart. Al modificar la política deben mantenerse coherentes PHP, Nginx y configuración de aplicación. La comprobación del runtime y la prueba real de reinicio se registran en [phase-8.md](phase-8.md).

## Inspección del contenido

ImageMetadataService::inspect(UploadedFile $file, string $errorKey='file'): array devuelve:

- mime_type y extension canónica, derivados del contenido.
- size_bytes real y width/height obtenidos y comprobados en servidor.
- checksum_sha256 del archivo original.
- original_name saneado, utilizado solamente como metadata.
- metadata técnica con validation=finfo_header_gd_v1, exif_extracted=false y original_preserved=true.

No utiliza el MIME ni dimensiones declarados por el navegador. Primero comprueba recepción y tamaño, luego fileinfo, el tipo de cabecera, dimensiones y presupuesto de memoria. Finalmente decodifica mediante GD y verifica que las dimensiones resultantes coincidan. getimagesize por sí solo no es un validador de imágenes; GD rechaza contenido que no puede cargar. [Manual de PHP: cabecera](https://www.php.net/manual/en/function.getimagesize.php), [decodificación](https://www.php.net/manual/en/function.imagecreatefromstring.php).

El runtime verificado soporta GD JPEG, PNG y WebP, además de fileinfo. La lista configurable puede restringir esos formatos; no permite habilitar automáticamente otros solo por agregar un MIME. SVG, HEIC, GIF y AVIF quedan fuera de esta fase. No se garantiza soporte de WebP animado: cualquier archivo que GD no decodifique sin errores se rechaza.

La extensión del nombre debe corresponder al contenido: jpg/jpeg para image/jpeg, png para image/png y webp para image/webp. Se rechazan extensiones ejecutables, incongruencias y dobles extensiones como foto.php.jpg. El nombre físico no procede del nombre recibido. Se retiran directorios con separadores Windows o POSIX, controles y marcas de dirección Unicode; se limita el nombre de metadata a 255 caracteres. Su presentación requiere escape HTML como cualquier texto del usuario.

JPEG exige marcadores inicial y final; PNG exige firma y cierre IEND completo; WebP verifica RIFF/WEBP y longitud declarada exacta. Estas comprobaciones rechazan cierres ausentes o inconsistentes y los anexos cubiertos por las pruebas; no recorren todos los segmentos del formato ni garantizan detectar un cierre repetido después de contenido adicional. Complementan la decodificación, pero no prueban la ausencia universal de archivos políglotas o malware incrustado en segmentos válidos. La defensa incluye nombres generados, ausencia de ejecución, almacenamiento fuera de webroot y entrega controlada. No se envían imágenes a servicios externos de análisis. [Controles de carga de OWASP](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).

## Memoria y resolución

El máximo inicial es 20 millones de píxeles, por protección de recursos del proceso PHP con memory_limit=256M. El límite se comprueba mediante división antes de multiplicar ancho y alto, evitando desbordamientos ante cabeceras maliciosas.

Además se reserva conservadoramente memoria actual + 10 bytes por píxel + dos veces el tamaño comprimido + 32 MiB de margen. Una imagen inferior a 20 millones de píxeles puede requerir reducción si no cabe en el presupuesto disponible. Esta estimación no es una medición exacta del consumo de cada códec ni una garantía frente a todos sus defectos; reduce el riesgo antes de invocar GD.

Se procesa una imagen por vez. GD y los buffers se liberan en finally, tanto en éxito como en error; el lote retiene únicamente los metadatos calculados, no varios mapas de píxeles descomprimidos. Las dimensiones no se aceptan del cliente. Las pruebas de cabeceras enormes y presupuesto de memoria rechazan antes de reservar el decodificador.

## Original, EXIF y fecha de captura

Se almacena el original sin reescribir píxeles ni generar miniaturas. Su checksum corresponde a esos mismos bytes. La orientación EXIF permanece en el archivo para los navegadores modernos que la interpreten; el servidor no rota físicamente la imagen. El runtime actual no carga la extensión EXIF y no extrae cámara, orientación, GPS ni fecha. No se inventan valores para cubrir esa ausencia.

captured_at pertenece a la metadata descriptiva validada por la aplicación y puede quedar NULL. No se deduce de la fecha de subida, del mtime del temporal ni de una cabecera EXIF sin zona conocida. Las coordenadas oficiales de Tree permanecen en PostGIS: subir una fotografía nunca las sustituye.

Una futura variante para publicación puede normalizar orientación y eliminar metadata privada sin alterar el original. No existe todavía portal público, variante pública ni columna is_public. Todas las evidencias se entregan mediante autorización privada; el formulario no puede publicar una imagen.

## Rutas y entrega

Cada operación usa un UUID nuevo por archivo, asignado por el servidor:

~~~text
organizations/{organization_uuid}/{operation_uuid}.jpg
organizations/{organization_uuid}/{operation_uuid}.png
organizations/{organization_uuid}/{operation_uuid}.webp
.pending/{operation_uuid}.json
~~~

Los manifest solo contienen disk, path y operation_id. Sus rutas se validan estrictamente: disco configurado, UUID de organización/operación válidos y extensión canónica. El UUID del nombre del journal debe coincidir con su contenido y el del objeto. No admiten URLs, rutas absolutas, subidas con ../ ni referencias a otro disco. Un journal tiene un máximo de 2 KiB; uno inconsistente produce un error, no una orden de borrado.

EvidenceStorageService::read(Evidence) retorna un stream y exists(Evidence) comprueba el objeto. Ambos verifican el disco, ruta y prefijo de organización; el coordinador debe haber autorizado antes el recurso y su propietario. No necesitan obtener una ruta física local del destino, por lo que el mismo contrato sirve para un adaptador remoto.

EvidenceDeliveryService autoriza cada petición antes de abrir el original y usa MIME persistido, Content-Length, Cache-Control: private, no-store, Pragma: no-cache, X-Content-Type-Options: nosniff y Content-Security-Policy: default-src 'none'; sandbox. Content-Disposition usa el UUID y extensión calculada; el modo de descarga es attachment. No se generan ETag ni respuestas condicionales que eviten revalidar la sesión. Las rutas privadas /evidences/{uuid}/view y /download no revelan paths físicos. Si falta el objeto, se informa de su ausencia sin devolver un stream ficticio.

## Transacción y compensación

PostgreSQL y Filesystem no forman una transacción distribuida. La coordinación combina locks de operación, journals y comprobación de referencias para impedir una limpieza insegura:

1. EvidenceService autoriza propietario/tenant y valida todo el lote antes de escribir. Dentro de la transacción vuelve a validar el actor, propietario y tipo.
2. Por archivo genera operationId y obtiene EvidenceRepository::lockStorageOperation(operationId), mantenido hasta finalizar la transacción.
3. store(file, organizationId, technical, operationId) registra primero .pending/{operationId}.json. Si falla el journal no escribe el original. No sobrescribe una operación ya existente.
4. Escribe el stream privado y verifica el tamaño resultante. Retorna disk/path/operation_id. Una escritura parcial fallida intenta retirar su propio destino; si tampoco puede limpiarlo, conserva el journal y propaga el error.
5. La transacción inserta Evidence y auditoría. No se reintenta automáticamente un callback de DB que haya producido escrituras de archivos.
6. Tras commit, acknowledgeWritten(refs) retira exclusivamente journals. Si falla este paso, la evidencia confirmada y su original se conservan para una reconciliación posterior.
7. Ante un fallo, EvidenceRecoveryService adquiere nuevamente el mismo lock y consulta referencedStoragePath, incluyendo evidencias anuladas. Si la ruta está referenciada, conserva el original y cierra el journal; si no, compensa archivo y journal. Con DB inaccesible o estado incierto no elimina el objeto.

deleteWritten(refs) intenta todas las referencias aunque una falle, informa del fallo agregado y conserva los journals no resueltos. Es idempotente cuando archivo y journal ya no existen. Se niega a borrar un archivo existente sin journal verificable, porque puede tratarse de una evidencia ya confirmada.

El journal previo también permite detectar una interrupción del proceso entre Filesystem y DB. El lock serializa la recuperación contra una transacción todavía abierta; no se utiliza una edad arbitraria del archivo para decidir si puede borrarse.

## Recuperación operativa explícita

pendingOperations() enumera únicamente journals válidos del disco configurado; findPending(operationId) consulta uno por UUID. No escanean evidencias existentes para inferir orfandad.

~~~bash
docker compose exec -T app php artisan evidences:reconcile-upload UUID_DEL_INTENTO
~~~

El UUID se obtiene del evento operativo de carga pendiente. El comando es una acción explícita: usa EvidenceRecoveryService y el lock PostgreSQL descrito arriba, no borra archivos con referencias confirmadas, incluidas SoftDeletes. Si DB o almacenamiento no están disponibles, conserva el intento y devuelve un error comprensible para reintentarlo posteriormente. Un journal manipulado o corrupto requiere revisión; no se interpreta como una ruta autorizada.

No hay scheduler de eliminación, garbage collector genérico ni política de retención implementada. SoftDelete de Evidence conserva siempre el objeto físico y no pasa por deleteWritten. Restaurar requiere que exists confirme el original. La futura purga permanente necesita una política distinta y autorizada.

## Verificación y alcance

Las pruebas unitarias ejecutadas pasaron: ImageMetadataServiceTest y EvidenceStorageServiceTest, 54 casos y 113 aserciones, sin acceso DB. Cubren imágenes reales de los tres formatos, MIME/extension, corrupción, casos concretos de bytes añadidos, tamaño, píxeles/memoria, dimensiones/checksum, nombre hostil, rutas, journal previo, fallo parcial, limpieza fallida, compensación de lote, idempotencia, protección de originales confirmados y lectura mediante stream. Pint aprobó los siete archivos PHP de este componente.

Las verificaciones DB de propietario/tenant, integración HTTP, permisos, restauración y persistencia real tras restart pertenecen a la integración de [evidencias](evidences.md) y su informe [Fase 8](phase-8.md). Este resultado unitario no sustituye esas comprobaciones.

## Prueba real tras reiniciar Docker

La verificación utilizó una DB nueva y aislada, sysforest_phase8_persistence, y un directorio exclusivo dentro del volumen storage_data: storage/app/private/evidences-phase8-persistence. Se comprobó ausencia previa de la base, se registró un marcador de creación con run_id `8ec7ebc4-2ddb-421f-b839-d9c71cf52651` y se ejecutaron migraciones únicamente allí. No se ejecutó roles.sql ni se alteraron los roles o datos de sysforest/sysforest_testing.

Un actor temporal autorizado creó mediante servicios reales un Tree, una TreeInspection y dos Evidence. Las imágenes JPEG/PNG fueron generadas con GD, con fondo de color y el texto PRUEBA TECNICA FASE 8; no representaban individuos reales ni se incorporaron al producto. Una se vinculó a Tree con EVD_WHOLE_TREE y otra a TreeInspection con EVD_FAUNA. La transacción quedó confirmada antes de reiniciar; no se utilizó Storage::fake.

Después del restart y up -d coordinados, nuevos contenedores one-off de app y queue comprobaron:

- Los mismos UUID, propietario, tipo, fecha capturada, caption, autor, versión y timestamps.
- Todos los atributos privados de persistencia, incluidos disk, path y metadata.
- Streams completos con tamaño y SHA-256 idénticos, sin journals pendientes.
- Lectura con UID 1000 en ambos servicios. Propietario/grupo de archivos y directorios: 1000/1000; directorios 0700 y originales 0600.

| Archivo técnico | Tamaño | Dimensiones | SHA-256 |
| --- | ---: | --- | --- |
| JPEG de Tree | 3.899 bytes | 320 × 160 | 3e23f7b1faccf530cf59a70d27cc2ecdb18e4e3873657de9680429e1b489c28a |
| PNG de TreeInspection | 953 bytes | 320 × 160 | 56d72e6976c53d9fbf9f6b7bd5d7f2231049309eff36f3b6936c8e8d64006e2b |

La limpieza verificó el marcador de la DB y sus conteos 1 Tree / 1 TreeInspection / 2 Evidence antes de eliminar exclusivamente esa base, sin FORCE. Solo después de comprobar su ausencia se retiraron los dos archivos cuyo hash coincidía, el marcador privado y sus directorios vacíos. No se eliminaron volúmenes ni se deshabilitaron triggers. El directorio temporal ya no existe. La comprobación final de sysforest devolvió **0 árboles, 0 inspecciones y 0 evidencias**; no quedaron fixtures forestales permanentes.

Los comprobantes sin credenciales están fuera del proyecto, en el área temporal Windows .phase8-work: persistence-creation-marker.json, persistence-proof-full.json, persistence-before-restart.json, persistence-after-restart.json y persistence-cleanup.json. Los scripts de esa ejecución son deliberadamente específicos de su UUID; no deben reutilizarse sin preparar una nueva ejecución aislada.

Los comandos de una ejecución equivalente deben mantener esta separación:

~~~bash
# Migrar exclusivamente la DB temporal ya creada y marcada.
docker compose run --rm --no-deps -T \
  -e DB_DATABASE=sysforest_phase8_persistence -e DB_URL= \
  init php artisan migrate --force --no-interaction

# Ejecutar el comprobador revisado por stdin, con el mismo disco aislado.
# Sustituir app por queue para comprobar el segundo usuario de servicio.
docker compose run --rm --no-deps -T \
  -e DB_DATABASE=sysforest_phase8_persistence -e DB_URL= \
  -e APP_ENV=testing -e CACHE_STORE=array \
  -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  app php < RUTA_DEL_COMPROBADOR_REVISADO.php
~~~

El provisionamiento utiliza el servicio db-init con un script acotado que aborta si la DB ya existe, lee el secreto desde su archivo y ejecuta solo la creación/permisos de la nueva base. La eliminación es otro script con nombre exacto, marcador y conteos comprobados; no se ofrece aquí un comando genérico para borrar bases. La recuperación ordinaria de cargas continúa usando evidences:reconcile-upload y no este procedimiento de fixtures.


# Política de datos del portal público

## Alcance de la Fase 11

El portal SIGAU consulta información forestal expresamente autorizada, sin usuario, JWT, sesión institucional ni TenantContext. La administración permanece privada. Las rutas anónimas se separan de las rutas institucionales; conocer un UUID o código de un registro privado no concede acceso.

Esta política es una lista de inclusión. **Todo campo no autorizado expresamente se considera PRIVADO.** Los Resources públicos preparan arrays propios y nunca serializan modelos, relaciones completas, pivotes o auditorías. Las mismas restricciones se aplican a HTML, JSON, puntos, búsqueda, conteos, indicadores, límites geográficos, clusters y calor.

- **PÚBLICO:** campo autorizado para un registro que ya satisface su cadena de publicación; no significa que todos los registros de ese modelo sean públicos.
- **CONDICIONAL:** requiere una autorización o condición adicional indicada en la tabla.
- **PRIVADO:** excluido de todas las proyecciones públicas de esta fase.

No se crean exportaciones ni informes descargables en Fase 11. El GeoJSON de dibujo del mapa es una respuesta paginada o acotada de consulta, no una descarga masiva del inventario.

## Cadena de publicación

La migración 000017 incorpora `is_public=false` y `publication_version=1` a Organization, Inventory, Tree, TreeInspection y Evidence. No publica datos existentes ni habilita automáticamente el piloto. Los nuevos registros permanecen privados aunque su organización ya sea pública.

| Registro | Condiciones para aparecer |
| --- | --- |
| Organization | Autorizada, activa, sin SoftDelete; municipio, departamento y país activos; public_code reservado. |
| Inventory | Organización publicable, autorización propia, active=true y status distinto de draft. Closed y archived pueden conservar historia si siguen disponibles. |
| Tree | Organización publicable, autorización propia, sin SoftDelete y al menos una participación en Inventory publicable de la misma organización. |
| TreeInspection | Tree publicable, autorización propia; si inventory_tree_id existe, esa campaña concreta también debe ser publicable. Sin campaña asociada no se inventa una participación. |
| Maintenance | Tree publicable, orden sin SoftDelete y estado executed o verified. No tiene un flag nuevo de publicación. |
| Evidence | Autorización propia, sin SoftDelete y cadena propietaria completa: Tree publicable; o inspección publicable; o mantenimiento ejecutado/verificado no anulado de un Tree publicable. |

Tener otra campaña pública no publica una inspección vinculada a una campaña privada. El filtro de campaña también exige una campaña publicable: no basta con que el árbol tenga cualquier otra participación pública.

Ocultar una organización o árbol retira sus dependientes del universo anónimo en la siguiente consulta. Ocultar una inspección retira esa visita y sus evidencias. Ocultar un inventario retira sus inspecciones vinculadas, pero el árbol puede seguir publicado si participa en otra campaña pública elegible; en ese caso también pueden seguir visibles sus visitas independientes, mantenimientos realizados y fotografías autorizadas del árbol. Las banderas dependientes se conservan; no se destruyen fotos ni historia. Reactivar o volver a publicar un propietario puede hacer nuevamente visibles a los hijos cuya autorización siga vigente. Para retirar permanentemente la autorización de un hijo, debe ocultarse también ese registro.

`active`, `is_public`, SoftDelete y estado biológico son conceptos distintos. Dead, felled, transplanted, removed y replaced no son anulación administrativa: un individuo histórico puede seguir siendo público. Un Tree anulado nunca aparece, independientemente de su estado o de sus banderas. Restaurar un Tree no restaura evidencias anuladas por separado.

## Administración y control de cambios

El permiso `publication.manage` se concede a ORGANIZATION_ADMIN y SUPER_ADMIN. Se exige una organización seleccionada y acceso vigente a ella. SuperAdmin no recibe membresías artificiales ni publica nacionalmente mediante un parámetro del navegador. Official y DepartmentAdmin no pueden publicar ni modificar las descripciones públicas.

GET `/publication` prepara las cinco vistas administrativas. PATCH `/publication/organization` usa exclusivamente el tenant actual; los cuatro grupos hijos reciben UUID internos en rutas privadas. El servidor revalida actor, membresía/concesión, cuenta y organización; resuelve el sujeto dentro del tenant y bloquea actor → organización → Tree → propietario intermedio → Evidence cuando corresponde.

Los formularios exigen `publication_version`; una versión obsoleta devuelve 409. Las versiones de edición de los hijos también aumentan para invalidar formularios institucionales abiertos antes del cambio. Los validadores ordinarios de Organization, Inventory, Tree, TreeInspection y Evidence rechazan la modificación de campos de publicación.

`publication_revisions` guarda antes/después completos, actor, fecha UTC, organización, sujeto con FK real y versión. Un CHECK exige exactamente un sujeto; los hijos tienen FK compuesta con organization_id. El historial no admite UPDATE ni DELETE. La revisión y el evento mínimo `publication.changed` se confirman en la misma transacción. Descripciones públicas anteriores quedan únicamente en esta auditoría privada.

Autorizar un registro no convierte el texto interno en público. Las ediciones posteriores de los campos forestales estructurados permitidos de un registro ya autorizado se reflejan en el portal mientras mantenga su autorización; no hay un flujo adicional de aprobación por cada corrección. Las nuevas inspecciones y fotografías sí nacen privadas y requieren autorización propia.

## Identidad, referencias y URLs

| Campo o referencia | Clasificación | Regla |
| --- | --- | --- |
| Organization.public_code | PÚBLICO | Único global, fijado desde code en la primera publicación e inmutable aunque cambie el código administrativo o se oculte. |
| Organization.code | PRIVADO como identidad de ruta | El portal usa public_code; pueden coincidir inicialmente, pero no se deduce uno del otro después. |
| Tree.code | PÚBLICO | Estable e institucional. La URL combina public_code de organización y código de árbol para resolver colisiones. |
| Inventory.code | CONDICIONAL | Solo campañas publicables; los filtros usan organization.public_code~inventory.code. |
| Evidence.public_id | CONDICIONAL | ULID reservado al publicar, distinto del UUID interno; identifica únicamente la ruta controlada de imagen. |
| UUID internos, organization_id y claves foráneas | PRIVADO | Se utilizan en SQL, no se devuelven como identidad anónima. |
| Código territorial o de catálogo | PÚBLICO | Referencia segura del filtro; departamento COUNTRY~DEPARTMENT y municipio COUNTRY~DEPARTMENT~MUNICIPALITY. |
| is_public, publication_version, lock_version | PRIVADO | El usuario anónimo recibe únicamente registros elegibles, no detalles del control de publicación. |

Ficha: `/portal/organizations/{public_code}/trees/{tree_code}`. Foto: `/portal/media/{public_id}`. No se cambia la PK de ningún modelo.

Un código institucional antiguo sigue reservado en public_code. Si otra organización reutiliza ese código administrativo, su primera publicación se rechaza con un mensaje claro; no se reasigna la URL antigua.

Los formularios públicos mantienen nombres de filtros como organization_id por compatibilidad con el componente común, pero **sus valores son códigos públicos, no UUID**. El backend resuelve referencias dentro del universo publicable antes de consultar.

## Organization, Inventory y territorio

| Campo | Clasificación | Proyección actual |
| --- | --- | --- |
| Organization.name, public_code | PÚBLICO | Entidad institucional y referencia pública; no el personal. |
| País, departamento y municipio: nombre/código | PÚBLICO | Jerarquía activa de organizaciones publicables. |
| Geometrías oficiales territoriales | CONDICIONAL | Encuadre y límites autorizados, con los límites técnicos del mapa; no deducidas de registros privados. |
| Inventory.name, código compuesto | CONDICIONAL | Opciones de campañas publicables. |
| Organization.short_name, type | PRIVADO en esta proyección | No son necesarios para la respuesta actual. |
| Organization.email, phone, settings, logo_path | PRIVADO | No existe una decisión de publicación de contactos o configuración institucional. |
| Inventory.description, notas de participación | PRIVADO | Texto operativo sin autorización pública independiente. |
| Inventory.created_by/updated_by, fechas de edición y versiones | PRIVADO | Procedencia administrativa. |
| Membresías, usuarios, responsables y roles | PRIVADO | No forman parte del portal. |

Los catálogos nacionales autorizados se muestran con nombres/códigos, no sus atributos técnicos completos. Las referencias históricas válidas pueden conservar nombres de catálogo actualmente inactivos; la inactividad del catálogo no reescribe una observación publicada.

## Tree

| Campo | Clasificación | Regla |
| --- | --- | --- |
| code | PÚBLICO | Identifica el individuo dentro de su organización pública. |
| Nombre común, nombre científico y familia | PÚBLICO | Catálogos asociados; no se inventa un nombre cuando falta. |
| Hábito, origen de establecimiento y biogeográfico | PÚBLICO | Etiquetas de catálogos existentes. |
| Emplazamiento | PÚBLICO | Clasificación de entorno, no notas internas. |
| current_status y etiqueta | PÚBLICO | Estado forestal real. |
| is_protected, is_heritage | PÚBLICO | Banderas forestales declaradas. |
| geometry | CONDICIONAL | Point existente de un Tree publicable. Si falta, se devuelve null, sin coordenadas ficticias. |
| location_description, external_number | PRIVADO | Texto o referencia interna no aprobados para publicación. Tampoco participan en q público. |
| coordinate_raw, coordinate_format, datum | PRIVADO | Metadata técnica de captura. |
| accuracy_m, gps_brand, gps_model, captured_at técnico | PRIVADO | Procedencia GPS sin necesidad pública. |
| created_by, updated_by, deleted_by, restored_by | PRIVADO | Personal interno. |
| created_at, updated_at, restored_at, lock_version | PRIVADO | Información de gestión. |
| deletion_reason_id, deletion_notes y notas de cambios | PRIVADO | Anulación o justificación operativa. |
| TreeStatusHistory y TreeLocationHistory completos | PRIVADO | No se serializan historiales de auditoría; el historial público se prepara con inspecciones y mantenimientos permitidos. |

La búsqueda general pública consulta código, nombre común y científico. No permite inferir textos privados por coincidencias, conteos o resultados vacíos.

## TreeInspection y última visita publicada

La última visita pública se selecciona **antes de aplicar filtros de mediciones o condiciones**, usando el mismo orden determinista del motor privado: inspection_date DESC, created_at DESC, id DESC. El universo de candidatas se restringe previamente a inspecciones publicables. La UI la identifica como «última inspección publicada», sin revelar si existe una visita privada posterior.

Una campaña privada no aporta datos a esa última visita ni a sus condiciones ANY/ALL. El mapa, la tabla, la ficha y los indicadores comparten esta semántica; no se elige una visita más antigua solo porque satisfaga un filtro.

| Campo | Clasificación | Regla |
| --- | --- | --- |
| inspection_date | CONDICIONAL | Fecha de inspección publicable. |
| cap_cm, number_of_stems, form_factor | CONDICIONAL | Valores existentes; no se agregan supuestos para múltiples fustes. |
| total_height_m, commercial_height_m, crown_x_m, crown_y_m | CONDICIONAL | Mediciones existentes de visita publicable. |
| dap_m, radius_m, basal_area_m2 | CONDICIONAL | Derivados guardados; decimales conservados como cadenas. |
| commercial_volume_m3, total_volume_m3 | CONDICIONAL | Resultados guardados, sin recálculo diferente para Internet. |
| diameter_class_number, height_class_number, madurez | CONDICIONAL | Clasificación existente de la visita publicada. |
| Condiciones físicas y fitosanitarias | CONDICIONAL | Nombres de los catálogos relacionados; sin pivotes ni IDs técnicos. |
| Fauna asociada | CONDICIONAL | Nombres de categorías existentes. |
| flowering_date, fruiting_date | CONDICIONAL | Fechas declaradas, cuando existen. |
| flowering_development, fruiting_development | PRIVADO | Texto libre; no representa un catálogo de estados fenológicos aprobado. |
| general_observations, fauna_observations | PRIVADO | No hay distinción formal entre observación pública e interna. |
| classification_rule_version_id, calculation_rule_version | PRIVADO | Identidad técnica de cálculo; no necesaria en la proyección ciudadana. |
| Autor, editor, created_at, updated_at, versiones | PRIVADO | Procedencia y auditoría. |
| inspection_revisions y motivos/comentarios internos | PRIVADO | La corrección no publica el historial administrativo. |

El historial presenta visitas publicables paginadas, no revisiones internas. La anulación formal de una inspección sigue pendiente de definición.

## Maintenance

| Campo | Clasificación | Regla |
| --- | --- | --- |
| Tipo nacional y etiqueta | CONDICIONAL | Orden ejecutada/verificada, sin anulación y de Tree publicable. |
| status, status_label | CONDICIONAL | Únicamente executed y verified. |
| performed_date | CONDICIONAL | Fecha real de ejecución, presentada como performed_at sin hora inventada. |
| pending, scheduled, assigned, in_progress, cancelled | PRIVADO | Fuera del historial y de los filtros públicos de órdenes. |
| code, UUID, assigned_to, created_by, updated_by | PRIVADO | Identidad y personal de operación. |
| scheduled_date, priority | PRIVADO | Planificación interna. |
| notes, result, motivos de cancelación/anulación | PRIVADO | Texto operativo aunque la orden esté ejecutada. |
| Revisiones e historial de transiciones | PRIVADO | No se expone auditoría ni responsable. |

El predicado executed/verified se aplica dentro del mismo EXISTS usado por los filtros de mantenimiento. No se publican indirectamente órdenes pendientes mediante coincidencias, puntos o conteos.

No se añade un flag a Maintenance: al ejecutar/verificar una orden de un Tree publicable, su resumen mínimo pasa a ser elegible. Esta regla no publica sus fotografías automáticamente.

## Evidence y entrega de imágenes

| Campo o contenido | Clasificación | Regla |
| --- | --- | --- |
| Imagen WebP derivada | CONDICIONAL | Flag propio y cadena completa publicable; nunca el original. |
| URL controlada y tipo | CONDICIONAL | Solo para Evidence elegible. |
| public_caption | CONDICIONAL | Descripción aportada expresamente por administrador; máximo 2000 caracteres. No copia caption privado. |
| alt | CONDICIONAL | Descripción pública o etiqueta del tipo. |
| captured_at | CONDICIONAL | Fecha declarada; se devuelve solo la fecha, sin inferirla de EXIF. |
| width, height | PRIVADO como dimensiones originales | El Resource actual devuelve null: orientación EXIF puede intercambiarlas; el navegador conoce las dimensiones del derivado. |
| caption, notes, deletion_notes | PRIVADO | No hay fallback hacia estos textos. |
| original_name, mime_type/extension originales, size_bytes | PRIVADO | No son necesarios para la galería pública. |
| disk, path, checksum_sha256, metadata | PRIVADO | Procedencia física e integridad del original. |
| uploaded_by, updated_by, deleted_by, fechas administrativas | PRIVADO | Personal y operaciones internas. |
| EXIF, XMP, IPTC, comentarios PNG/JPEG, perfiles | PRIVADO | El archivo público se construye a partir de píxeles decodificados. |
| Original descargable y evidence_revisions | PRIVADO | Continúan bajo las rutas y permisos de Fase 8. |

PublicEvidenceDeliveryService consulta elegibilidad antes de leer el caché y antes de cualquier respuesta 304. GD decodifica la imagen, aplica las ocho orientaciones JPEG EXIF cuando corresponde y codifica un WebP nuevo. EXIF se utiliza solo para orientación; no se publica ni se copia a la salida. El original, su checksum y su historial permanecen intactos.

El derivado se guarda en caché de archivos del servidor con namespace public-image-v1; la clave incluye identidad interna, checksum original y calidad. No es un directorio público ni una URL física. La caducidad configurada por defecto es 3600 segundos; **tener bytes cacheados no evita revalidar la autorización en cada solicitud**.

HTTP entrega image/webp, filename genérico arbol.webp, nosniff, ETag calculado sobre el derivado y `Cache-Control: public, max-age=0, must-revalidate`. Un cliente puede reutilizar bytes tras un 304 autorizado; si se oculta cualquier eslabón, la petición posterior devuelve 404 incluso con ETag válido. Una copia que alguien ya descargó no puede retirarse de su dispositivo.

HTML y API usan no-store. No se comparte caché de respuestas privadas con públicas y no se abre CORS global para este portal de la misma aplicación.

## Límites y verificación

Las opciones y referencias anónimas solo enumeran entidades publicables. Las consultas usan valores ligados y campos de orden predefinidos. El mapa conserva bbox, agregación de densidad, límites de puntos y avisos de resultados; no utiliza árboles privados para indicadores o encuadre. El calor expresa concentración, no una categoría inventada de riesgo.

Las pruebas de publicación verifican permisos, aislamiento A/B, revocación, conflictos, auditoría atómica y constraints. Las pruebas de entrega pública verifican originales intactos, metadatos retirados, orientación, transparencia, caché y autorización antes de 304. Las pruebas del portal verifican proyecciones sin personal y coherencia del motor común. Los resultados ejecutados se registran en el informe de cierre, no se presuponen en esta política.

Permanecen las deudas anteriores: semántica completa de múltiples fustes, estados fenológicos estructurados, anulación formal de inspecciones y notificaciones de mantenimiento. Publicar datos no resuelve ni altera esas definiciones.

Véanse [evidencias](evidences.md), [mantenimientos](maintenances.md) y [mapa interno y motor compartido](maps.md).

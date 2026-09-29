# Auditoría privada

La Fase 14 consolida el historial privado de SYSFOREST en `/audit`. El flujo es `AuditController → AuditReadService → AuditReadRepository → datos → Blade`. `AuditAccessService` resuelve y revalida la autorización; `AuditFilterService` valida los filtros y `AuditPresentationService` prepara una proyección explícita para las vistas.

## Acceso

El permiso `audit.view` corresponde exclusivamente a `SUPER_ADMIN` y `ORGANIZATION_ADMIN` en la matriz inicial. El administrador nacional puede consultar el alcance nacional, incluidos eventos sin institución e instituciones desactivadas. El administrador institucional consulta únicamente la organización activa autorizada. Los roles OFFICIAL y DEPARTMENT_ADMIN no reciben este permiso; el modo `department_read` tampoco lo convierte en una capacidad institucional.

La ruta exige JWT vigente, sesión activa, correo verificado y autorización. La lectura del Service vuelve a comprobar el permiso después de consultar. El detalle mantiene el alcance solicitado: una entrada ajena devuelve 404, sin ampliar el alcance para buscarla. El límite de lectura es 90 solicitudes por minuto y usuario. No hay rutas públicas, exportaciones ni operaciones de modificación de auditoría.

## Fuentes y fechas

El lector une `security_events`, `inspection_revisions`, `evidence_revisions`, `maintenance_revisions`, `publication_revisions`, `tree_status_histories` y `tree_location_histories`. Una revisión se omite de la lista solo si existe un evento con `revision_id`, institución, recurso y acción coincidentes y con valores capturados. Las fechas iguales nunca se utilizan para deduplicar.

La consulta comienza con los últimos 30 días UTC, inclusive. Se puede cambiar el intervalo. El límite superior se implementa como el inicio exclusivo del día siguiente para conservar los microsegundos. Se admiten filtros por nombre actual del actor, acción exacta, tipo e identificador del recurso y, en alcance nacional, organización. La página contiene 25 entradas ordenadas por fecha, fuente e identificador; los filtros se conservan al paginar. El detalle no depende del intervalo inicial de fechas.

Los nombres de usuario e institución se consultan en su estado actual y la pantalla lo indica. Las identidades originales se conservan en la base. Un evento anterior que no guardaba valores mantiene NULL: la interfaz explica esa ausencia y no reconstruye un supuesto estado histórico. Un objeto vacío representa el estado previo de una creación; no equivale a un detalle nunca capturado.

## Captura y protección

`SecurityEventRepository::recordChange` exige la transacción de la operación. Si falla la auditoría, se revierte también el cambio de negocio. El evento conserva actor, institución cuando corresponde, acción, tipo e identificador del recurso, fecha y valores anteriores/posteriores. `AuditSnapshot` define una lista explícita de atributos por recurso, aplicada al guardar y al leer. Añadir un atributo a un modelo no lo publica automáticamente en auditoría.

Se documentan cambios de organizaciones, catálogos, identidad y roles, membresías, invitaciones, inventarios, participaciones, árboles, inspecciones, evidencia, mantenimiento, publicación e importaciones. Las revisiones forestales existentes permanecen vinculadas por UUID. Las credenciales, tokens, correos, teléfonos, configuración interna, rutas de archivos, imágenes y EXIF no forman parte de los atributos capturados. El contexto utiliza un UUID de correlación generado por el servidor; no copia cabeceras, cookies ni IP del cliente. Blade escapa los textos y conserva diferencias entre nulo, vacío, cero y falso.

PostgreSQL rechaza UPDATE y DELETE de `security_events`. Las claves foráneas restringen borrar físicamente al actor o institución referenciados. Los historiales forestales conservan sus restricciones previas; inventarios rechazan borrado físico y participaciones `inventory_trees` rechazan modificación y borrado. Las anulaciones funcionales mantienen la trazabilidad mediante las reglas existentes.

No se fabrican actores humanos para seeders o utilidades administrativas ejecutadas por CLI. La revisión no es una certificación externa ni prueba de penetración. La integridad protege contra las operaciones de la aplicación; un administrador de base con capacidad de cambiar triggers conserva sus privilegios administrativos.

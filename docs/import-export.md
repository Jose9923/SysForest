# Plantilla e importación Excel de SYSFOREST

La Fase 13 incorpora la plantilla oficial `SYSFOREST_IMPORT_V1` y un flujo institucional de revisión y confirmación. Cada fila válida crea un **árbol nuevo**, lo vincula al inventario seleccionado mediante `inventory_trees` y registra su primera inspección. La carga inicial no crea árboles ni consume consecutivos.

La documentación de reportes y exportaciones de Fase 12 permanece en [reportes y exportaciones](reports-and-exports.md) y en [su matriz de campos](exports-field-mapping.md). Un archivo exportado no sustituye la plantilla de importación: son contratos distintos. La correspondencia de las 110 columnas de entrada con las 94 variables del instrumento original se detalla en [trazabilidad de importación](import-field-mapping.md).

## Flujo de uso

1. Inicia sesión y selecciona la organización activa. Abre **Importaciones**.
2. Descarga la plantilla oficial desde ese módulo. Sus catálogos reflejan las referencias disponibles al generarla.
3. Diligencia únicamente la hoja `Datos`, desde la fila 2, conservando los encabezados, sus posiciones y el formato Texto.
4. Selecciona una campaña activa de la organización y carga el `.xlsx`. El destino institucional proviene de la sesión y del formulario autorizado, no de celdas del libro.
5. Revisa el lote: cantidades totales, filas válidas, errores por fila y cálculos del servidor. El número mostrado corresponde a la fila real de `Datos`, aunque haya filas vacías intermedias.
6. Si hay errores, corrige el archivo y cárgalo como un nuevo lote. No existe confirmación parcial ni edición de las celdas almacenadas desde el navegador.
7. Para un lote válido, marca la confirmación de revisión y de inventario de destino. **Confirmar importación** vuelve a autorizar y validar todo antes de guardar.
8. Consulta el resultado y los vínculos al árbol y a su inspección. Las fotografías se cargan posteriormente desde **Evidencias**. Los nuevos registros permanecen privados hasta una publicación explícita.

Puede cancelarse un lote pendiente o con errores. Cancelarlo conserva el archivo descrito por su nombre y huella, las filas leídas y sus diagnósticos; no elimina la trazabilidad. Un lote importado no puede cancelarse mediante este flujo. La corrección de registros creados por error utiliza los mecanismos forestales existentes, incluida la anulación autorizada cuando corresponda.

## Contrato de la plantilla

El libro contiene exactamente cuatro hojas visibles en este orden:

| Hoja | Función |
| --- | --- |
| `Datos` | Encabezados técnicos exactos y 110 columnas en orden fijo. Es la única hoja para ingresar registros. |
| `Diccionario` | Etiqueta española, grupo, modo, tipo, obligatoriedad, referencia original e instrucción de cada columna. |
| `Catalogos` | Códigos y nombres de las referencias disponibles; se copian códigos, no etiquetas. |
| `Instrucciones` | Identificador de versión y guía de diligenciamiento. La primera fila identifica `SYSFOREST_IMPORT_V1`. |

Las filas completamente vacías se omiten. El lector no recorta un archivo que supere los límites: lo rechaza completo. Cambiar encabezados, añadir columnas con datos, agregar hojas o alterar la versión requiere corregir el libro. Las columnas grises se conservan por trazabilidad y deben permanecer vacías.

Los catálogos incluidos en Excel son una ayuda de captura. Editar esa hoja no crea, modifica ni autoriza referencias nacionales; cada código se resuelve nuevamente contra la base al cargar y confirmar. También deben seguir disponibles los padres correspondientes, como familia o categoría de condición.

No se aceptan archivos `.xls`, `.xlsm`, CSV ni libros arbitrarios; tampoco macros, imágenes, comentarios, objetos incrustados, celdas combinadas, hipervínculos, conexiones externas, libros cifrados o fórmulas. Una fórmula en `Datos` se informa como error de su fila y nunca se evalúa, aunque Excel haya guardado un resultado calculado. Los errores de estructura del libro impiden crear una vista previa utilizable.

## Identidad, códigos y duplicados

`code` es opcional. Si queda vacío, `TreeCodeService` asigna el siguiente código institucional al confirmar. Si se indica, debe utilizar el prefijo vigente de la organización y un consecutivo positivo canónico: ancho mínimo de seis dígitos, sin ceros adicionales. Por ejemplo, para un prefijo ilustrativo `ORG`, `ORG-000125` es canónico; `ORG-0000125` no lo es. El prefijo real proviene de la organización activa.

Un código explícito se conserva. Antes de crear cualquier fila se reservan todos los códigos explícitos del lote y el consecutivo institucional avanza, como mínimo, hasta el mayor de ellos. Esto evita que una fila con código automático consuma un código explícito posterior. Por tanto, importar un código alto puede dejar un salto en la secuencia; los siguientes códigos automáticos continúan después del máximo reservado, no rellenan ese salto. La reserva se revierte si falla la transacción completa.

Una colisión con otro árbol de la misma organización —incluido un árbol anulado— bloquea la importación. También se marcan las filas que repiten un código dentro del libro. No se actualiza, reemplaza, fusiona ni restaura un árbol por coincidencia de código.

`external_number` conserva el número legado como texto, incluidos los ceros iniciales. No es único ni constituye un criterio de identidad: dos filas con el mismo número legado y `code` vacío pueden crear dos individuos distintos. El usuario debe revisar el contenido antes de confirmar.

La huella SHA-256 impide confirmar nuevamente **el mismo archivo binario** si ya existe un lote completado de esa organización; también se comprueba antes de aceptar una nueva carga. Dos copias cargadas antes de confirmar no se importan ambas: después de confirmar la primera, la segunda se cancela al detectar su huella ya completada. Esta protección no es una estrategia avanzada de duplicados: volver a guardar un libro puede cambiar su huella, y no se buscan equivalencias por coordenadas, especie, texto o número legado. La resolución avanzada continúa fuera del MVP.

## Datos forestales y precisión

- `current_status` e `inspection_date` son obligatorios por fila. La fecha de la primera inspección debe declararse; no se inventa a partir de la fecha de carga.
- Las fechas de inspección y fenología se escriben como texto `AAAA-MM-DD`. No se aceptan fechas seriales de Excel. Para `captured_at`, utiliza una fecha y hora ISO 8601 con zona, por ejemplo `2026-01-31T10:30:00-05:00`.
- Las medidas se ingresan como texto con punto decimal, sin separadores de miles ni notación científica. Los campos dasométricos admiten hasta seis decimales; las entradas fuera del contrato se rechazan sin redondearlas. La ausencia de una medida permanece `NULL`; no se convierte en cero.
- CAP y altura total deben ser positivos cuando se informan. El número de fustes debe ser un entero positivo dentro del rango BIGINT. Factor de forma admite de `0.45` a `0.70`. La altura comercial no puede superar la altura total cuando ambas se informan.
- `is_protected`, `is_heritage` y las 65 columnas `condition_…` usan `0`, `1` o vacío. En una condición, `1` selecciona; `0` y vacío no seleccionan. Una celda vacía no acredita que se haya examinado la ausencia de una condición.
- Las condiciones conservan la separación físico-mecánica/fitosanitaria y sus órganos. No se crean categorías o condiciones al importar.
- `fauna_category_codes` admite varios códigos exactos separados por `|`, sin repetirlos. Las observaciones de fauna se conservan por separado.
- La floración y fructificación conservan fecha y descripción de desarrollo independientes. No se inventan estados fenológicos ni equivalencias de madurez.

`coordinate_raw` admite latitud y longitud en grados decimales o DMS, mediante el mismo `CoordinateParserService` usado por la captura manual. Se conserva la expresión original y se persiste la posición normalizada en PostGIS con SRID 4326. Se utiliza el datum declarado cuando se conoce; no se deduce de la marca del GPS ni se transforma automáticamente otro sistema de referencia. La exactitud y los datos del dispositivo siguen las validaciones de `TreeLocationService`.

Se dejan vacíos nombre común, nombre científico y familia: se obtienen de `species_code`. DAP, radio, área basal, volúmenes, clases y madurez también quedan vacíos en Excel. `DendrometryCalculationService` recalcula los derivados con la aritmética y las reglas versionadas del dominio; no utiliza valores calculados enviados por el usuario. Las columnas de fotografías no reciben URLs ni imágenes; no se descargan recursos externos.

## Confirmación y concurrencia

`ImportConfirmationService` abre una transacción que incluye la reserva de códigos, los árboles, las participaciones en campaña, las inspecciones, las filas importadas, el estado del lote y la auditoría. Una excepción revierte el conjunto. El procesamiento es síncrono: no crea trabajos de cola ni importa filas en transacciones independientes.

Antes de escribir, el servicio recupera al actor vigente, reautoriza el tenant, bloquea organización/lote/inventario y vuelve a validar las filas originales. Comprueba códigos, catálogos, campaña activa, versión de plantilla, vencimiento, tamaño del lote y versión de revisión (`lock_version`). Las referencias de catálogo utilizadas se bloquean durante la validación transaccional.

Si aparecen errores, el lote pasa a `invalid` con diagnósticos actualizados y ninguna fila creada. Si las referencias o los resultados normalizados cambian sin producir errores, la vista previa se actualiza, incrementa su versión y exige **una segunda confirmación después de revisarla**. Las diferencias no se aceptan silenciosamente.

Una confirmación repetida de un lote `completed`, después de reautorizar al actor y su institución, devuelve el resultado existente sin crear registros adicionales. Las confirmaciones simultáneas se serializan mediante bloqueos. Una versión obsoleta devuelve conflicto. La cancelación de un lote ya cancelado también conserva su resultado anterior.

| Estado | Significado y acción |
| --- | --- |
| `ready` | Revisión válida; puede confirmarse mientras siga vigente y el actor conserve autorización. |
| `invalid` | Contiene errores; se corrige el archivo y se carga un lote nuevo. |
| `completed` | Todas las filas fueron importadas; resultado inmutable. |
| `cancelled` | No se importó ninguna fila; historial conservado. |

La vista previa tiene una vigencia configurable, de 24 horas por defecto. Al intentar confirmar una revisión vencida o de otra versión de plantilla, el servicio cancela ese lote y solicita cargar nuevamente el archivo. La caducidad no elimina automáticamente el historial.

## Acceso institucional y privacidad

Las rutas requieren JWT de sesión, cuenta activa con correo verificado y organización activa autorizada. `ImportAccessService` aplica `imports.view` o `imports.create`, además de los permisos forestales necesarios. Leer lotes y descargar la plantilla requiere consultar árboles, inspecciones e inventarios; cargar, confirmar o cancelar requiere también crear árboles e inspecciones.

| Rol vigente | Acceso predeterminado |
| --- | --- |
| `OFFICIAL` | Consulta, plantilla, carga, confirmación y cancelación en su organización autorizada. |
| `ORGANIZATION_ADMIN` | Las mismas operaciones dentro de la organización. |
| `SUPER_ADMIN` | Operaciones sobre la organización activa autorizada; no se fabrica una membresía. |
| `DEPARTMENT_ADMIN` en lectura departamental | No puede consultar ni operar lotes internos. |
| Anónimo o cuenta sin acceso vigente | Sin acceso al módulo. |

La colaboración no se limita al creador del lote: otro usuario actualmente autorizado de la misma institución puede revisarlo y confirmarlo. Se conservan por separado creador y confirmador. No existe importación pública ni un alcance nacional que reúna lotes de múltiples instituciones en la misma carga.

Los repositorios resuelven UUID junto con `organization_id`. Las claves foráneas compuestas enlazan lote, inventario, fila, árbol e inspección dentro de la misma institución. La base también comprueba que la inspección importada corresponda al árbol registrado. Los formularios no permiten sustituir institución, autores, ruta de almacenamiento o filas normalizadas desde la confirmación.

El archivo subido se lee desde el temporal de PHP y no se conserva como original en el almacenamiento de la aplicación. El lote guarda nombre, tamaño, SHA-256, versión, autores, fechas, contadores y estado. Cada fila guarda valores leídos, errores de lectura, valores normalizados, errores de dominio y referencias creadas. La identidad y los valores originales son inmutables; los lotes finalizados y sus filas quedan protegidos frente a cambios y borrados.

La auditoría registra `import.preview_created`, `import.preview_revalidated`, `import.completed` e `import.cancelled`, además de los eventos emitidos por los servicios forestales reutilizados. La plantilla descargable se prepara como archivo temporal privado y se elimina al enviarse; utiliza `ExportFileRepository` y su limpieza de temporales ya descrita en Fase 12.

## Límites y diagnóstico

La configuración está en `config/imports.php`. El formulario y la plantilla muestran los límites configurados, no un número calculado en JavaScript.

| Variable / clave | Valor predeterminado y función |
| --- | --- |
| `IMPORTS_MAX_ROWS` / `max_rows` | 100 filas con datos por lote. La medición local con las 65 condiciones por fila confirmó 34,3 s de escritura, dentro del timeout HTTP de 65 s; aumentar el límite requiere medir nuevamente. |
| `IMPORTS_MAX_UPLOAD_KB` / `max_upload_kb` | 5120 KiB, equivalentes a 5 MiB, por archivo. |
| `IMPORTS_PREVIEW_TTL_HOURS` / `preview_ttl_hours` | 24 horas de vigencia; el servicio aplica como mínimo una hora. |
| `IMPORTS_RATE_LIMIT` / `rate_limit` | 6 solicitudes por minuto y usuario para mutaciones, compartidas entre carga, confirmación y cancelación. |
| `max_uncompressed_bytes` | 32 MiB para el contenido total descomprimido del XLSX. |
| `max_zip_entries` | 200 componentes ZIP. |
| `max_cell_chars` | 32767 caracteres por celda; los campos de dominio pueden tener límites inferiores. |

`XlsxPackageGuard` inspecciona una lista cerrada de componentes sin extraerlos a disco, sin seguir enlaces y sin resolver entidades externas. Rechaza rutas ambiguas, entradas duplicadas, DTD, entidades, XML mal formado y estructuras no permitidas. `ImportWorkbookReader` conserva valores OOXML como cadenas para evitar conversiones intermedias a coma flotante. El contrato recomienda mantener las celdas como Texto porque Excel puede haber redondeado un número antes de guardar el archivo; el servidor no puede recuperar información ya perdida por el editor.

Los errores de fila muestran campo y mensaje junto con los valores recibidos escapados. Un error de archivo o de formulario devuelve `422`; falta de permiso `403`; recurso ajeno o no disponible `404`; conflicto de revisión `409`; exceso de solicitudes `429`. Los errores inesperados se presentan como `503` controlado sin SQL, rutas temporales ni trazas, incluso con depuración activada. El límite del servidor HTTP puede rechazar una carga excesiva antes del controlador con `413`.

## Rutas y responsabilidades

| Método y ruta | Nombre | Función |
| --- | --- | --- |
| `GET /imports` | `imports.index` | Historial institucional paginado. |
| `GET /imports/create` | `imports.create` | Selección de inventario y archivo. |
| `GET /imports/template` | `imports.template` | Descarga de la plantilla oficial. |
| `POST /imports` | `imports.store` | Validación y creación de la vista previa. |
| `GET /imports/{batch}` | `imports.show` | Errores, valores, cálculos y resultado del lote. |
| `POST /imports/{batch}/confirm` | `imports.confirm` | Confirmación explícita con versión de revisión. |
| `POST /imports/{batch}/cancel` | `imports.cancel` | Cancelación de un lote pendiente. |

El flujo mantiene `ImportController → ImportService → Services de caso de uso → Repositories → Models`. Los FormRequests validan el contrato HTTP y la autorización inicial. `ImportUploadService`, `ImportValidationService` e `ImportRowValidationService` preparan la revisión; `ImportConfirmationService` e `ImportRowWriteService` coordinan la confirmación; `ImportReadService` prepara datos y acciones de Blade. `ImportTemplateSchema`, `ImportTemplateService`, `XlsxPackageGuard` e `ImportWorkbookReader` definen, generan y leen el archivo oficial.

`TreeService`, `TreeInspectionService`, `TreeCodeService`, `TreeLocationService`, `PhenologyService` y `DendrometryCalculationService` se reutilizan para mantener las mismas reglas de captura. `ImportBatchRepository`, `ImportRowRepository` e `ImportCatalogRepository` encapsulan el acceso propio del módulo. Blade presenta los datos preparados sin consultas; Select2 solo facilita seleccionar el inventario.

Las pruebas relacionadas están en `ImportWorkbookTest`, `ImportTemplateTest`, `ImportRowValidationTest`, `ImportRowWriteTest`, `ImportWorkflowTest`, `ImportSecurityTest`, `ImportHttpTest` e `ImportPresentationTest`. Los comandos, resultados observados y límites prácticos de validación se documentan en el informe de cierre de Fase 13; esta guía describe el contrato y no sustituye esos resultados.

La importación no incorpora actualizaciones masivas, asociación masiva de individuos existentes a otra campaña, conciliación avanzada de duplicados, carga remota de fotos o libros de formato libre.

# Plantilla e importación Excel de SYSFOREST

La plantilla oficial en español `SYSFOREST_IMPORT_V2_ES` conserva la captura forestal incorporada en Fase 13 y un flujo institucional de revisión y confirmación. Cada fila válida crea un **árbol nuevo**, lo vincula al inventario seleccionado mediante `inventory_trees` y registra su primera inspección. La carga inicial no crea árboles ni consume consecutivos.

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

La versión actual contiene exactamente once hojas visibles en este orden:

| Hoja | Función |
| --- | --- |
| `Datos` | 110 encabezados en español, en orden fijo. Es la única hoja para ingresar registros, desde la fila 2. |
| `Guía de campos` | Explicación, obligatoriedad, formato, ejemplos y catálogo correspondiente de cada campo. |
| `Estados` | Códigos de estado del árbol; `active` corresponde a **Sano**. |
| `Especies` | Códigos, nombres científicos, familia y nombres comunes disponibles. |
| `Emplazamientos` | Códigos y nombres de emplazamiento. |
| `Hábitos de crecimiento` | Códigos y nombres de hábito. |
| `Origen de establecimiento` | Códigos de establecimiento, independientes del origen biogeográfico. |
| `Origen biogeográfico` | Códigos de procedencia biogeográfica. |
| `Fauna` | Categorías disponibles; se pueden separar varios códigos con `\|`. |
| `Condiciones` | Condiciones disponibles por evaluación y órgano. En Datos se marca `1`, `0` o vacío en su columna correspondiente. |
| `Instrucciones` | Guía general. Su primera fila identifica `SYSFOREST_IMPORT_V2_ES`. |

Los códigos de catálogo son identificadores exactos y no se traducen. Las listas desplegables muestran el nombre seguido del código entre corchetes, por ejemplo `Sano [active]`. Se conservan también las entradas con solo el código. La aplicación los resuelve contra los catálogos vigentes. Los encabezados sí están en español y se transforman internamente a las mismas claves técnicas del esquema forestal.

Se mantiene compatible la plantilla anterior `SYSFOREST_IMPORT_V1`, con sus cuatro hojas exactas `Datos`, `Diccionario`, `Catalogos` e `Instrucciones` y sus encabezados técnicos originales. No mezcle hojas, encabezados ni versiones de ambos formatos. Las vistas previas V1 vigentes siguen siendo confirmables y los bloques preparados por consola conservan su versión e identidad.

Las filas completamente vacías se omiten. El lector no recorta un archivo que supere los límites: lo rechaza completo. Cambiar encabezados, añadir columnas con datos, agregar hojas o alterar la versión requiere corregir el libro. Las columnas grises se conservan por trazabilidad y deben permanecer vacías.

Los catálogos incluidos en Excel son una ayuda de captura. Editar esas hojas no crea, modifica ni autoriza referencias nacionales; cada código se resuelve nuevamente contra la base al cargar y confirmar. También deben seguir disponibles los padres correspondientes, como familia o categoría de condición.

No se aceptan archivos `.xls`, `.xlsm`, CSV ni libros arbitrarios; tampoco macros, imágenes, comentarios, objetos incrustados, celdas combinadas, hipervínculos, conexiones externas, libros cifrados o fórmulas en celdas. Las listas oficiales usan únicamente referencias a rangos internos del mismo libro, sin cálculos en las celdas ni macros. Una fórmula en `Datos` se informa como error de su fila y nunca se evalúa, aunque Excel haya guardado un resultado calculado. Los errores de estructura del libro impiden crear una vista previa utilizable.

## Listas desplegables

La plantilla incluye listas en **Emplazamiento, Código de especie, Hábito de crecimiento, Origen de establecimiento, Origen biogeográfico, Estado actual y Fauna asociada**, para las filas 2 a 101 con el límite predeterminado de 100 registros. Las listas consultan la columna **Opción en lista** de la hoja correspondiente mediante rangos internos con nombre. Un catálogo sin opciones vigentes no genera una lista vacía ni una referencia inválida.

Las opciones tienen la forma `Nombre [código]`. Al importar, el servidor valida que código y nombre correspondan a la referencia vigente y que esta y su catálogo padre estén disponibles. Se conserva el texto seleccionado en la auditoría y se normaliza a las mismas referencias de dominio. Si un nombre cambió después de descargar, actualiza la plantilla o utiliza el código vigente. Las etiquetas de Excel nunca sustituyen a la base de datos como autoridad.

Las listas de catálogos son ayudas de selección y permiten escribir códigos exactos manualmente. El servidor valida todas las entradas incluso si se pegan valores o se elimina la validación de Excel. No hace falta añadir macros ni fórmulas en las celdas. El formato de hojas y encabezados sigue siendo V2 y se conservan las plantillas anteriores sin listas.

**Fauna asociada**: elegir una opción selecciona una categoría y reemplaza el contenido anterior. Para varias categorías, escribe sus códigos separados por `|` (por ejemplo, `FAU_BIRDS|FAU_INSECTS` si ambos siguen vigentes). El servidor rechaza categorías repetidas, incluso si se combina un código con una selección que representa la misma categoría.

**Protegido, Patrimonial y las 65 condiciones** tienen listas `0`/`1`. En las condiciones, `1` selecciona y `0` o vacío no seleccionan; una celda vacía no acredita ausencia examinada. Las columnas grises calculadas o reservadas permanecen vacías y no tienen listas.

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

La vista previa tiene una vigencia configurable, de 24 horas por defecto. Al intentar confirmar una revisión vencida o de una versión de plantilla no admitida, el servicio cancela ese lote y solicita cargar nuevamente el archivo. La caducidad no elimina automáticamente el historial.

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

El archivo subido se lee desde el temporal de PHP y no se conserva como original en el almacenamiento de la aplicación. El lote guarda nombre, tamaño, SHA-256, versión del esquema normalizado, autores, fechas, contadores y estado. Las nuevas cargas XLSX V1 y V2 se normalizan al esquema vigente; los bloques preparados por consola conservan V1. Cada fila guarda valores leídos, errores de lectura, valores normalizados, errores de dominio y referencias creadas. La identidad y los valores originales son inmutables; los lotes finalizados y sus filas quedan protegidos frente a cambios y borrados.

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

Los resultados históricos y límites prácticos de validación se documentan en el informe de cierre de Fase 13. Las suites automatizadas se retiraron por solicitud expresa del usuario; las verificaciones vigentes se describen en docs/testing.md. Esta guía describe el contrato y no sustituye los resultados observados.

La importación no incorpora actualizaciones masivas, asociación masiva de individuos existentes a otra campaña, conciliación avanzada de duplicados, carga remota de fotos o libros de formato libre.

## Importación administrativa de un inventario revisado

El comando `sysforest:import-reviewed-inventory` procesa un JSON previamente normalizado y revisado. Es una entrada exclusiva de consola; la interfaz HTTP sigue aceptando únicamente el XLSX oficial. No traduce etiquetas, completa fechas, interpreta celdas ambiguas ni fuerza el estado de los individuos. Esas decisiones deben quedar aprobadas y representadas en el archivo preparado.

El JSON UTF-8, sin BOM, reside en `storage/app/private`, con extensión `.json` y un máximo de `imports.max_uncompressed_bytes` (32 MiB por defecto). Su raíz contiene exactamente `version: "SYSFOREST_PREPARED_IMPORT_V1"`, `source_file` (nombre del libro original sin directorios), `source_sha256` (SHA-256 original en hexadecimal minúscula) y `rows`. Cada elemento de `rows` tiene solamente `row_number` (entero, fila real del Excel, único y ascendente desde 2) y `values` (las 110 claves oficiales, cada valor como cadena o `null`; no números ni booleanos JSON). El administrador debe comprobar la huella contra el original conservado: el comando no abre dicho libro para verificar la declaración de procedencia.

Desde el proyecto, valida sin guardar lotes, árboles, inspecciones ni auditoría:

```bash
docker compose exec -T app php artisan sysforest:import-reviewed-inventory \
  /var/www/html/storage/app/private/reviewed-imports/inventario.json \
  --actor=ID --organization=UUID --inventory=UUID
```

Tras revisar el resultado, repite el mismo comando con `--confirm` para importar. El actor debe ser una cuenta vigente, activa, verificada y autorizada en la organización y campaña; no hay elusión de permisos mediante el CLI. La organización se activa únicamente durante el caso de uso. La prevalidación comprueba todos los bloques, sus celdas y duplicados de códigos en el documento completo antes de crear la primera vista previa. Los bloques tienen `min(100, imports.max_rows)` filas y respetan también el límite de tamaño de carga. Con 2.854 filas y límite 100 resultan 29 bloques.

En un documento de varios bloques, `code` debe quedar vacío o `null` en todas las filas para asignación automática, o contener un código explícito en todas. La mezcla se rechaza durante la prevalidación, sin crear lotes ni registros: la reserva de consecutivos se realiza por bloque y no puede reservar de antemano un código explícito situado en otro. Un documento de un solo bloque conserva la mezcla admitida por el servicio ordinario, que reserva primero todos sus códigos explícitos.

Cada bloque se somete a `ImportUploadService` y `ImportConfirmationService`, conservando sus validaciones, bloqueos, transacciones, auditoría y referencias forestales. El nombre inmutable `reviewed-<sha-original>-<fila-inicial>-<fila-final>.json` distingue esta entrada. La huella del lote se calcula sobre su JSON canónico (versión, nombre/SHA original y filas ordenadas); los bloques no comparten artificialmente la misma huella. La auditoría de creación conserva además nombre, huella original y rango de filas Excel.

La transacción es por bloque, no por documento completo. Si falla una confirmación, el resultado indica `partial` o `failed`, el bloque fallido y las cantidades ya guardadas; los bloques confirmados permanecen registrados. Reintenta **el mismo archivo, campaña y límite de filas**. Un lote completado solo se omite tras comprobar identidad, valores originales y referencias creadas; uno `ready` vigente se reutiliza y vuelve a validar. Un lote inválido, cancelado o vencido exige revisión operativa y no se sustituye automáticamente. Cambiar contenido, campaña o partición para una fuente con lotes previos se rechaza. Las comprobaciones de solapamiento y nombre/huella se repiten bajo el bloqueo institucional al crear la vista previa.

La salida estándar contiene un informe JSON con estado, filas y bloques; el avance se escribe en la salida de errores sin celdas ni credenciales. Código de salida `0`: validación satisfactoria o importación completa; `1`: validación por filas rechazada, error operativo o resultado parcial; `2`: argumentos o contrato inválidos. El modo sin `--confirm` sigue siendo únicamente una validación: no demuestra que el estado concurrente de catálogos y permisos vaya a permanecer vigente al confirmar.

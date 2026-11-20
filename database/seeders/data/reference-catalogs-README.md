# Catálogos de referencia neutrales

`reference-catalogs-v2.json` sustituye al paquete anterior mediante una revisión nominal explícita. No es una fuente botánica nueva: conserva las etiquetas transcritas y sus advertencias sobre identificación, sinonimia y nombres no resueltos. Incluye 45 familias, 142 entradas de especie y 13 emplazamientos, con superposición deliberada con los catálogos base; BaseSeeder produce el conjunto revisado de 45 familias, 143 especies, 145 nombres comunes y 16 emplazamientos.

Las claves SOURCE_FAMILY_, SOURCE_SPECIES_, SOURCE_COMMON_ y SOURCE_PLACEMENT_ son identificadores técnicos de procedencia. No se muestran como códigos del catálogo: las altas generan FAMILY_, SPECIES_, COMMON_ o PLACEMENT_ numéricos. Cada una de las 315 claves cambiadas conserva `legacy_code_hash`, SHA-256 UTF-8 del identificador anterior. Los padres usan claves fuente coherentes; se verifican identidad, nombre, padre y vigencia antes de reutilizar registros.

SHA-256 del paquete: `ded816dd91cb6dc68214b565678a9aef7cc7077cdbc41ba8ba62be47a60f84c0`.

La fuente original conserva SHA-256 `54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63`. Su etiqueta pública es neutral; el nombre original se acredita mediante `original_filename_sha256`. El XLSX, prepared.json y statuses.json privados no se modifican ni se incluyen en imágenes productivas. El municipio Restrepo, su DANE 50606 y los demás datos territoriales reales se conservan.

`ExtendedTaxonomySeeder` prepara solo referencias nacionales. `DatabaseSeeder` delega a `BaseSeeder` sin piloto, inventarios, árboles o importación automática. `ReferenceInventorySeeder` y `ReferenceInventoryImportSeeder` son operaciones explícitas: la segunda sigue restringida a consola local y al paquete privado de huellas exactas. En una nueva preparación el destino usa REFERENCE-2025 y el prefijo COL-MET-RES-REF; un destino histórico se reconoce por su huella y no se renombra ni se duplica.

La procedencia neutral no acredita una revisión nomenclatural ni cambia fechas de observación, decisiones profesionales o datos científicos. Ante dos identidades posibles, un nombre/padre diferente o un registro desactivado, la preparación se detiene sin sobrescribirlo.

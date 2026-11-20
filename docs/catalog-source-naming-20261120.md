# Nombres neutrales y compatibilidad de catálogos

## Alcance y estado

Solicitud posterior del 20/11/2026: retirar la marca del archivo de origen de códigos y descripciones operativas, paquetes y nombres de herramientas, conservando datos y compatibilidad. Implementación local y QA integrada finalizadas. La activación productiva permanece pendiente; no se afirma publicación, migración principal ni validación de Android físico.

La entrega anterior de códigos taxonómicos es un antecedente: 000038 introdujo alias literales y el SQL de normalización cambió 305 códigos en producción. La nueva versión sustituye esa infraestructura mediante 000039 y la normalización adicional mediante 000040. No se usa migrate:fresh, no se elimina negocio ni se vuelve a importar el inventario.

## Contrato de referencias

`CatalogCodeKind` abarca familias, especies, nombres comunes y emplazamientos. `CatalogCodeRepository` conserva contadores y referencias en `catalog_code_sequences` y `catalog_code_aliases`; los alias almacenan huellas SHA-256 de identificadores de origen, sin una columna de código fuente en claro. Resolver una clave actual, una clave neutral o una huella anterior debe conducir a una sola identidad; una colisión entre identidades se rechaza. Registrar alias no modifica nombres, actividad, padres, versiones o códigos del registro existente.

039 generaliza la infraestructura de 038 y conserva sus referencias por hash. 040 solo normaliza los emplazamientos y las descripciones de procedencia esperadas según el mapeo revisado, preservando IDs BIGINT/UUID, nombres científicos/comunes, parentesco y atributos ajenos a esa normalización. Los códigos estándar son internos de SysForest, no nomenclatura botánica oficial. La auditoría de la operación se distingue de una reescritura de antecedentes.

El paquete `reference-catalogs-v2.json` usa SOURCE_FAMILY_, SOURCE_SPECIES_, SOURCE_COMMON_ y SOURCE_PLACEMENT_ con el mismo sufijo identificador; las 315 referencias revisadas incluyen hash del código anterior. Padres, etiquetas científicas y SHA del XLSX permanecen trazables. Los códigos visibles de nuevas altas se generan con FAMILY_, SPECIES_, COMMON_ y PLACEMENT_ numéricos. Las claves fuente no se usan como código visible.

Importaciones y filtros públicos aceptan identificadores anteriores por hash, revalidan actividad/parentesco/ámbito y presentan códigos actuales. La compatibilidad no amplía permisos ni publica árboles privados. Android conserva su contrato y obtiene códigos vigentes al sincronizar; no se requiere una nueva APK para este cambio nominal.

## Carbono y procedencia

`carbon-species-references.json` conserva su versión/source_package GWDD_2_2_SYSFOREST_20261025 y las 143 referencias, densidades, límites, citas, estados de aplicabilidad y justificaciones. Solo cambian las claves fuente afectadas y su hash de compatibilidad. CarbonReferenceSeedService exige una coincidencia única y los mismos nombres científicos/familia; repetir no reemplaza perfiles ni revive referencias retiradas. No cambia fórmulas ni recalcula resultados.

- Catálogos v2 SHA-256: `ded816dd91cb6dc68214b565678a9aef7cc7077cdbc41ba8ba62be47a60f84c0`.
- Referencias de carbono SHA-256: `e8ea3e934d3271857d459e3fba72f4916e0bc9b06f0372404d9ce0e7f3a46d95`.
- Fuente XLSX original SHA-256, intacta: `54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63`.

La etiqueta del archivo fuente es neutral y el nombre original queda acreditado por su hash; no se inventa una fuente distinta. Los municipios reales, DANE y la organización geográfica RESTREPO-META siguen siendo datos reales, no marcas que deban borrarse. El paquete privado permanece byte a byte en su ubicación original.

## Instalación e importación explícita

DatabaseSeeder ahora llama únicamente a BaseSeeder. Por defecto no prepara pilotos, cuentas de desarrollo, inventarios ni árboles y no ejecuta importaciones de referencia. Los catálogos/base administrativa mantienen sus reglas existentes.

Las clases y seeders de preparación/importación se denominan ReferenceInventory*. La importación completa sigue limitada al paquete privado exacto, consola local, organización autorizada y campaña privada. Para altas nuevas usa REFERENCE-2025 y COL-MET-RES-REF; las identidades históricas se reconocen por hash y se reutilizan sin renombrarlas ni crear duplicados. La detección de correcciones de estado ya aplicadas reconoce el prefijo histórico por hash y el sufijo exacto de la fuente, conservando recibos y decisiones posteriores. El límite de 2.854 filas/29 lotes permanece exclusivo de esa recuperación; no aplica a otros inventarios.

La presentación de textos heredados puede usar una etiqueta neutral sin alterar el documento canónico. No se reescriben security_events, documentos Mongo, snapshots, recibos móviles/de importación ni archivos privados para eliminar una palabra de su historia. El SQL anterior está archivado sin cambios en database/operations/archive/standardize-taxonomy-codes.sql; no ejecutarlo después de 039, porque opera sobre la infraestructura sustituida.

## Evidencia obtenida y pendiente

Los 14 PHP de consumidores y renombres aprobaron PSR-12, sintaxis y diff-check. El mapeo revisado contiene 37 familias, 133 especies, 135 nombres comunes y 10 emplazamientos; compara hashes y conserva todos los campos científicos del paquete de carbono. La infraestructura 039 tiene su revisión independiente. No se recrearon suites permanentes.

La QA aislada aprobó la migración 039 con conservación de 72 tablas y la 040 con conservación de 67 tablas: 305 alias anteriores se conservaron por hash dentro de 630 referencias finales; se normalizaron las 315 descripciones previstas y 10 códigos de emplazamiento. La instalación nueva y repetición aprobaron 23/23 observaciones. La actualización directa desde 038, sin ejecutar el SQL histórico, conservó una descripción profesional personalizada; importaciones y filtros públicos aprobaron 10/10 observaciones.

CarbonReferenceSeedService aprobó 5/5 observaciones sobre la instalación nueva: 143 perfiles exactos, repetición sin altas ni cambios, paquete científico intacto y rollback total de sus fixtures. La revisión independiente de los 16 archivos de consumidores/paquetes no encontró bloqueos y confirmó sus hashes congelados. Estas comprobaciones son observaciones temporales, no una suite persistente ni una certificación científica.

La evidencia se conserva fuera del repositorio en `.source-naming-work`: `qa-migration039-result.json`, `qa-migration040-result.json`, `qa-fresh-result.json` y `qa-consumer-carbon.json`. Producción sigue pendiente. La consulta productiva previa fue solo lectura: separó menciones operativas de alias/auditoría; no constituye por sí sola autorización para reescribir historia.

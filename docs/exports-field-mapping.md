# Matriz de exportación del inventario — Fase 12

El esquema privado cubre las **94 variables conceptuales** de `Hoja1!A:CP`. No es una réplica binaria del libro ni una plantilla de importación: descompone orígenes, coordenadas, fenología y fauna para conservar sus significados y agrega identidad institucional. Las 65 condiciones originales tienen columnas distintas por código y órgano. La matriz pública aplica la política de Fase 11; no expone los textos privados para completar artificialmente las 94 variables.

Fuente de encabezados: `eJEMPLO DE LO QUE SE REQUIERE PARA UN INVENTARIO FORESTAL.xlsx`, SHA-256 `57621dc14923f611d54eee0a62bcc396ac637680614a1a963cbe4bf091222785`. Esta matriz reutiliza las decisiones operativas de [la matriz del Excel](excel-field-mapping.md), [dasometría](dendrometry.md), [inspecciones](inspections.md) y [política pública](public-data-policy.md); no reinterpreta el original.

## Contrato y límites

- `InventoryExportSchema::columns(bool $public)` devuelve columnas ordenadas con `key`, `label`, `type`, `group` y, cuando corresponde, `unit` y `source`. `type` pertenece a `text`, `decimal`, `integer` o `boolean`. Los grupos son identidad, taxonomía, ubicación, dasometría, condiciones, fenología, fauna, evidencias y observaciones. El esquema se conserva en memoria por instancia; no consulta catálogos por fila.
- `ExportReadRepository::count(ReportSelectionDTO): int`, `rows(DTO, int $batchSize = 200): iterable<array>` y `paginate(DTO, int $page = 1, int $perPage = 25): LengthAwarePaginator` comparten la selección. La página se obtiene con OFFSET/LIMIT SQL y precarga únicamente sus relaciones. El lote se limita a 1–1000 y la página a 1–100 registros.
- `find(DTO, string $treeIdOrCode, ?string $organizationPublicCode = null): ?array` conserva todos los filtros. Público requiere código público de organización y código de árbol. Privado acepta UUID interno, o código de árbol dentro de un alcance de organización; para un alcance amplio y código se exige el código interno de organización en el tercer argumento. No escoge arbitrariamente entre códigos repetidos.
- La capa de servicios autoriza y acota cada descarga síncrona: 10.000 árboles públicos y 50.000 privados. El repositorio no aplica el límite de marcadores ni de páginas del mapa y no inicia trabajos de cola. La generación que combina conteo y lotes usa el snapshot de lectura que administra el servicio, además de revalidar el acceso antes de entregar el archivo.

`ReportSelectionDTO` conserva el alcance explícito, los filtros compartidos y sus valores presentables. El lector usa `MapTreeRepository::query(scope, filters, false)`: **radio sí; bbox del viewport no**. Mantiene árboles sin geometría cuando no hay filtro radial, excluye árboles anulados y organizaciones/territorios inactivos. Los filtros de campaña son participación mediante `inventory_trees`, no una redefinición de la última visita. La ordenación usa `TreeQueryRepository::ordered`: código por defecto, opciones validadas y UUID interno como desempate estable; el UUID no se convierte en columna.

La última inspección se obtiene con el LATERAL compartido, ordenado por `inspection_date DESC, created_at DESC, id DESC`. Los filtros de clase, medidas y condiciones ANY/ALL se aplican a esa visita. Los lotes cargan **exactamente los IDs elegidos** y los separan por organización. Público elige primero entre visitas publicadas y campañas publicables, siguiendo la misma ordenación; no toma una visita privada más reciente. Los filtros de mantenimiento conservan el EXISTS común y, en público, exigen executed/verified en la misma orden que satisface los demás criterios.

## Precisión, ausencia y seguridad

Los valores NUMERIC de entradas conservan seis decimales y los resultados doce como cadenas; no se evalúan fórmulas en Excel/CSV, no se recalculan medidas con reglas actuales y no se usa float para las medidas. `classification_rule_version` y `calculation_rule_version` privados son códigos legibles de las versiones persistidas, sin UUID. BIGINT se conserva como entero PHP de 64 bits; el escritor evita perder dígitos al serializar Excel. El escritor también aplica su tratamiento de texto frente a fórmulas de hoja de cálculo; el proyector conserva intactos los textos originales, incluidos espacios y saltos de línea.

Una condición seleccionada produce `x`; su ausencia produce `null`, sin afirmar una evaluación negativa. `false`, `0`, `0.000000` y `null` no se intercambian. Las condiciones/catálogos desactivados que pertenecen a una inspección histórica siguen visibles. Los códigos originales fijan el significado de las columnas y sus etiquetas documentales; las nuevas condiciones no amplían dinámicamente el archivo: se agregan en `physical_additional_conditions` y `phytosanitary_additional_conditions`, una por línea con código, órgano y nombre actual.

Los proyectores privado y público son independientes y usan relaciones precargadas. Público ni siquiera selecciona observaciones, desarrollo fenológico, legado o metadatos GPS de la base. No hay columnas de usuarios, UUID internos, auditoría, rutas de disco, nombres originales de imágenes ni metadatos técnicos de medios. Los códigos de departamento/municipio públicos son compuestos como en el portal (`país~departamento` y `país~departamento~municipio`).

CN y CO pertenecen a la inspección elegida. CN filtra `EVD_FAUNA` y CO `EVD_WHOLE_TREE`; las fotografías permanentes del árbol y las de mantenimiento no se mezclan. Se incluyen todas las fotografías vigentes de cada tipo, separadas por saltos de línea. Privado requiere `evidences.view`, y cada URL controlada vuelve a exigir sesión, tenant y autorización del propietario. Público solo emite `/portal/media/{publicId}` de evidencias publicadas cuya cadena completa siga publicada, que entrega la derivada pública; nunca se usa el original privado. Ocultar o anular una evidencia elimina su enlace al regenerar el reporte.

### Metadatos internos excluidos del esquema descargable

Cada fila incluye, aparte de las columnas, `_geometry` como Point GeoJSON `[longitud, latitud]` o null, `_identity` como `rawurlencode(códigoOrganización)/rawurlencode(códigoÁrbol)` y `_source` con `tree_id`, `inspection_id` nullable, `evidence_ids` y `maintenance_ids` (vacío en inventario). `_source` permite revalidar lo seleccionado y **nunca se serializa** en archivos ni respuestas públicas. `_geometry` solo se utiliza como geometría de una Feature; no se duplica entre las propiedades. Los escritores seleccionan propiedades mediante `columns()`, nunca volcando la fila completa.

Las coordenadas proceden de `ST_X/ST_Y` de la geometría Point/4326. Latitud/longitud tabulares usan la representación completa del double; `_geometry` conserva esos números para GeoJSON. No se procesa `coordinate_raw` para inventar un punto cuando geometry es null ni se certifica un datum desconocido.

## Correspondencia completa

La columna original identifica la variable conceptual; un punto y coma dentro de una clave indica su descomposición en varias columnas. `_geometry` es un dato técnico reservado, no una columna de Excel/CSV. En filas sin inspección, todos los atributos de visita son null.

| Columna original | Encabezado y celda fuente | Clave(s) de exportación privada | Conservación | Exportación pública |
| --- | --- | --- | --- | --- |
| A | `A4` · N. ÁRBOL | `external_number` | Número legado opcional; code conserva la identidad SYSFOREST por separado. | Excluido: número legado privado. |
| B | `B4` · EMPLAZAMIENTO | `placement` | Valor persistido; sin recalcular. | Mismo dato forestal permitido. |
| C | `C6` · Georreferenciación (Coordenadas) | `coordinate_raw; latitude; longitude; _geometry` | Valor persistido; sin recalcular. | Solo latitude/longitude y geometría; sin texto original ni metadatos GPS. |
| D | `D6` · Nombre Común | `common_name; common_names` | Valor persistido; sin recalcular. | Mismo dato forestal permitido. |
| E | `E6` · Nombre Científico | `scientific_name` | Valor persistido; sin recalcular. | Mismo dato forestal permitido. |
| F | `F6` · Familia | `family` | Valor persistido; sin recalcular. | Mismo dato forestal permitido. |
| G | `G6` · Hábito de Crecimiento | `growth_habit` | Valor persistido; sin recalcular. | Mismo dato forestal permitido. |
| H | `H4` · ORIGEN | `establishment_origin; biogeographic_origin` | Dos dimensiones de origen separadas; ninguna se infiere de la otra. | Mismo dato forestal permitido. |
| I | `I6` · CAP (cm) | `cap_cm` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| J | `J6` · DAP (m) | `dap_m` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| K | `K6` · Radio (m) | `radius_m` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| L | `L6` · Área Basal (m²) | `basal_area_m2` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| M | `M6` · Numero de fustes o estípites | `number_of_stems` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| N | `N6` · Factor de forma (0,45 - 0,7) | `form_factor` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| O | `O6` · Altura Total (m) | `total_height_m` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| P | `P6` · Altura Comercial (m) | `commercial_height_m` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| Q | `Q6` · Volumen comercial (m³) | `commercial_volume_m3` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| R | `R6` · Volumen total (m³) | `total_volume_m3` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| S | `S6` · Copa X (m) | `crown_x_m` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| T | `T6` · Copa Y (m) | `crown_y_m` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| U | `U6` · Clase diamétrica | `diameter_class_number` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| V | `V6` · Clase de altura | `height_class_number` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| W | `W6` · Estado de Madurez | `maturity_state` | Valor persistido; sin recalcular. | Mismo dato sobre la inspección pública elegida. |
| X | `X6` · Anclaje malo | `condition_PHY_ROOT_BAD_ANCHORAGE` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| Y | `Y6` · Anclaje bueno | `condition_PHY_ROOT_GOOD_ANCHORAGE` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| Z | `Z6` · Aletones | `condition_PHY_ROOT_BUTTRESSES` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AA | `AA6` · Confinamiento insuficiente | `condition_PHY_ROOT_INSUFFICIENT_CONFINEMENT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AB | `AB6` · Recto | `condition_PHY_STEM_STRAIGHT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AC | `AC6` · Torcido | `condition_PHY_STEM_CROOKED` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AD | `AD6` · Retorcido | `condition_PHY_STEM_TWISTED` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AE | `AE6` · Ramificación abajo | `condition_PHY_STEM_LOW_BRANCHING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AF | `AF6` · Inclinado < de 30 grados | `condition_PHY_STEM_LEAN_UNDER_30` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AG | `AG6` · Inclinado > de 30 grados | `condition_PHY_STEM_LEAN_OVER_30` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AH | `AH6` · Grietas o fisuras  verticales | `condition_PHY_STEM_VERTICAL_CRACKS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AI | `AI6` · Grietas o fisuras horizontales | `condition_PHY_STEM_HORIZONTAL_CRACKS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AJ | `AJ6` · Aguijones | `condition_PHY_STEM_PRICKLES` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AK | `AK6` · Espinas | `condition_PHY_STEM_THORNS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AL | `AL6` · Marcas por impacto | `condition_PHY_STEM_IMPACT_MARKS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AM | `AM6` · Mas de una por nudo | `condition_PHY_BRANCH_MORE_THAN_ONE_PER_NODE` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AN | `AN6` · Colgantes | `condition_PHY_BRANCH_HANGING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AO | `AO6` · Muertas | `condition_PHY_BRANCH_DEAD` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AP | `AP6` · Chupones | `condition_PHY_BRANCH_SUCKER_SHOOTS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AQ | `AQ6` · Mal podadas | `condition_PHY_BRANCH_POORLY_PRUNED` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AR | `AR6` · Alterada | `condition_PHY_CROWN_ALTERED` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AS | `AS6` · Sin alteración | `condition_PHY_CROWN_UNALTERED` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AT | `AT6` · Desestabilizada por malas podas | `condition_PHY_CROWN_UNBALANCED_BY_PRUNING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AU | `AU6` · Raices visibles | `condition_PHT_ROOT_VISIBLE_ROOTS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AV | `AV6` · Raices retorcidas | `condition_PHT_ROOT_TWISTED_ROOTS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AW | `AW6` · Podas por acción de obras | `condition_PHT_ROOT_PRUNED_BY_WORKS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AX | `AX6` · Sano | `condition_PHT_STEM_HEALTHY` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AY | `AY6` · Agallas o tumores | `condition_PHT_STEM_GALLS_OR_TUMORS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| AZ | `AZ6` · Hongos | `condition_PHT_STEM_FUNGI` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BA | `BA6` · Pudrición | `condition_PHT_STEM_ROT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BB | `BB6` · Necrosis | `condition_PHT_STEM_NECROSIS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BC | `BC6` · Oquedad media < 30% | `condition_PHT_STEM_CAVITY_UNDER_30` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BD | `BD6` · Oquedad grande > 30% | `condition_PHT_STEM_CAVITY_OVER_30` | x si la condición está seleccionada; null en ausencia. | Mismo dato forestal permitido. |
| BE | `BE6` · Anillamiento | `condition_PHT_STEM_GIRDLING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BF | `BF6` · Elementos de estrangulamiento | `condition_PHT_STEM_CONSTRICTING_ELEMENTS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BG | `BG6` · Epífitas parásitas o hemiparasitas | `condition_PHT_STEM_PARASITIC_HEMIPARASITIC_EPIPHYTES` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BH | `BH6` · Bejuco o liana | `condition_PHT_STEM_VINE` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BI | `BI6` · Matapalo | `condition_PHT_STEM_STRANGLER` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BJ | `BJ6` · Termitas o comején | `condition_PHT_STEM_TERMITES` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BK | `BK6` · Hospedero insectos | `condition_PHT_STEM_INSECT_HOST` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BL | `BL6` · Podridas | `condition_PHT_BRANCH_ROT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BM | `BM6` · Hongos | `condition_PHT_BRANCH_FUNGI` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BN | `BN6` · Epífitas parásitas o hemiparasitas | `condition_PHT_BRANCH_PARASITIC_HEMIPARASITIC_EPIPHYTES` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BO | `BO6` · Muertas | `condition_PHT_BRANCH_DEAD` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BP | `BP6` · Escaso | `condition_PHT_FOLIAGE_SCANT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BQ | `BQ6` · Abundante | `condition_PHT_FOLIAGE_ABUNDANT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BR | `BR6` · Sano | `condition_PHT_FOLIAGE_HEALTHY` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BS | `BS6` · Clorosis | `condition_PHT_FOLIAGE_CHLOROSIS` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BT | `BT6` · Entorchamiento | `condition_PHT_FOLIAGE_CURLING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BU | `BU6` · Marchitamiento | `condition_PHT_FOLIAGE_WILTING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BV | `BV6` · Pudrición localizada | `condition_PHT_FOLIAGE_LOCALIZED_ROT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BW | `BW6` · Epífitas parásitas o hemiparasitas | `condition_PHT_FOLIAGE_PARASITIC_HEMIPARASITIC_EPIPHYTES` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BX | `BX6` · Absición foliar anormal - No desfoliación genetica | `condition_PHT_FOLIAGE_ABNORMAL_LEAF_ABSCISSION` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BY | `BY6` · Escasa | `condition_PHT_FLOWER_FRUIT_SCANT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| BZ | `BZ6` · Abundante | `condition_PHT_FLOWER_FRUIT_ABUNDANT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CA | `CA6` · Sana | `condition_PHT_FLOWER_FRUIT_HEALTHY` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CB | `CB6` · Pudrición localizada o general | `condition_PHT_FLOWER_FRUIT_LOCAL_OR_GENERAL_ROT` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CC | `CC6` · Marchitamiento antes de polinización - En fructificación | `condition_PHT_FLOWER_FRUIT_WILTING_BEFORE_POLLINATION_DURING_FRUITING` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CD | `CD6` · Absición anormal de flores y/o frutos | `condition_PHT_FLOWER_FRUIT_ABNORMAL_FLOWER_FRUIT_ABSCISSION` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CE | `CE6` · Registrar fecha de floración y estimar estado desarrollo | `flowering_date; flowering_development` | Fecha y desarrollo se conservan como campos independientes. | Solo flowering_date; el desarrollo textual es privado. |
| CF | `CF6` · Registrar fecha fructificación y estimar estado desarrollo | `fruiting_date; fruiting_development` | Fecha y desarrollo se conservan como campos independientes. | Solo fruiting_date; el desarrollo textual es privado. |
| CG | `CG6` · Sano | `condition_PHT_GENERAL_HEALTHY` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CH | `CH6` · Muerte descendente | `condition_PHT_GENERAL_DIEBACK` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CI | `CI6` · Proceso de muerte > 50% | `condition_PHT_GENERAL_DYING_OVER_50` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CJ | `CJ6` · Proceso de muerte < 50% | `condition_PHT_GENERAL_DYING_UNDER_50` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CK | `CK6` · Hospedero insectos benignos - Avispas, abejas | `condition_PHT_GENERAL_BENIGN_INSECT_HOST` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CL | `CL6` · Hospedero insectos dañinos - Trozadores, perforadores | `condition_PHT_GENERAL_HARMFUL_INSECT_HOST` | x si la condición está seleccionada; null en ausencia. | Mismo dato sobre la inspección pública elegida. |
| CM | `CM6` · (AVES, MAMÍFEROS, REPTILES, INSECTOS, QUIRÓPTEROS) | `fauna; fauna_observations` | Valor persistido; sin recalcular. | Solo categorías fauna; sin observaciones. |
| CN | `CN4` · FOTO FAUNA | `fauna_photo_urls` | Todas las URLs de este tipo y de esta visita; una por línea. Sin mezclar propietario Tree. | Solo enlaces a derivadas de evidencias publicadas de la visita pública elegida. |
| CO | `CO4` · FOTO INDIVIDUO | `tree_photo_urls` | Todas las URLs de este tipo y de esta visita; una por línea. Sin mezclar propietario Tree. | Solo enlaces a derivadas de evidencias publicadas de la visita pública elegida. |
| CP | `CP4` · OBSERVACIONES | `general_observations` | Valor persistido; sin recalcular. | Excluido: observaciones privadas. |

## Columnas complementarias

Además de las correspondencias anteriores, se incluyen `code`, `organization_code`, `organization`, `country_code`, `country`, `department_code`, `department`, `municipality_code`, `municipality`, `current_status`, `current_status_label`, `is_protected`, `is_heritage`, `latest_inspection_date` y las dos agregaciones de condiciones complementarias. Privado agrega metadatos declarados de ubicación y códigos de versión de clasificación/cálculo. El código estable `current_status` se conserva junto a su etiqueta en español `current_status_label`, usada en la tabla y el PDF compacto. El esquema tiene 126 columnas privadas y 111 públicas. Las fechas se expresan como AAAA-MM-DD; la captura de ubicación privada incluye hora UTC y microsegundos.

Esta proyección de inventario no transforma árboles en registros de mantenimiento ni resume todas sus inspecciones históricas en una sola visita. Los agregados de reportes se calculan sobre el mismo conjunto filtrado y tienen sus propios esquemas; la futura plantilla de importación de Fase 13 continúa fuera de este contrato.

## Verificación

`ExportProjectionTest` comprueba las 94 fuentes y 65 condiciones, null/cero/false, precisión persistida, condiciones históricas y complementarias, fotografías CN/CO, geometría y exclusiones públicas. `ExportQueryTest` comprueba la reutilización del motor, ANY/ALL, visitas/campañas, múltiples organizaciones, orden/página, radio sin bbox, más de 2.000 filas sin truncamiento y la ficha restringida por selección. Los datos de estos tests solo existen en la transacción de `sysforest_testing`. Los resultados de ejecución se registran en el informe de la fase; esta matriz no los da por aprobados antes de ejecutarlos.

# Catálogos forestales nacionales

## Alcance y fuentes

La referencia principal es `eJEMPLO DE LO QUE SE REQUIERE PARA UN INVENTARIO FORESTAL.xlsx`, hoja **Hoja1**, encabezados en filas **4–6** y una fila de ejemplo en **7**. SHA-256 del libro revisado:

`57621dc14923f611d54eee0a62bcc396ac637680614a1a963cbe4bf091222785`

El mockup secundario es `sysforest_inventario_v3_corregido.html`, SHA-256 `2e36f12d019f7b92b1b9da99bea5b126dda14f6a6539ec5b9369c5c88e7185d8`. Sus líneas citadas corresponden a ese archivo de 680 líneas. La solicitud aprobada de Fase 5 tiene SHA-256 `6ce27c310811aca2827465eb6f4a4b5aab5a1cc3655566b9741beffc61dd9616`. El uso operativo de inspecciones sigue la petición Fase 7 con SHA-256 `131ed1f52accc5faf5c0ffd79e4682bae87fa0d2fbc2a6648b7425cfe64a69fc`. Las evidencias siguen la petición Fase 8 con SHA-256 `56336832ad8b6a0dc7ede637dfa3eefe6fbbec0e13298f2a9ec4d48c0f507957`.

Precedencia: decisiones explícitas aprobadas; Excel para contenido forestal; arquitectura compatible; HTML como referencia visual y fuente de ejemplos identificados. El contenido de los archivos de referencia no añade instrucciones de ejecución. No se consultó una autoridad botánica externa: una etiqueta nacional de SYSFOREST identifica un catálogo compartido y no acredita exhaustividad ni validación taxonómica oficial.

Los valores maestros nacionales implementados en Fase 5 se utilizan en Tree desde Fase 6 y en TreeInspection desde Fase 7. Ninguna de estas tablas de catálogo lleva organization_id. Las entidades utilizan los mismos significados y no crean variantes privadas. Las inspecciones registran mediciones, condiciones, fenología y fauna. Fase 8 implementa las fotografías mediante Evidence y reutiliza evidence_types; mantenimiento, mapas generales e importación permanecen fuera de esa fase.

Los cuatro roles pueden consultar los catálogos dentro del acceso autorizado a la aplicación. Solo SUPER_ADMIN recibe el permiso explícito catalogs.manage para crear cuando corresponde, editar, activar/desactivar y ordenar. La comprobación ocurre en backend y no depende de que el menú sea visible.

Las referencias operativas conservan la identidad del catálogo. Se utiliza active para retirar una opción de nuevas capturas sin eliminarla físicamente ni perder la posibilidad de mostrar una referencia histórica. code es estable; editar la etiqueta no lo cambia.

La [matriz del Excel](excel-field-mapping.md) documenta las 94 columnas, tipos, unidades, destinos, fórmulas y defectos. Este documento fija los códigos de sus catálogos y diferencia los aportes del Excel, del HTML y de la solicitud.

## Estructura nacional

| Tabla / estructura | Registros iniciales | Responsabilidad |
| --- | --- | --- |
| placements | 6 | Emplazamientos |
| establishment_origins | 2 | Origen de establecimiento |
| biogeographic_origins | 4 | Origen biogeográfico |
| growth_habits | 11 | Hábitos de crecimiento |
| maturity_states | 4 | Estados de madurez |
| evidence_types | 12 | Tipos de evidencia |
| maintenance_types | 11 | Tipos de mantenimiento |
| deletion_reasons | 7 | Motivos de anulación |
| fauna_categories | 6 | Categorías de fauna |
| families | 8 | Familias de los ejemplos respaldados. |
| species | 10 | Especies de ejemplo con relación obligatoria a familia. |
| species_common_names | 10 | Un nombre inicial por ejemplo; la relación admite varios. |
| condition_categories | 10 | Cuatro órganos físicos y seis fitosanitarios. |
| conditions | 65 | 23 condiciones físicas y 42 fitosanitarias. |
| classification_rule_versions | Versiones EXCEL_V1 | Parámetros centrales de diámetro, altura y madurez. |
| maturity_rule_bands | 4 | Clases 1–3, 4–6, 7–9 y ≥10. |

No se crea una tabla de estados de desarrollo fenológico: CE/CF solicitan fecha y estimación libre, sin aportar opciones discretas. Tampoco se crea un catálogo de estados del árbol a partir de las etiquetas mezcladas del mockup.

## Emplazamientos, orígenes, hábitos y madurez

### Emplazamientos

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Andén | placements | PLC_SIDEWALK | Hoja1!B7; HTML:502 | Excel dice Anden; se normaliza a Andén, sin duplicar. |
| Parque | placements | PLC_PARK | HTML:502 | Opción literal del mockup; lista ampliable, no exhaustiva. |
| Separador vial | placements | PLC_ROAD_MEDIAN | HTML:502 | Opción literal del mockup; lista ampliable, no exhaustiva. |
| Zona verde | placements | PLC_GREEN_AREA | HTML:502 | Opción literal del mockup; lista ampliable, no exhaustiva. |
| Institución | placements | PLC_INSTITUTION | HTML:502 | Opción literal del mockup; lista ampliable, no exhaustiva. |
| Vía pública | placements | PLC_PUBLIC_ROAD | HTML:502 | Opción literal del mockup; lista ampliable, no exhaustiva. |

### Origen de establecimiento

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Espontánea | establishment_origins | EST_SPONTANEOUS | Hoja1!H7, validación | Etiqueta de la fuente. |
| Plantada | establishment_origins | EST_PLANTED | Hoja1!H7, validación | Etiqueta de la fuente. |

### Origen biogeográfico

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Especie nativa | biogeographic_origins | BIO_NATIVE | Hoja1!H7, validación | Etiqueta de la fuente. |
| Especie exótica | biogeographic_origins | BIO_EXOTIC | Hoja1!H7, validación | Etiqueta de la fuente. |
| Especie naturalizada | biogeographic_origins | BIO_NATURALIZED | Hoja1!H7, validación | Etiqueta de la fuente. |
| Especie invasora | biogeographic_origins | BIO_INVASIVE | Hoja1!H7, validación | Etiqueta de la fuente. |

### Hábitos de crecimiento

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Árbol | growth_habits | HAB_TREE | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Palma | growth_habits | HAB_PALM | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Arbusto | growth_habits | HAB_SHRUB | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Caña o pasto | growth_habits | HAB_CANE_GRASS | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Vástago | growth_habits | HAB_SHOOT | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Bejuco | growth_habits | HAB_VINE | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Liana | growth_habits | HAB_LIANA | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Epífita no parásita | growth_habits | HAB_NONPARASITIC_EPIPHYTE | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Epífita parásita | growth_habits | HAB_PARASITIC_EPIPHYTE | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Matapalo | growth_habits | HAB_STRANGLER | Hoja1!F7:G7, validación; HTML:506 | Etiqueta de la fuente. |
| Herbácea | growth_habits | HAB_HERBACEOUS | HTML:506 | Aporte adicional del HTML; no equivale automáticamente a Caña o pasto. |

### Estados de madurez

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Fustal joven | maturity_states | MAT_YOUNG | Hoja1!V7:W7, validación | Clases 1–3; fuente: hasta clase 3. W7 es texto manual. |
| Fustal medio | maturity_states | MAT_MEDIUM | Hoja1!V7:W7, validación | Clases 4–6; fuente: hasta clase 6. W7 es texto manual. |
| Fustal maduro | maturity_states | MAT_MATURE | Hoja1!V7:W7, validación | Clases 7–9; fuente: hasta clase 9. W7 es texto manual. |
| Fustal sobremaduro | maturity_states | MAT_OVERMATURE | Hoja1!V7:W7, validación | Clases ≥10; fuente: mayor a clase 9. W7 es texto manual. |

Los orígenes están separados en dos tablas: establecimiento y biogeografía. H7 mezcla las seis opciones en una sola celda; separar el catálogo no autoriza a inventar una segunda respuesta. Tree implementa dos FK independientes y opcionales. La captura conserva como NULL la dimensión desconocida; una importación futura deberá identificar explícitamente cuál contiene la única respuesta legada.

No se siembra Introducido del HTML ni se equipara a Plantada, Espontánea, Especie exótica o Especie naturalizada. Nativo del HTML tampoco crea un duplicado o alias automático de Especie nativa. Tree admite una referencia por dimensión como decisión operativa aprobada. El libro no define exclusividad científica ni equivalencias entre las etiquetas biogeográficas; esas interpretaciones siguen pendientes.

Los diez hábitos del Excel se conservan, incluidos Caña o pasto, Vástago, Bejuco y Liana como etiquetas independientes. Herbácea añade un undécimo valor por la decisión documentada de conservar el literal HTML; no elimina ni agrupa los originales.

La madurez conserva exclusivamente Fustal joven, Fustal medio, Fustal maduro y Fustal sobremaduro. No se crean equivalencias con Juvenil, Adulto ni Senescente. Los límites inferiores 1/4/7/10 expresan tramos consecutivos de clases positivas a partir de los topes de la lista. Fase 7 automatiza la madurez de la inspección mediante su clase diamétrica y las bandas de la versión sellada, sin introducir equivalencias con otras etapas.

## Condiciones por evaluación y órgano

Las condiciones se normalizan como condition_categories → conditions. Cada categoría distingue assessment_type, con physical_mechanical o phytosanitary. La identidad de la condición incluye su categoría; Hongos de fuste y Hongos de ramas mantienen códigos distintos.

| Evaluación | Categoría | code | Fuente de órgano | Columnas | Condiciones |
| --- | --- | --- | --- | --- | --- |
| physical_mechanical | Sistema radicular | PHY_ROOT | Hoja1!X5 | X6:AA6 | 4 |
| physical_mechanical | Fuste o estípite | PHY_STEM | Hoja1!AB5 | AB6:AL6 | 11 |
| physical_mechanical | Ramas | PHY_BRANCH | Hoja1!AM5 | AM6:AQ6 | 5 |
| physical_mechanical | Copa | PHY_CROWN | Hoja1!AR5 | AR6:AT6 | 3 |
| phytosanitary | Sistema radicular | PHT_ROOT | Hoja1!AU5 | AU6:AW6 | 3 |
| phytosanitary | Fuste o estípite | PHT_STEM | Hoja1!AX5 | AX6:BK6 | 14 |
| phytosanitary | Ramas | PHT_BRANCH | Hoja1!BL5 | BL6:BO6 | 4 |
| phytosanitary | Follaje | PHT_FOLIAGE | Hoja1!BP5 | BP6:BX6 | 9 |
| phytosanitary | Flores y frutos | PHT_FLOWER_FRUIT | Hoja1!BY5 | BY6:CF6 | 6 |
| phytosanitary | Estado fitosanitario general del árbol | PHT_GENERAL | Hoja1!CG5 | CG6:CL6 | 6 |

El bloque fitosanitario AU:CL tiene 44 columnas: 42 condiciones y dos campos compuestos de fenología (CE/CF). Flores y frutos aporta seis condiciones BY:CD; sus otras dos columnas se reservan a fenología. El conteo previo de «44 condiciones» incluía esos dos campos y no debe convertirse en 44 casillas.

### Trazabilidad de las 65 condiciones

| Campo Excel | Etiqueta fuente | Catálogo | code | Categoría | Observación |
| --- | --- | --- | --- | --- | --- |
| Hoja1!X6 | Anclaje malo | conditions | PHY_ROOT_BAD_ANCHORAGE | PHY_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!Y6 | Anclaje bueno | conditions | PHY_ROOT_GOOD_ANCHORAGE | PHY_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!Z6 | Aletones | conditions | PHY_ROOT_BUTTRESSES | PHY_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AA6 | Confinamiento insuficiente | conditions | PHY_ROOT_INSUFFICIENT_CONFINEMENT | PHY_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AB6 | Recto | conditions | PHY_STEM_STRAIGHT | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AC6 | Torcido | conditions | PHY_STEM_CROOKED | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AD6 | Retorcido | conditions | PHY_STEM_TWISTED | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AE6 | Ramificación abajo | conditions | PHY_STEM_LOW_BRANCHING | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AF6 | Inclinado &lt; de 30 grados | conditions | PHY_STEM_LEAN_UNDER_30 | PHY_STEM | Etiqueta con umbral estricto; igualdad pendiente. |
| Hoja1!AG6 | Inclinado &gt; de 30 grados | conditions | PHY_STEM_LEAN_OVER_30 | PHY_STEM | Etiqueta con umbral estricto; igualdad pendiente. |
| Hoja1!AH6 | Grietas o fisuras  verticales | conditions | PHY_STEM_VERTICAL_CRACKS | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AI6 | Grietas o fisuras horizontales | conditions | PHY_STEM_HORIZONTAL_CRACKS | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AJ6 | Aguijones | conditions | PHY_STEM_PRICKLES | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AK6 | Espinas | conditions | PHY_STEM_THORNS | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AL6 | Marcas por impacto | conditions | PHY_STEM_IMPACT_MARKS | PHY_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AM6 | Mas de una por nudo | conditions | PHY_BRANCH_MORE_THAN_ONE_PER_NODE | PHY_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AN6 | Colgantes | conditions | PHY_BRANCH_HANGING | PHY_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AO6 | Muertas | conditions | PHY_BRANCH_DEAD | PHY_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AP6 | Chupones | conditions | PHY_BRANCH_SUCKER_SHOOTS | PHY_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AQ6 | Mal podadas | conditions | PHY_BRANCH_POORLY_PRUNED | PHY_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AR6 | Alterada | conditions | PHY_CROWN_ALTERED | PHY_CROWN | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AS6 | Sin alteración | conditions | PHY_CROWN_UNALTERED | PHY_CROWN | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AT6 | Desestabilizada por malas podas | conditions | PHY_CROWN_UNBALANCED_BY_PRUNING | PHY_CROWN | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AU6 | Raices visibles | conditions | PHT_ROOT_VISIBLE_ROOTS | PHT_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AV6 | Raices retorcidas | conditions | PHT_ROOT_TWISTED_ROOTS | PHT_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AW6 | Podas por acción de obras | conditions | PHT_ROOT_PRUNED_BY_WORKS | PHT_ROOT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AX6 | Sano | conditions | PHT_STEM_HEALTHY | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AY6 | Agallas o tumores | conditions | PHT_STEM_GALLS_OR_TUMORS | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!AZ6 | Hongos | conditions | PHT_STEM_FUNGI | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BA6 | Pudrición | conditions | PHT_STEM_ROT | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BB6 | Necrosis | conditions | PHT_STEM_NECROSIS | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BC6 | Oquedad media &lt; 30% | conditions | PHT_STEM_CAVITY_UNDER_30 | PHT_STEM | Etiqueta con umbral estricto; igualdad pendiente. |
| Hoja1!BD6 | Oquedad grande &gt; 30% | conditions | PHT_STEM_CAVITY_OVER_30 | PHT_STEM | Etiqueta con umbral estricto; igualdad pendiente. |
| Hoja1!BE6 | Anillamiento | conditions | PHT_STEM_GIRDLING | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BF6 | Elementos de estrangulamiento | conditions | PHT_STEM_CONSTRICTING_ELEMENTS | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BG6 | Epífitas parásitas o hemiparasitas | conditions | PHT_STEM_PARASITIC_HEMIPARASITIC_EPIPHYTES | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BH6 | Bejuco o liana | conditions | PHT_STEM_VINE | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BI6 | Matapalo | conditions | PHT_STEM_STRANGLER | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BJ6 | Termitas o comején | conditions | PHT_STEM_TERMITES | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BK6 | Hospedero insectos | conditions | PHT_STEM_INSECT_HOST | PHT_STEM | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BL6 | Podridas | conditions | PHT_BRANCH_ROT | PHT_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BM6 | Hongos | conditions | PHT_BRANCH_FUNGI | PHT_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BN6 | Epífitas parásitas o hemiparasitas | conditions | PHT_BRANCH_PARASITIC_HEMIPARASITIC_EPIPHYTES | PHT_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BO6 | Muertas | conditions | PHT_BRANCH_DEAD | PHT_BRANCH | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BP6 | Escaso | conditions | PHT_FOLIAGE_SCANT | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BQ6 | Abundante | conditions | PHT_FOLIAGE_ABUNDANT | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BR6 | Sano | conditions | PHT_FOLIAGE_HEALTHY | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BS6 | Clorosis | conditions | PHT_FOLIAGE_CHLOROSIS | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BT6 | Entorchamiento | conditions | PHT_FOLIAGE_CURLING | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BU6 | Marchitamiento | conditions | PHT_FOLIAGE_WILTING | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BV6 | Pudrición localizada | conditions | PHT_FOLIAGE_LOCALIZED_ROT | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BW6 | Epífitas parásitas o hemiparasitas | conditions | PHT_FOLIAGE_PARASITIC_HEMIPARASITIC_EPIPHYTES | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BX6 | Absición foliar anormal - No desfoliación genetica | conditions | PHT_FOLIAGE_ABNORMAL_LEAF_ABSCISSION | PHT_FOLIAGE | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BY6 | Escasa | conditions | PHT_FLOWER_FRUIT_SCANT | PHT_FLOWER_FRUIT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!BZ6 | Abundante | conditions | PHT_FLOWER_FRUIT_ABUNDANT | PHT_FLOWER_FRUIT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CA6 | Sana | conditions | PHT_FLOWER_FRUIT_HEALTHY | PHT_FLOWER_FRUIT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CB6 | Pudrición localizada o general | conditions | PHT_FLOWER_FRUIT_LOCAL_OR_GENERAL_ROT | PHT_FLOWER_FRUIT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CC6 | Marchitamiento antes de polinización - En fructificación | conditions | PHT_FLOWER_FRUIT_WILTING_BEFORE_POLLINATION_DURING_FRUITING | PHT_FLOWER_FRUIT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CD6 | Absición anormal de flores y/o frutos | conditions | PHT_FLOWER_FRUIT_ABNORMAL_FLOWER_FRUIT_ABSCISSION | PHT_FLOWER_FRUIT | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CG6 | Sano | conditions | PHT_GENERAL_HEALTHY | PHT_GENERAL | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CH6 | Muerte descendente | conditions | PHT_GENERAL_DIEBACK | PHT_GENERAL | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CI6 | Proceso de muerte &gt; 50% | conditions | PHT_GENERAL_DYING_OVER_50 | PHT_GENERAL | Etiqueta con umbral estricto; igualdad pendiente. |
| Hoja1!CJ6 | Proceso de muerte &lt; 50% | conditions | PHT_GENERAL_DYING_UNDER_50 | PHT_GENERAL | Etiqueta con umbral estricto; igualdad pendiente. |
| Hoja1!CK6 | Hospedero insectos benignos - Avispas, abejas | conditions | PHT_GENERAL_BENIGN_INSECT_HOST | PHT_GENERAL | Órgano preservado; no fusionar etiquetas. |
| Hoja1!CL6 | Hospedero insectos dañinos - Trozadores, perforadores | conditions | PHT_GENERAL_HARMFUL_INSECT_HOST | PHT_GENERAL | Órgano preservado; no fusionar etiquetas. |

Las palabras y umbrales del instrumento se mantienen, incluidas las grafías Raices, Mas y Absición. No se inventan versiones de condiciones para llenar vacíos del archivo. Los códigos ingleses identifican registros; las etiquetas visibles permanecen en español.

Una marca x señala una condición observada en el ejemplo. No hay regla de exclusión mutua: BR7 marca Sano y BT7 marca Entorchamiento en follaje, mientras CG7 también marca Sano general. Este ejemplo no justifica programar restricciones automáticas entre esas opciones.

Los pares &lt;30°/&gt;30°, &lt;30%/&gt;30% y &gt;50%/&lt;50% dejan sin etiqueta el valor exactamente igual al umbral. Se documenta la falta de definición sin añadir condiciones ni alterar sus signos.

## Taxonomía inicial y nombres comunes

La relación es Family → Species → SpeciesCommonName. Una especie admite varios nombres comunes; el nombre no es único globalmente. El indicador de nombre principal admite como máximo uno por especie. La existencia de un solo nombre por ejemplo no impone una relación uno a uno.

Las ocho familias de HTML:505 son:

| Familia | code | Fuente |
| --- | --- | --- |
| CHRYSOBALANACEAE | FAMILY_CHRYSOBALANACEAE | Hoja1!F7 y HTML:505 |
| FABACEAE | FAMILY_FABACEAE | HTML:505 |
| ANACARDIACEAE | FAMILY_ANACARDIACEAE | HTML:505 |
| BIGNONIACEAE | FAMILY_BIGNONIACEAE | HTML:505 |
| MALVACEAE | FAMILY_MALVACEAE | HTML:505 |
| MORACEAE | FAMILY_MORACEAE | HTML:505 |
| ARECACEAE | FAMILY_ARECACEAE | HTML:505 |
| COMBRETACEAE | FAMILY_COMBRETACEAE | HTML:505 |

Los ejemplos científicos y comunes son:

| Especie fuente | code especie | Familia | Nombre común | code nombre común | Fuente |
| --- | --- | --- | --- | --- | --- |
| Licania tomentosa (Benth.) Fritsch. | SPECIES_LICANIA_TOMENTOSA | CHRYSOBALANACEAE | Oití | COMMON_LICANIA_TOMENTOSA_PRIMARY | Hoja1!D7:F7; HTML:512 |
| Samanea saman | SPECIES_SAMANEA_SAMAN | FABACEAE | Samán | COMMON_SAMANEA_SAMAN_PRIMARY | HTML:513 |
| Mangifera indica | SPECIES_MANGIFERA_INDICA | ANACARDIACEAE | Mango | COMMON_MANGIFERA_INDICA_PRIMARY | HTML:514 |
| Roystonea regia | SPECIES_ROYSTONEA_REGIA | ARECACEAE | Palma | COMMON_ROYSTONEA_REGIA_PRIMARY | HTML:515 |
| Handroanthus chrysanthus | SPECIES_HANDROANTHUS_CHRYSANTHUS | BIGNONIACEAE | Guayacán | COMMON_HANDROANTHUS_CHRYSANTHUS_PRIMARY | HTML:516 |
| Ceiba pentandra | SPECIES_CEIBA_PENTANDRA | MALVACEAE | Ceiba | COMMON_CEIBA_PENTANDRA_PRIMARY | HTML:517 |
| Terminalia catappa | SPECIES_TERMINALIA_CATAPPA | COMBRETACEAE | Almendro | COMMON_TERMINALIA_CATAPPA_PRIMARY | HTML:518 |
| Ficus benjamina | SPECIES_FICUS_BENJAMINA | MORACEAE | Ficus | COMMON_FICUS_BENJAMINA_PRIMARY | HTML:519 |
| Vachellia farnesiana | SPECIES_VACHELLIA_FARNESIANA | FABACEAE | Acacia | COMMON_VACHELLIA_FARNESIANA_PRIMARY | HTML:520 |
| Gliricidia sepium | SPECIES_GLIRICIDIA_SEPIUM | FABACEAE | Matarratón | COMMON_GLIRICIDIA_SEPIUM_PRIMARY | HTML:521 |

El Excel incluye la autoría en Licania tomentosa (Benth.) Fritsch.; el HTML usa la forma abreviada Licania tomentosa. Se conserva la forma del Excel en el mismo ejemplo SPECIES_LICANIA_TOMENTOSA y no se duplica la especie por omisión de autoría. Esto es una conciliación de ambos ejemplos, no una afirmación externa de sinonimia o nombre aceptado.

Palma y Ficus son nombres comunes literales de los ejemplos. No se inventan nombres más precisos ni sinónimos. Los códigos terminados en PRIMARY son identidades estables del nombre inicial; no se regeneran si cambia is_primary o su etiqueta.

La clasificación de origen o hábito de un árbol del HTML no convierte esos atributos en propiedades universales de la especie. Las semillas taxonómicas conservan ejemplos respaldados y no representan árboles existentes, un listado nacional exhaustivo ni una revisión botánica certificada.

## Clases, decimales y versiones

Las clases se representan mediante reglas versionadas; no se siembra una cantidad finita arbitraria de bandas. Una regla conserva el ancho, unidad y límites que permiten obtener cualquier número positivo de clase.

| Regla EXCEL_V1 | Fórmula para entradas positivas | Intervalo de clase n | Fuente |
| --- | --- | --- | --- |
| Diámetro | ceil(DAP_cm / 5) | (5 × (n−1), 5 × n] cm | Hoja1!U7 |
| Altura | ceil(altura_total_m / 10) | (10 × (n−1), 10 × n] m | Hoja1!V7 |
| Madurez | Selección del tramo de clase diamétrica | 1–3 / 4–6 / 7–9 / ≥10 | Validación V7:W7 |

DAP=5 cm corresponde a clase 1; 5,000001 cm a clase 2; 10 cm a clase 2. Altura=10 m corresponde a clase 1; 10,000001 m a clase 2. Los parámetros usan DECIMAL(18,6): hasta doce dígitos enteros y seis decimales. La entrada de cálculo es una cadena decimal con punto; se rechazan cero, negativos, notación científica y exceso de precisión, sin redondearlos silenciosamente para hacerlos válidos. BCMath resuelve la aritmética exacta, sin desplazar límites mediante comparación binaria de floats.

classification_rule_versions centraliza la versión y parámetros, y maturity_rule_bands relaciona sus tramos con los cuatro estados. EXCEL_V1 queda sellada: triggers de PostgreSQL impiden cambiar sus parámetros y bandas. Solo pueden editarse name, description, active y sort_order de la versión. Cambiar la fórmula exige una nueva versión.

ClassificationService::diameter(string $value, string $versionId) y ClassificationService::height(string $value, string $versionId) devuelven ClassResult con número de clase, límite mínimo exclusivo, máximo inclusivo, unidad, etiqueta e ID de versión. El ID identifica la regla aplicada; no se toma una versión implícita que pueda cambiar entre consultas.

Las nuevas inspecciones usan EXCEL_V1 activa y sellada; el estado de madurez resultante debe estar activo. TreeInspection guarda classification_rule_version_id, ambas clases y maturity_state_id. La corrección utiliza el ID original incluso si se desactiva la versión o su estado histórico. El cálculo por ID conserva esa reproducción y una regla nueva no reescribe registros anteriores. Los IDs históricos proceden del recurso autorizado, no de campos libres enviados por el navegador.

Fase 7 concreta la precisión técnica que el libro no especifica: entradas NUMERIC(18,6), factor NUMERIC(7,6), DAP/radio NUMERIC(24,12), área NUMERIC(36,12) y volúmenes NUMERIC(48,12). DENDROMETRY_V1 identifica fórmulas, PI, escala interna 40 y redondeo final a 12 decimales. La clase del diámetro calculado se obtiene exactamente como ceil(CAP_cm / (PI × paso)) mediante cociente entero y resto, sin redondear antes el DAP. ClassificationService::diameterRatio amplía ese uso interno y conserva intacto el contrato público manual de seis decimales. Véanse fórmulas, dependencias NULL y límites en [dendrometry.md](dendrometry.md).

## Evidencias, mantenimiento, anulación y fauna

Estos catálogos proceden de la solicitud explícita de Fase 5. El Excel solo aporta dos columnas de fotos y cinco categorías de fauna; no se atribuyen al libro las listas de mantenimiento, anulación ni los otros tipos de evidencia.

### Tipos de evidencia

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Individuo completo | evidence_types | EVD_WHOLE_TREE | Solicitud F5 §13; Hoja1!CO4:CO6 | Catálogo nacional; sin recurso operativo en F5. |
| Sistema radicular | evidence_types | EVD_ROOT_SYSTEM | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Fuste | evidence_types | EVD_STEM | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Ramas | evidence_types | EVD_BRANCHES | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Copa | evidence_types | EVD_CROWN | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Follaje | evidence_types | EVD_FOLIAGE | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Flores/frutos | evidence_types | EVD_FLOWERS_FRUITS | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Afectación fitosanitaria | evidence_types | EVD_PHYTOSANITARY | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Fauna | evidence_types | EVD_FAUNA | Solicitud F5 §13; Hoja1!CN4:CN6 | Catálogo nacional; sin recurso operativo en F5. |
| Mantenimiento antes | evidence_types | EVD_MAINTENANCE_BEFORE | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Mantenimiento después | evidence_types | EVD_MAINTENANCE_AFTER | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |
| Otro | evidence_types | EVD_OTHER | Solicitud F5, §13 | Catálogo nacional; sin recurso operativo en F5. |

Fase 8 reutiliza estos doce IDs nacionales sin duplicar el catálogo. Evidence exige una FK no nullable a evidence_types y exactamente un propietario Tree o TreeInspection, respaldado por FK compuestas del mismo tenant. CN/FOTO FAUNA usa EVD_FAUNA y CO/FOTO INDIVIDUO usa EVD_WHOLE_TREE, ambas como fotografías históricas de TreeInspection. Tree admite archivos propios adicionales, sin duplicación automática.

Una nueva carga o cambio de clasificación exige un tipo activo. El tipo inactivo ya asociado se conserva y sigue visible históricamente; cambiarlo no altera uploaded_by y queda auditado. Cada archivo puede clasificarse individualmente, incluso dentro de una carga múltiple. No se vincula la fotografía de fauna obligatoriamente a una especie ni se guarda el archivo dentro de inspection_fauna.

### Tipos de mantenimiento

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Poda | maintenance_types | MNT_PRUNING | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Tala | maintenance_types | MNT_FELLING | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Tratamiento fitosanitario | maintenance_types | MNT_PHYTOSANITARY | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Aplicación de herbicida | maintenance_types | MNT_HERBICIDE | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Fertilización | maintenance_types | MNT_FERTILIZATION | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Riego | maintenance_types | MNT_IRRIGATION | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Control de plagas | maintenance_types | MNT_PEST_CONTROL | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Manejo de raíces | maintenance_types | MNT_ROOT_MANAGEMENT | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Traslado | maintenance_types | MNT_TRANSPLANT | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Inspección técnica | maintenance_types | MNT_TECHNICAL_INSPECTION | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |
| Otro | maintenance_types | MNT_OTHER | Solicitud F5, §14 | Catálogo nacional; sin recurso operativo en F5. |

### Motivos de anulación

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Registro duplicado | deletion_reasons | DEL_DUPLICATE | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |
| Árbol inexistente | deletion_reasons | DEL_NONEXISTENT_TREE | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |
| Error de ubicación | deletion_reasons | DEL_LOCATION_ERROR | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |
| Error de identificación | deletion_reasons | DEL_IDENTIFICATION_ERROR | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |
| Registro de prueba | deletion_reasons | DEL_TEST_RECORD | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |
| Creación accidental | deletion_reasons | DEL_ACCIDENTAL_CREATION | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |
| Otro | deletion_reasons | DEL_OTHER | Solicitud F5, §15 | Catálogo nacional; sin recurso operativo en F5. |

### Categorías de fauna

| Etiqueta | Catálogo | code | Fuente | Observación |
| --- | --- | --- | --- | --- |
| Aves | fauna_categories | FAU_BIRDS | Hoja1!CM6 y solicitud F5 §16 | Grupo literal; no se infiere especie ni abundancia. |
| Mamíferos | fauna_categories | FAU_MAMMALS | Hoja1!CM6 y solicitud F5 §16 | Grupo literal; no se infiere especie ni abundancia. |
| Reptiles | fauna_categories | FAU_REPTILES | Hoja1!CM6 y solicitud F5 §16 | Grupo literal; no se infiere especie ni abundancia. |
| Insectos | fauna_categories | FAU_INSECTS | Hoja1!CM6 y solicitud F5 §16 | Grupo literal; no se infiere especie ni abundancia. |
| Quirópteros | fauna_categories | FAU_BATS | Hoja1!CM6 y solicitud F5 §16 | Grupo literal; no se infiere especie ni abundancia. |
| Otros | fauna_categories | FAU_OTHER | Solicitud F5 §16 | Otros se añade por solicitud explícita. |

Cada fotografía tiene exactamente un evidence_type. Los nombres de miniaturas del HTML (Vista general, Tronco, Hojas) no crean tipos adicionales ni cambian esa cardinalidad. Los ejemplos de cronograma no introducen estados u órdenes de mantenimiento.

Fauna conserva Aves, Mamíferos, Reptiles, Insectos, Quirópteros y Otros. inspection_fauna admite varias categorías por inspección y fauna_observations conserva una descripción libre, también cuando no se clasifica. No se reorganizan las categorías del instrumento ni se crean catálogos de fauna a partir de tórtolas/pericos del texto libre HTML o avispas/abejas de una etiqueta fitosanitaria.

## Discrepancias registradas

| Tema | Excel | HTML | Decisión y estado vigente |
| --- | --- | --- | --- |
| Emplazamientos | B7=Anden; B2528:B2545 sin lista | Seis valores en línea 502 | PLC_SIDEWALK muestra Andén por normalización ortográfica explícita. Conservar los seis valores respaldados; catálogo ampliable por SUPER_ADMIN. |
| Origen | Validación H7: seis opciones de dos dimensiones | Línea 504: Nativo, Especie exótica, Introducido | Dos tablas con las seis etiquetas Excel. Introducido y equivalencias quedan pendientes. |
| Hábitos | Validación F7:G7: diez etiquetas | Línea 506: Árbol, Palma, Arbusto, Herbácea | Diez valores Excel y Herbácea adicional del HTML, sin fusionar significados. |
| Madurez | Validación V7:W7: cuatro estados fustales con topes | Línea 503: Juvenil, Adulto, Fustal sobremaduro, Senescente | Solo los cuatro estados del Excel; no inventar equivalencias. |
| Diámetro | U7: pasos de 5 cm, límite superior incluido | Línea 507: 0–10,10–20,20–30,30–50,&gt;50 cm | Regla Excel centralizada. No sembrar las bandas manuales. |
| Altura | V7: pasos de 10 m, límite superior incluido | Línea 508: 0–5,5–10,10–15,15–20,&gt;20 m | Regla Excel centralizada. |
| Raíces visibles/retorcidas | AU6/AV6: fitosanitario, radicular | Línea 393: físico, radicular | Conservar PHT_ROOT_VISIBLE_ROOTS y PHT_ROOT_TWISTED_ROOTS. |
| Ramificación abajo | AE6: físico, fuste | Línea 396: copa | Conservar PHY_STEM_LOW_BRANCHING. |
| Marcas por impacto | AL6: físico, fuste | Línea 395: ramas | Conservar PHY_STEM_IMPACT_MARKS. |
| Grietas | AH6 y AI6: verticales y horizontales separadas | Línea 394: un checkbox Grietas o fisuras | Dos condiciones de fuste, sin fusionarlas. |
| Categorías fitosanitarias | Seis órganos; radicular, fuste, ramas, follaje, flores/frutos, general | Líneas 400–403: mezcla radicular/fuste y Plagas y asociaciones; sin ramas/flores | Seguir las seis categorías Excel y sus 42 condiciones. |
| Sano/Sana | AX6 fuste, BR6 follaje, CA6 flores/frutos, CG6 general | Simplificación en líneas 400–403 | Cuatro identidades por órgano; no mover Sano exclusivamente a general. |
| Fenología | CE6/CF6 piden fecha y estado; ejemplos vacíos | Sin campos estructurados | F7 conserva flowering_date/development y fruiting_date/development como cuatro columnas opcionales de TreeInspection; ningún catálogo discreto inventado. |
| Taxonomía Oití | E7 contiene autoría científica | Línea 512 omite autoría | Prioridad del literal Excel dentro del ejemplo conciliado. |
| Cálculos | Siete fórmulas verificables J/K/L/Q/R/U/V | Copia selecciones y entradas en JS; no calcula reglas | F5 selló las reglas; F7 calcula medidas y clases en backend con versiones y sin redondeo previo. |

La matriz detallada de [defectos del Excel](excel-field-mapping.md#validaciones-y-defectos-de-la-fuente) registra además #REF!, el rango de emplazamientos sin datos, la validación de hábito sobre familia y la de madurez sobre clase de altura. Esos defectos no se reproducen en los catálogos ni constituyen nuevas reglas.

## Integridad, administración y conservación

Las relaciones de familia/especie/nombres y categoría/condición se respaldan con FK. La asociación de evaluación y órgano no se deduce del texto visible. Los códigos nacionales son únicos e inmutables; sort_order conserva el orden y active permite desactivar sin borrado físico.

Los Controllers coordinan, los Form Requests validan entrada, los Services autorizan y aplican reglas y los Repositories consultan y persisten. Blade recibe datos preparados. Taxonomía y condiciones conservan repositorios específicos en lugar de un repositorio universal que cambie de tabla por un string del cliente.

Los seeders son idempotentes y mantienen identidades. La disponibilidad de un catálogo no implica que todos sus recursos operativos estén implementados: Evidence ya gestiona fotografías desde F8, pero los tipos Mantenimiento antes/después no crean una entidad Maintenance ni habilitan ese módulo. Las mutaciones de administración registran actor y acción mediante la infraestructura de eventos de seguridad existente.

Fase 5 no añade caché de catálogos. Las lecturas consultan su estado vigente; no se introduce invalidación ni una copia desactualizada de active en Redis.

## Uso histórico y decisiones pendientes

Fase 7 relaciona las 65 condiciones mediante inspection_conditions. La misma etiqueta en órganos distintos conserva IDs distintos. Los pivotes llevan organization_id y FK compuesta a la inspección; conditions y condition_categories siguen siendo nacionales. Las selecciones históricas desactivadas pueden conservarse, pero una selección nueva exige condición y categoría activas. PostgreSQL verifica la disponibilidad al insertar y bloquea desactivaciones concurrentes hasta confirmar la captura. Fauna aplica la misma conservación. No se infiere una exclusión automática entre Sano y otro hallazgo ni que una casilla vacía signifique ausencia evaluada.

CE/CF son dos variables lógicas compuestas y no amplían las 42 condiciones fitosanitarias. flowering_date, flowering_development, fruiting_date y fruiting_development son opcionales e independientes. Los textos conservan su contenido; no existe enum, porcentaje obligatorio ni tabla adicional de episodios. Inspecciones sucesivas mantienen el historial observado.

Continúan pendientes:

- Equivalencias y compatibilidad científica de las etiquetas biogeográficas, y resolución de la dimensión conocida en una importación de H.
- Estados discretos de desarrollo y necesidad futura de múltiples episodios dentro de una inspección; hoy se conserva texto libre y hasta una observación compuesta por proceso.
- Política de exclusividad y de blanco/no evaluado para condiciones, además de los umbrales de igualdad no descritos.
- Fórmula para individuos multifuste y reglas de importación; el conteo no multiplica los resultados actuales. La precisión y los nulos ya se documentan en dendrometry.md y los metadatos de coordenadas en georeferencing.md.
- Curación botánica externa y ampliación nacional de ejemplos taxonómicos y emplazamientos.
- Maintenance y demás módulos posteriores conservan su alcance pendiente. CN/CO ya están soportadas por Evidence desde Fase 8.

Las decisiones no resueltas no se sustituyen por supuestos ocultos. La creación de catálogos queda documentada en [Fase 5](phase-5.md), el dominio permanente en [Tree](tree-domain.md), el uso histórico en [inspecciones](inspections.md) y [Fase 7](phase-7.md), y las fotografías en [evidencias](evidences.md), [almacenamiento](file-storage.md) y [Fase 8](phase-8.md).

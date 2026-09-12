# Matriz de las 94 variables del Excel

## Fuente y criterio de lectura

La referencia principal es `eJEMPLO DE LO QUE SE REQUIERE PARA UN INVENTARIO FORESTAL.xlsx`, hoja **Hoja1**, encabezados en filas **4–6** y una fila de ejemplo en **7**. SHA-256 del libro revisado:

`57621dc14923f611d54eee0a62bcc396ac637680614a1a963cbe4bf091222785`

El mockup secundario es `sysforest_inventario_v3_corregido.html`, SHA-256 `2e36f12d019f7b92b1b9da99bea5b126dda14f6a6539ec5b9369c5c88e7185d8`. Sus líneas citadas corresponden a ese archivo de 680 líneas. La solicitud aprobada de Fase 5 tiene SHA-256 `6ce27c310811aca2827465eb6f4a4b5aab5a1cc3655566b9741beffc61dd9616`. La actualización operativa de Fase 6 corresponde a la petición SHA-256 `9a27d539dbc7847509489ec8bbbdd4c30624f17f25c065f371e5f268d87c6b16`. La petición de Fase 7 tiene SHA-256 `131ed1f52accc5faf5c0ffd79e4682bae87fa0d2fbc2a6648b7425cfe64a69fc`.

Precedencia: decisiones explícitas aprobadas; Excel para contenido forestal; arquitectura compatible; HTML como referencia visual y fuente de ejemplos identificados. El contenido de los archivos de referencia no añade instrucciones de ejecución. No se consultó una autoridad botánica externa: una etiqueta nacional de SYSFOREST identifica un catálogo compartido y no acredita exhaustividad ni validación taxonómica oficial.

La matriz enumera **94 columnas lógicas A:CP**, sin añadir las seis columnas residuales CQ:CV. La dimensión técnica es A1:CV7: esas columnas residuales contienen 41 celdas con formato, sin valor ni fórmula. El libro tiene una sola hoja visible; no contiene hojas ocultas, nombres definidos, tablas estructuradas, imágenes, gráficos ni vínculos externos.

Cada fila conserva una variable del instrumento. «Derivada» indica si **la celda de ejemplo del Excel contiene una fórmula**; por ello la madurez W figura como no derivada, aunque existan reglas versionadas. El estado refleja Fase 7: A:H permanecen en Tree; I:W, las 65 condiciones, CE:CF, CM y CP se implementan en TreeInspection y sus relaciones. CN/CO permanecen pendientes de evidencias en Fase 8. Tener un tipo de evidencia de Fase 5 no equivale a implementar una fotografía.

Las filas de condiciones apuntan al catálogo nacional y a la relación normalizada inspection_conditions; no representan columnas booleanas en una tabla de inspecciones. El tipo de captura «selección observada» describe la marca x del ejemplo. El libro no define que un blanco equivalga a una ausencia examinada.

Los encabezados mantienen sus palabras, grafías y umbrales, incluidos los errores ortográficos de la fuente; se omiten únicamente espacios exteriores al presentarlos. Los códigos son identificadores de SYSFOREST, no datos que existan en el libro.

## Reconciliación del total

| Componente | Columnas | Variables |
| --- | --- | --- |
| Identidad, localización y taxonomía | A:H | 8 |
| Mediciones, clases y madurez | I:W | 15 |
| Condiciones físico-mecánicas | X:AT | 23 |
| Condiciones fitosanitarias | AU:CD y CG:CL | 42 |
| Fenología: fecha y estado de desarrollo | CE:CF | 2 |
| Fauna | CM | 1 |
| Fotos | CN:CO | 2 |
| Observaciones | CP | 1 |
| Total | A:CP | 94 |

El bloque AU:CL contiene **44 variables fitosanitarias**, de las que **42 son condiciones** y **2 son campos compuestos de fenología**. La expresión previa «44 condiciones fitosanitarias» contabilizaba todo el bloque. La corrección de esta denominación conserva todas las columnas: el catálogo tiene **65 condiciones**, 23 físicas y 42 fitosanitarias.

## Matriz completa

### Identidad y mediciones

| Columna (celda) | Encabezado Excel | Grupo | Tipo esperado | Unidad | Entidad y destino actual/futuro | Derivada | Catálogo / código | Observaciones | Estado F7 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1. A (A4) | N. ÁRBOL | Identidad/localización | string (identificador humano; ejemplo entero) | — | Tree.code (automático); Tree.external_number (N. ÁRBOL legado) | No | — | El número original se conserva como external_number nullable, sin unicidad inferida. code es automático e inmutable por organización; UUID identifica el registro. | Implementado Tree F6 |
| 2. B (B4) | EMPLAZAMIENTO | Emplazamiento | UUID de catálogo | — | Tree; Tree.placement_id | No | placements: PLC_SIDEWALK (ejemplo) | Ejemplo exacto Anden; puede mostrarse Andén si se documenta normalización ortográfica. E01, E02. | Catálogo F5 + relación Tree F6 |
| 3. C (C6) | Georreferenciación (Coordenadas) | Identidad/localización | DMS/decimal de captura; geometry(Point,4326) nullable | grados sexagesimales de origen; grados decimales en geometry | Tree; Tree.geometry y coordinate_raw | No | — | X=longitud; Y=latitud. raw conserva el texto de captura; el dato original no aporta datum ni exactitud. Metadatos opcionales y cambios historizados F6. E13. | Implementado Tree F6 |
| 4. D (D6) | Nombre Común | Identificación taxonómica | nombre común / relación taxonómica | — | Tree; Tree.species_id; SpeciesCommonName.name | No | species_common_names: COMMON_LICANIA_TOMENTOSA_PRIMARY | Un nombre común de ejemplo no demuestra relación uno-a-uno entre especie y nombres. | Catálogo F5 + relación Tree F6 |
| 5. E (E6) | Nombre Científico | Identificación taxonómica | nombre científico / UUID de especie | — | Tree; Tree.species_id; Species.scientific_name | No | species: SPECIES_LICANIA_TOMENTOSA | Conservar autoría y grafía de la fuente; la fuente no acredita una revisión taxonómica actual. | Catálogo F5 + relación Tree F6 |
| 6. F (F6) | Familia | Identificación taxonómica | nombre científico de familia / UUID | — | Tree; Species.family_id; Family.scientific_name | No | families: FAMILY_CHRYSOBALANACEAE | Species resuelve Family; no se duplica family_id en Tree. En Excel no es fórmula. E03. | Catálogo F5 + relación Tree F6 |
| 7. G (G6) | Hábito de Crecimiento | Identificación taxonómica | UUID de catálogo | — | Tree; Tree.growth_habit_id | No | growth_habits; HAB_TREE (ejemplo) | Diez etiquetas de la validación; conservar combinaciones Caña o pasto y las alternativas separadas Bejuco/Liana. E03. | Catálogo F5 + relación Tree F6 |
| 8. H (H4) | ORIGEN | Origen | selección nominal de dimensión ambigua | — | Tree; Tree.establishment_origin_id y/o biogeographic_origin_id | No | establishment_origins: EST_*; biogeographic_origins: BIO_* | Dos FK independientes y nullable: conservar la dimensión conocida sin inventar la otra. La fuente contiene una sola selección. E01, E05. | Catálogo F5 + relación Tree F6 |
| 9. I (I6) | CAP (cm) | Dasonimía | decimal | cm | TreeInspection; TreeInspection.cap_cm | No | — | Entrada; no hay validación numérica en el libro. | Implementado TreeInspection F7 |
| 10. J (J6) | DAP (m) | Dasonimía | decimal derivado | m | TreeInspection; TreeInspection.dap_m | Sí | — | Se convierte a cm solo para la regla de clase diamétrica. E06. | Calculado en TreeInspection F7 |
| 11. K (K6) | Radio (m) | Dasonimía | decimal derivado | m | TreeInspection; TreeInspection.radius_m | Sí | — | Radio = DAP / 2. E06. | Calculado en TreeInspection F7 |
| 12. L (L6) | Área Basal (m²) | Dasonimía | decimal derivado | m² | TreeInspection; TreeInspection.basal_area_m2 | Sí | — | Área = π × DAP² / 4. E06. | Calculado en TreeInspection F7 |
| 13. M (M6) | Numero de fustes o estípites | Dasonimía | integer | fustes o estípites | TreeInspection.number_of_stems | No | — | Conteo observado; M7=3 no interviene en fórmulas. Se adopta number_of_stems por petición F7 §12, en lugar del nombre propuesto stem_count. E11. | Implementado TreeInspection F7 |
| 14. N (N6) | Factor de forma (0,45 - 0,7) | Dasonimía | decimal | adimensional | TreeInspection; TreeInspection.form_factor | No | — | El encabezado especifica 0,45–0,7; el libro no valida ese rango. F7 lo valida sin imponer pasos discretos. | Implementado TreeInspection F7 |
| 15. O (O6) | Altura Total (m) | Dasonimía | decimal | m | TreeInspection; TreeInspection.total_height_m | No | — | Altura total observada. | Implementado TreeInspection F7 |
| 16. P (P6) | Altura Comercial (m) | Dasonimía | decimal | m | TreeInspection; TreeInspection.commercial_height_m | No | — | Altura comercial observada; sin validación cruzada en Excel. | Implementado TreeInspection F7 |
| 17. Q (Q6) | Volumen comercial (m³) | Dasonimía | decimal derivado | m³ | TreeInspection; TreeInspection.commercial_volume_m3 | Sí | — | Altura comercial × área basal × factor de forma. E06, E11. | Calculado en TreeInspection F7 |
| 18. R (R6) | Volumen total (m³) | Dasonimía | decimal derivado | m³ | TreeInspection; TreeInspection.total_volume_m3 | Sí | — | Altura total × factor de forma × área basal. E06, E11. | Calculado en TreeInspection F7 |
| 19. S (S6) | Copa X (m) | Dasonimía | decimal | m | TreeInspection; TreeInspection.crown_x_m | No | — | Diámetro/eje de copa X según etiqueta; no hay orientación cardinal especificada. | Implementado TreeInspection F7 |
| 20. T (T6) | Copa Y (m) | Dasonimía | decimal | m | TreeInspection; TreeInspection.crown_y_m | No | — | Diámetro/eje de copa Y según etiqueta; no hay orientación cardinal especificada. | Implementado TreeInspection F7 |
| 21. U (U6) | Clase diamétrica | Dasonimía | integer derivado | número de clase | TreeInspection.diameter_class_number y classification_rule_version_id | Sí | classification_rule_versions: EXCEL_V1 (diámetro) | ceil(DAP_cm / 5) para DAP > 0. F7 resuelve CAP/(PI×paso) sin redondear DAP previamente. E06. | Calculado en TreeInspection F7 |
| 22. V (V6) | Clase de altura | Dasonimía | integer derivado | número de clase | TreeInspection.height_class_number y classification_rule_version_id | Sí | classification_rule_versions: EXCEL_V1 (altura) | ceil(altura_total_m / 10) para altura &gt; 0. No se fija clase máxima. E06, E04. | Calculado en TreeInspection F7 |
| 23. W (W6) | Estado de Madurez | Dasonimía | UUID de estado; texto en Excel | — | TreeInspection.maturity_state_id y classification_rule_version_id | No | maturity_states: MAT_*; maturity_rule_bands | Cuatro estados; W7 sigue siendo texto manual en la fuente. F7 aplica automáticamente bandas EXCEL_V1 desde la clase, sin cambiar Derivada del Excel. E04, E01, E10. | Calculado en TreeInspection F7 |

### Estado físico-mecánico

| Columna (celda) | Encabezado Excel | Grupo | Tipo esperado | Unidad | Entidad y destino actual/futuro | Derivada | Catálogo / código | Observaciones | Estado F7 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 24. X (X6) | Anclaje malo | Físico · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_ROOT_BAD_ANCHORAGE | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 25. Y (Y6) | Anclaje bueno | Físico · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_ROOT_GOOD_ANCHORAGE | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 26. Z (Z6) | Aletones | Físico · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_ROOT_BUTTRESSES | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 27. AA (AA6) | Confinamiento insuficiente | Físico · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_ROOT_INSUFFICIENT_CONFINEMENT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 28. AB (AB6) | Recto | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_STRAIGHT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 29. AC (AC6) | Torcido | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_CROOKED | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 30. AD (AD6) | Retorcido | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_TWISTED | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 31. AE (AE6) | Ramificación abajo | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_LOW_BRANCHING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 32. AF (AF6) | Inclinado &lt; de 30 grados | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_LEAN_UNDER_30 | Marca x; identidad por órgano. E07, E08. | Implementado Condition/inspection_conditions F7 |
| 33. AG (AG6) | Inclinado &gt; de 30 grados | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_LEAN_OVER_30 | Marca x; identidad por órgano. E07, E08. | Implementado Condition/inspection_conditions F7 |
| 34. AH (AH6) | Grietas o fisuras  verticales | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_VERTICAL_CRACKS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 35. AI (AI6) | Grietas o fisuras horizontales | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_HORIZONTAL_CRACKS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 36. AJ (AJ6) | Aguijones | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_PRICKLES | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 37. AK (AK6) | Espinas | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_THORNS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 38. AL (AL6) | Marcas por impacto | Físico · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_STEM_IMPACT_MARKS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 39. AM (AM6) | Mas de una por nudo | Físico · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_BRANCH_MORE_THAN_ONE_PER_NODE | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 40. AN (AN6) | Colgantes | Físico · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_BRANCH_HANGING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 41. AO (AO6) | Muertas | Físico · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_BRANCH_DEAD | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 42. AP (AP6) | Chupones | Físico · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_BRANCH_SUCKER_SHOOTS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 43. AQ (AQ6) | Mal podadas | Físico · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_BRANCH_POORLY_PRUNED | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 44. AR (AR6) | Alterada | Físico · Copa | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_CROWN_ALTERED | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 45. AS (AS6) | Sin alteración | Físico · Copa | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_CROWN_UNALTERED | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 46. AT (AT6) | Desestabilizada por malas podas | Físico · Copa | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHY_CROWN_UNBALANCED_BY_PRUNING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |

### Estado fitosanitario, incluida fenología

| Columna (celda) | Encabezado Excel | Grupo | Tipo esperado | Unidad | Entidad y destino actual/futuro | Derivada | Catálogo / código | Observaciones | Estado F7 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 47. AU (AU6) | Raices visibles | Fitosanitario · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_ROOT_VISIBLE_ROOTS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 48. AV (AV6) | Raices retorcidas | Fitosanitario · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_ROOT_TWISTED_ROOTS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 49. AW (AW6) | Podas por acción de obras | Fitosanitario · Sistema radicular | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_ROOT_PRUNED_BY_WORKS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 50. AX (AX6) | Sano | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_HEALTHY | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 51. AY (AY6) | Agallas o tumores | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_GALLS_OR_TUMORS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 52. AZ (AZ6) | Hongos | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_FUNGI | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 53. BA (BA6) | Pudrición | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_ROT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 54. BB (BB6) | Necrosis | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_NECROSIS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 55. BC (BC6) | Oquedad media &lt; 30% | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_CAVITY_UNDER_30 | Marca x; identidad por órgano. E07, E08. | Implementado Condition/inspection_conditions F7 |
| 56. BD (BD6) | Oquedad grande &gt; 30% | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_CAVITY_OVER_30 | Marca x; identidad por órgano. E07, E08. | Implementado Condition/inspection_conditions F7 |
| 57. BE (BE6) | Anillamiento | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_GIRDLING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 58. BF (BF6) | Elementos de estrangulamiento | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_CONSTRICTING_ELEMENTS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 59. BG (BG6) | Epífitas parásitas o hemiparasitas | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_PARASITIC_HEMIPARASITIC_EPIPHYTES | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 60. BH (BH6) | Bejuco o liana | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_VINE | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 61. BI (BI6) | Matapalo | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_STRANGLER | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 62. BJ (BJ6) | Termitas o comején | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_TERMITES | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 63. BK (BK6) | Hospedero insectos | Fitosanitario · Fuste o estípite | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_STEM_INSECT_HOST | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 64. BL (BL6) | Podridas | Fitosanitario · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_BRANCH_ROT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 65. BM (BM6) | Hongos | Fitosanitario · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_BRANCH_FUNGI | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 66. BN (BN6) | Epífitas parásitas o hemiparasitas | Fitosanitario · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_BRANCH_PARASITIC_HEMIPARASITIC_EPIPHYTES | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 67. BO (BO6) | Muertas | Fitosanitario · Ramas | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_BRANCH_DEAD | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 68. BP (BP6) | Escaso | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_SCANT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 69. BQ (BQ6) | Abundante | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_ABUNDANT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 70. BR (BR6) | Sano | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_HEALTHY | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 71. BS (BS6) | Clorosis | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_CHLOROSIS | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 72. BT (BT6) | Entorchamiento | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_CURLING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 73. BU (BU6) | Marchitamiento | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_WILTING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 74. BV (BV6) | Pudrición localizada | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_LOCALIZED_ROT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 75. BW (BW6) | Epífitas parásitas o hemiparasitas | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_PARASITIC_HEMIPARASITIC_EPIPHYTES | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 76. BX (BX6) | Absición foliar anormal - No desfoliación genetica | Fitosanitario · Follaje | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FOLIAGE_ABNORMAL_LEAF_ABSCISSION | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 77. BY (BY6) | Escasa | Fitosanitario · Flores y frutos | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FLOWER_FRUIT_SCANT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 78. BZ (BZ6) | Abundante | Fitosanitario · Flores y frutos | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FLOWER_FRUIT_ABUNDANT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 79. CA (CA6) | Sana | Fitosanitario · Flores y frutos | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FLOWER_FRUIT_HEALTHY | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 80. CB (CB6) | Pudrición localizada o general | Fitosanitario · Flores y frutos | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FLOWER_FRUIT_LOCAL_OR_GENERAL_ROT | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 81. CC (CC6) | Marchitamiento antes de polinización - En fructificación | Fitosanitario · Flores y frutos | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FLOWER_FRUIT_WILTING_BEFORE_POLLINATION_DURING_FRUITING | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 82. CD (CD6) | Absición anormal de flores y/o frutos | Fitosanitario · Flores y frutos | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_FLOWER_FRUIT_ABNORMAL_FLOWER_FRUIT_ABSCISSION | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 83. CE (CE6) | Registrar fecha de floración y estimar estado desarrollo | Fitosanitario · Flores y frutos | fecha DATE nullable + estimación de desarrollo TEXT nullable | — | Phenology; TreeInspection.flowering_date y flowering_development | No | — | Floración. No existe taxonomía de estados, formato de fecha ni ejemplo. No crear condition booleana. E09. | Implementado Phenology F7 |
| 84. CF (CF6) | Registrar fecha fructificación y estimar estado desarrollo | Fitosanitario · Flores y frutos | fecha DATE nullable + estimación de desarrollo TEXT nullable | — | Phenology; TreeInspection.fruiting_date y fruiting_development | No | — | Fructificación. No existe taxonomía de estados, formato de fecha ni ejemplo. No crear condition booleana. E09. | Implementado Phenology F7 |
| 85. CG (CG6) | Sano | Fitosanitario · Estado fitosanitario general del árbol | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_GENERAL_HEALTHY | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 86. CH (CH6) | Muerte descendente | Fitosanitario · Estado fitosanitario general del árbol | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_GENERAL_DIEBACK | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 87. CI (CI6) | Proceso de muerte &gt; 50% | Fitosanitario · Estado fitosanitario general del árbol | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_GENERAL_DYING_OVER_50 | Marca x; identidad por órgano. E07, E01, E08. | Implementado Condition/inspection_conditions F7 |
| 88. CJ (CJ6) | Proceso de muerte &lt; 50% | Fitosanitario · Estado fitosanitario general del árbol | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_GENERAL_DYING_UNDER_50 | Marca x; identidad por órgano. E07, E08. | Implementado Condition/inspection_conditions F7 |
| 89. CK (CK6) | Hospedero insectos benignos - Avispas, abejas | Fitosanitario · Estado fitosanitario general del árbol | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_GENERAL_BENIGN_INSECT_HOST | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |
| 90. CL (CL6) | Hospedero insectos dañinos - Trozadores, perforadores | Fitosanitario · Estado fitosanitario general del árbol | selección observada | — | Condition; TreeInspection → inspection_conditions | No | conditions: PHT_GENERAL_HARMFUL_INSECT_HOST | Marca x; identidad por órgano. E08. | Implementado Condition/inspection_conditions F7 |

### Fauna, evidencias y observaciones

| Columna (celda) | Encabezado Excel | Grupo | Tipo esperado | Unidad | Entidad y destino actual/futuro | Derivada | Catálogo / código | Observaciones | Estado F7 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 91. CM (CM6) | (AVES, MAMÍFEROS, REPTILES, INSECTOS, QUIRÓPTEROS) | Fauna | categorías múltiples + descripción libre | — | Fauna; inspection_fauna + TreeInspection.fauna_observations | No | fauna_categories: FAU_* | Cinco grupos en encabezado; no hay ejemplo ni selector ni detalle de abundancia/especie. Otros procede de solicitud F5. | Implementado Fauna F7 |
| 92. CN (CN4) | FOTO FAUNA | Evidencias | referencia a imagen futura | — | Evidence; TreeInspection → Evidence.evidence_type_id | No | evidence_types: EVD_FAUNA | Sin imagen, vínculo ni dato en CN7. La cardinalidad de una foto por tipo no se deduce del Excel. | Pendiente F8 · Evidence |
| 93. CO (CO4) | FOTO INDIVIDUO | Evidencias | referencia a imagen futura | — | Evidence; TreeInspection → Evidence.evidence_type_id | No | evidence_types: EVD_WHOLE_TREE | Sin imagen, vínculo ni dato en CO7. Un tipo por fotografía está aprobado en solicitud. | Pendiente F8 · Evidence |
| 94. CP (CP4) | OBSERVACIONES | Observaciones | texto libre | — | Observation; TreeInspection.general_observations | No | — | Observación temporal de inspección; ejemplo vacío. | Implementado TreeInspection F7 |

## Fórmulas y comprobación del ejemplo

Las siete fórmulas guardadas coinciden con un recálculo independiente de la fila 7, con tolerancia absoluta de 10⁻¹² para los resultados decimales. No hay errores guardados de fórmula en esas celdas. Los #REF! detectados pertenecen a validaciones de datos.

| Celda | Fórmula literal | Resultado guardado | Unidad |
| --- | --- | --- | --- |
| J7 | `=IF(I7<>"",I7/100/PI(),"")` | 0.490197224723038 | m |
| K7 | `=IF(J7<>"",J7/2,"")` | 0.245098612361519 | m |
| L7 | `=IF(J7<>"",PI()*(J7^2)/4,"")` | 0.18872593151837 | m² |
| Q7 | `=P7*L7*N7` | 0.0754903726073478 | m³ |
| R7 | `=O7*N7*L7` | 1.13235558911022 | m³ |
| U7 | `=ROUNDUP(J7*100/5,0)` | 10 | número de clase |
| V7 | `=ROUNDUP(O7/10,0)` | 2 | número de clase |

Las entradas del ejemplo son I7=154 cm, N7=0,5, O7=12 m y P7=0,8 m. M7=3 no aparece como multiplicador en Q7 ni R7. DAP se almacena en metros en J; U lo convierte a centímetros para clasificarlo.

Para entradas positivas, U7 equivale a `ceil(DAP_cm / 5)` y V7 a `ceil(altura_total_m / 10)`. El intervalo de cada clase es abierto por abajo y cerrado por arriba. El libro no establece un máximo de clases. Fase 5 centraliza el comportamiento en versiones de regla; no se crean bandas arbitrarias copiadas del HTML.

| Regla | Entrada decimal exacta | Clase |
| --- | --- | --- |
| DAP (cm) | 0.000001 | 1 |
| DAP (cm) | 5 | 1 |
| DAP (cm) | 5.000001 | 2 |
| DAP (cm) | 10 | 2 |
| DAP (cm) | 10.000001 | 3 |
| DAP (cm) | 15 | 3 |
| DAP (cm) | 30 | 6 |
| DAP (cm) | 45 | 9 |
| DAP (cm) | 45.000001 | 10 |
| Altura (m) | 0.000001 | 1 |
| Altura (m) | 10 | 1 |
| Altura (m) | 10.000001 | 2 |
| Altura (m) | 20 | 2 |

Las entradas no positivas, ausentes o inválidas no se convierten silenciosamente en una clase 0. La política numérica y el versionado se describen en [catálogos forestales](forestry-catalogs.md).

## Validaciones y defectos de la fuente

| ID | Ubicación | Hallazgo | Tratamiento |
| --- | --- | --- | --- |
| E01 | B4 H4 W6 AM5 AR5 AU4:AU5 BL5 BP5 BY5 CG5 CG6:CI6 | Validación de lista formula1=#REF!; muchas celdas son encabezados, no datos. | No proporciona ningún catálogo recuperable y no debe copiarse. |
| E02 | B7; fuente $B$2528:$B$2545 | La dirección de la lista es sintácticamente válida, pero el archivo solo contiene filas hasta 7 y no almacena valores en B2528:B2545. | El catálogo no es recuperable de la validación. El único ejemplo es B7=Anden. |
| E03 | F7:G7; encabezados F6:G6 | La misma lista de hábitos se aplica a Familia y Hábito de Crecimiento. F7=CHRYSOBALANACEAE no pertenece a la lista. | La lista sirve como fuente de hábitos, no de familias. |
| E04 | V7:W7; encabezados V6:W6 | La lista de madurez se aplica también a Clase de altura, cuya fórmula retorna 2. | La lista sirve como fuente de madurez. Clase de altura conserva regla numérica. |
| E05 | H4; H7 validación | Una lista única mezcla Espontánea/Plantada con Especie nativa/exótica/naturalizada/invasora. | Separar dimensiones documentadamente; no inferir establecimiento desde el origen biogeográfico ni exclusividad científica entre naturalizada/invasora/exótica. |
| E06 | J7:L7; Q7:R7; U7:V7 | J/K/L protegen solo cadena vacía; Q/R/U no comprueban los nulos y V sobre O vacío produciría clase 0. No hay validaciones numéricas ni contra negativos. | F7 conserva NULL cuando faltan dependencias y valida entradas; no traslada estos defectos de la hoja al cálculo operativo. |
| E07 | AF6:AG6; BC6:BD6; CI6:CJ6 | Los pares usan &lt;30/&gt;30 grados, &lt;30%/&gt;30% y &gt;50%/&lt;50%, sin representar igualdad exacta. | Conservar etiquetas; no inventar una condición para igualdad ni reasignarla arbitrariamente. |
| E08 | X6:CL7 | Se observan marcas x y blancos, sin validación ni regla de exclusividad; BR7=Sano y BT7=Entorchamiento están marcados juntos, junto con CG7=Sano general. | Una casilla vacía no demuestra ausencia examinada. No imponer exclusión automática de Sano frente a otros hallazgos sin decisión. |
| E09 | CE6:CF7 | CE/CF solicitan fecha y estimación de estado de desarrollo. Ambas celdas de ejemplo están vacías; no hay formato de fecha, catálogo ni fórmula. | Son dos campos lógicos compuestos de fenología, no dos conditions booleanas; F7 conserva fecha y estimación libre en pares de columnas opcionales, sin inventar estados discretos. |
| E10 | W7; validación V7:W7 | W7 es texto manual, no una fórmula; la lista expresa cuatro topes de clase diamétrica. | F7 aplica la regla EXCEL_V1 aprobada a la inspección y conserva su versión; W7 continúa identificado como texto manual del Excel. |
| E11 | M7; Q7:R7 | M7=3 no participa en las fórmulas de volumen. | No multiplicar el volumen por fustes sin una regla aprobada adicional. |
| E12 | CQ:CV | La dimensión técnica llega a columna 100 (CV), pero el instrumento y sus 94 encabezados terminan en CP. | Los formatos residuales no son seis variables forestales adicionales. |
| E13 | C6:C7 | El ejemplo es DMS con hemisferios N/W; no se especifica datum, precisión, equipo ni fecha de captura. | Fase 6 implementa Point 4326, coordinate_raw y metadatos nullable, pero la fuente no demuestra el datum de la observación. Un datum desconocido permanece NULL; el SRID de trabajo no certifica su procedencia. |

Las cinco reglas de validación del libro son:

| Rango de aplicación | Fuente de lista | Interpretación |
| --- | --- | --- |
| B4 H4 W6 AM5 AR5 AU4:AU5 BL5 BP5 BY5 CG5 CG6:CI6 | `#REF!` | Referencia rota sobre encabezados. |
| H7 | `"Espontánea,Plantada,Especie nativa,Especie exótica,Especie naturalizada,Especie invasora"` | Seis orígenes de dos dimensiones. |
| F7:G7 | `"Árbol,Palma,Arbusto,Caña o pasto,Vástago,Bejuco,Liana,Epífita no parásita,Epífita parásita,Matapalo"` | Diez hábitos; Familia incluida por error. |
| V7:W7 | `"Fustal joven - Hasta clase diamétrica 3,Fustal medio - Hasta clase diamétrica 6,Fustal maduro -  Hasta clase diamétrica 9,Fustal sobremaduro - Mayor a clase diamétrica 9"` | Cuatro madureces; Clase de altura incluida por error. |
| B7 | `$B$2528:$B$2545` | Rango sin valores almacenados. |

Todas son listas con blancos permitidos y mensaje de error habilitado. Ninguna constituye una validación de medidas, coordenadas, fechas o marcas de condición. B2528:B2545 es una dirección válida de Excel, pero no contiene los valores prometidos en este archivo; se distingue del literal #REF! de la otra validación.

## Fenología y fauna

CE6 solicita «Registrar fecha de floración y estimar estado desarrollo»; CF6 solicita «Registrar fecha fructificación y estimar estado desarrollo». CE7 y CF7 están vacías. No hay porcentaje, etapa enumerada, fecha de ejemplo, duración, periodicidad ni lista de estados. Fase 7 implementa cuatro columnas opcionales en TreeInspection: flowering_date, flowering_development, fruiting_date y fruiting_development. Las fechas y textos libres son independientes; no se añaden estados enumerados, porcentajes ni condiciones booleanas. Se representan los dos fenómenos por inspección sin forzar una tabla de episodios no exigida por la fuente.

CM6 contiene AVES, MAMÍFEROS, REPTILES, INSECTOS y QUIRÓPTEROS. CM7 está vacío. «Otros» procede de la solicitud de Fase 5, no del libro. CK6 y CL6 incluyen ejemplos de insectos dentro de etiquetas fitosanitarias; no definen una taxonomía adicional de fauna ni nuevos catálogos de especies.

CN7 y CO7 no tienen imagen, vínculo ni valor. Sus futuros tipos de evidencia son EVD_FAUNA y EVD_WHOLE_TREE. El mandato de un único evidence_type por fotografía procede de la arquitectura y la solicitud aprobada.

## Diferencias respecto al HTML

La [trazabilidad de catálogos](forestry-catalogs.md) detalla cada diferencia de origen, hábito, madurez, clases y condiciones. El Excel prevalece: no se trasladan las bandas manuales del HTML, no se mezclan Juvenil/Adulto/Senescente con los cuatro estados fustales, y no se desplazan condiciones de órgano.

La única fila de ejemplo no define reglas de obligatoriedad, unicidad, exclusión mutua ni exhaustividad nacional. Fase 6 añade, por requisito operativo explícito, organización, inventarios, autores, código automático, número legado, descripción de ubicación, estado, protección, versión e historiales, además de metadatos GPS opcionales. Estos campos técnicos no se cuentan como columnas nuevas del Excel. Fase 7 implementa 84 de las 86 capturas que estaban pendientes y deja exclusivamente las 2 columnas de fotografías para Fase 8.

## Estado operativo al cierre de Fase 7

| Destino operativo | Variables | Estado |
| --- | --- | --- |
| Tree y relaciones permanentes A:H | 8 | Implementadas desde F6 |
| TreeInspection: medidas, clases y madurez I:W | 15 | Implementadas F7 |
| Condition + inspection_conditions | 65 | Implementadas F7: 23 físicas y 42 fitosanitarias |
| Phenology CE:CF | 2 | Implementadas F7 como 4 columnas opcionales dentro de inspección |
| Fauna CM | 1 | Implementada F7: categorías múltiples y descripción |
| Observaciones CP | 1 | Implementada F7: general_observations |
| Evidence CN:CO | 2 | Pendientes exclusivamente de F8 |
| Total | 94 | 92 implementadas y 2 pendientes |

La matriz conserva los 94 encabezados y las 7 marcas «Derivada» originales. La activación de la madurez calculada no convierte W7 en una fórmula retrospectivamente. number_of_stems, general_observations y las columnas de fenología explicitan el nombre final implementado para los destinos antes propuestos.

La identificación permanente conserva external_number para N. ÁRBOL, separado de code, generado por una secuencia institucional. location_description pertenece al requisito operativo F6 y no reemplaza CP. El estado de Tree tampoco se modifica automáticamente al marcar condiciones, registrar madurez o describir fauna.

No se guardan rutas falsas ni fotografías de ejemplo para CN/CO. Los catálogos EVD_FAUNA y EVD_WHOLE_TREE siguen disponibles, pero la persistencia de Evidence y almacenamiento de imágenes pertenecen a Fase 8. Detalles: [inspecciones](inspections.md), [dasonomía](dendrometry.md), [dominio Tree](tree-domain.md) y [verificación F7](phase-7.md).

# Trazabilidad de la plantilla oficial de importación

`App\Imports\ImportTemplateSchema` es la fuente ejecutable de este contrato (`SYSFOREST_IMPORT_V1`). Sus 110 columnas conservan 94 referencias de variables originales, incluidas 65 selecciones de condición. Los campos descompuestos pueden compartir una misma celda de origen; otros campos agregan identidad técnica o metadatos necesarios para la captura.

La fuente `Hoja1!…` corresponde al instrumento Excel aportado por el usuario, no a una celda de la nueva plantilla. Las etiquetas históricas se conservan sin deducir equivalencias. El orden de esta tabla es el orden obligatorio de la hoja `Datos`.

Los modos del esquema tienen este significado:

- `input`: entrada del usuario, validada y normalizada por los Services.
- `backend`: celda reservada y vacía; taxonomía o resultado obtenido por el servidor.
- `evidence`: celda reservada y vacía; las fotografías se cargan mediante Evidencias después de importar.

Solo `current_status` e `inspection_date` son obligatorios incondicionalmente. Una entrada opcional, si se informa, debe satisfacer las reglas de tipo, referencia, medida y coherencia que correspondan. Consulta el [flujo, límites y transacciones](import-export.md) y la [trazabilidad del instrumento original](excel-field-mapping.md).

| N.º | Columna técnica | Etiqueta y grupo | Modo / tipo | Obligatoria | Fuente original | Regla de captura |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `code` | Código SYSFOREST · Identidad | `input` / `text` | No | Campo complementario | Opcional. Vacío genera código; un código indicado debe usar el prefijo vigente de la organización y al menos seis dígitos, sin ceros adicionales. Una colisión impide todo el lote. |
| 2 | `external_number` | N. ÁRBOL / número legado · Identidad | `input` / `text` | No | Hoja1!A4 | Hasta 100 caracteres; conserva ceros iniciales. No se usa como identidad inequívoca. |
| 3 | `placement_code` | Emplazamiento · Ubicación | `input` / `catalog` | No | Hoja1!B4 | Código exacto de placement_code en Catalogos. |
| 4 | `coordinate_raw` | Georreferenciación (coordenadas) · Ubicación | `input` / `text` | No | Hoja1!C6 | Latitud, longitud en grados decimales o DMS. Ejemplo: 4.154, -73.633. No se infiere el datum. |
| 5 | `species_code` | Código de especie · Taxonomía | `input` / `catalog` | No | Campo complementario | Código exacto de species_code. Resuelve nombre científico, nombres comunes y familia. |
| 6 | `common_name` | Nombre común · Taxonomía | `backend` / `text` | No | Hoja1!D6 | Dejar vacío: se obtiene de la especie seleccionada; no se importan nombres libres. |
| 7 | `scientific_name` | Nombre científico · Taxonomía | `backend` / `text` | No | Hoja1!E6 | Dejar vacío: se obtiene de la especie seleccionada; no se importan nombres libres. |
| 8 | `family` | Familia · Taxonomía | `backend` / `text` | No | Hoja1!F6 | Dejar vacío: se obtiene de la especie seleccionada; no se importan nombres libres. |
| 9 | `growth_habit_code` | Hábito de crecimiento · Taxonomía | `input` / `catalog` | No | Hoja1!G6 | Código exacto de growth_habit_code. |
| 10 | `establishment_origin_code` | Origen de establecimiento · Taxonomía | `input` / `catalog` | No | Hoja1!H4 | Código exacto de establishment_origin_code; no equivale al origen biogeográfico. |
| 11 | `biogeographic_origin_code` | Origen biogeográfico · Taxonomía | `input` / `catalog` | No | Hoja1!H4 | Código exacto de biogeographic_origin_code; independiente del establecimiento. |
| 12 | `current_status` | Estado actual · Identidad | `input` / `catalog` | Sí | Campo complementario | Código exacto de current_status en Catalogos. |
| 13 | `is_protected` | Protegido · Identidad | `input` / `boolean` | No | Campo complementario | 0 o 1. Vacío equivale a 0. |
| 14 | `is_heritage` | Patrimonial · Identidad | `input` / `boolean` | No | Campo complementario | 0 o 1. Vacío equivale a 0. |
| 15 | `inspection_date` | Fecha de inspección · Inspección | `input` / `date` | Sí | Campo complementario | Texto AAAA-MM-DD; no fecha serial de Excel. |
| 16 | `cap_cm` | CAP (cm) · Dasometría | `input` / `decimal` | No | Hoja1!I6 | Texto con punto decimal, sin miles ni notación científica; hasta seis decimales. |
| 17 | `dap_m` | DAP (m) · Dasometría | `backend` / `decimal` | No | Hoja1!J6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 18 | `radius_m` | Radio (m) · Dasometría | `backend` / `decimal` | No | Hoja1!K6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 19 | `basal_area_m2` | Área basal (m²) · Dasometría | `backend` / `decimal` | No | Hoja1!L6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 20 | `number_of_stems` | Número de fustes o estípites · Dasometría | `input` / `integer` | No | Hoja1!M6 | Entero positivo como texto; máximo BIGINT. No notación científica. |
| 21 | `form_factor` | Factor de forma · Dasometría | `input` / `decimal` | No | Hoja1!N6 | Texto con punto decimal, sin miles ni notación científica; hasta seis decimales. Entre 0.45 y 0.70, incluidos. |
| 22 | `total_height_m` | Altura total (m) · Dasometría | `input` / `decimal` | No | Hoja1!O6 | Texto con punto decimal, sin miles ni notación científica; hasta seis decimales. |
| 23 | `commercial_height_m` | Altura comercial (m) · Dasometría | `input` / `decimal` | No | Hoja1!P6 | Texto con punto decimal, sin miles ni notación científica; hasta seis decimales. No puede superar la altura total. |
| 24 | `commercial_volume_m3` | Volumen comercial (m³) · Dasometría | `backend` / `decimal` | No | Hoja1!Q6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 25 | `total_volume_m3` | Volumen total (m³) · Dasometría | `backend` / `decimal` | No | Hoja1!R6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 26 | `crown_x_m` | Copa X (m) · Dasometría | `input` / `decimal` | No | Hoja1!S6 | Texto con punto decimal, sin miles ni notación científica; hasta seis decimales. |
| 27 | `crown_y_m` | Copa Y (m) · Dasometría | `input` / `decimal` | No | Hoja1!T6 | Texto con punto decimal, sin miles ni notación científica; hasta seis decimales. |
| 28 | `diameter_class_number` | Clase diamétrica · Dasometría | `backend` / `integer` | No | Hoja1!U6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 29 | `height_class_number` | Clase de altura · Dasometría | `backend` / `integer` | No | Hoja1!V6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 30 | `maturity_state` | Estado de madurez · Dasometría | `backend` / `text` | No | Hoja1!W6 | Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas. |
| 31 | `condition_PHY_ROOT_BAD_ANCHORAGE` | Física / Sistema radicular / Anclaje malo · Condiciones | `input` / `boolean` | No | Hoja1!X6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 32 | `condition_PHY_ROOT_GOOD_ANCHORAGE` | Física / Sistema radicular / Anclaje bueno · Condiciones | `input` / `boolean` | No | Hoja1!Y6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 33 | `condition_PHY_ROOT_BUTTRESSES` | Física / Sistema radicular / Aletones · Condiciones | `input` / `boolean` | No | Hoja1!Z6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 34 | `condition_PHY_ROOT_INSUFFICIENT_CONFINEMENT` | Física / Sistema radicular / Confinamiento insuficiente · Condiciones | `input` / `boolean` | No | Hoja1!AA6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 35 | `condition_PHY_STEM_STRAIGHT` | Física / Fuste o estípite / Recto · Condiciones | `input` / `boolean` | No | Hoja1!AB6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 36 | `condition_PHY_STEM_CROOKED` | Física / Fuste o estípite / Torcido · Condiciones | `input` / `boolean` | No | Hoja1!AC6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 37 | `condition_PHY_STEM_TWISTED` | Física / Fuste o estípite / Retorcido · Condiciones | `input` / `boolean` | No | Hoja1!AD6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 38 | `condition_PHY_STEM_LOW_BRANCHING` | Física / Fuste o estípite / Ramificación abajo · Condiciones | `input` / `boolean` | No | Hoja1!AE6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 39 | `condition_PHY_STEM_LEAN_UNDER_30` | Física / Fuste o estípite / Inclinado < de 30 grados · Condiciones | `input` / `boolean` | No | Hoja1!AF6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 40 | `condition_PHY_STEM_LEAN_OVER_30` | Física / Fuste o estípite / Inclinado > de 30 grados · Condiciones | `input` / `boolean` | No | Hoja1!AG6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 41 | `condition_PHY_STEM_VERTICAL_CRACKS` | Física / Fuste o estípite / Grietas o fisuras  verticales · Condiciones | `input` / `boolean` | No | Hoja1!AH6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 42 | `condition_PHY_STEM_HORIZONTAL_CRACKS` | Física / Fuste o estípite / Grietas o fisuras horizontales · Condiciones | `input` / `boolean` | No | Hoja1!AI6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 43 | `condition_PHY_STEM_PRICKLES` | Física / Fuste o estípite / Aguijones · Condiciones | `input` / `boolean` | No | Hoja1!AJ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 44 | `condition_PHY_STEM_THORNS` | Física / Fuste o estípite / Espinas · Condiciones | `input` / `boolean` | No | Hoja1!AK6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 45 | `condition_PHY_STEM_IMPACT_MARKS` | Física / Fuste o estípite / Marcas por impacto · Condiciones | `input` / `boolean` | No | Hoja1!AL6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 46 | `condition_PHY_BRANCH_MORE_THAN_ONE_PER_NODE` | Física / Ramas / Mas de una por nudo · Condiciones | `input` / `boolean` | No | Hoja1!AM6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 47 | `condition_PHY_BRANCH_HANGING` | Física / Ramas / Colgantes · Condiciones | `input` / `boolean` | No | Hoja1!AN6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 48 | `condition_PHY_BRANCH_DEAD` | Física / Ramas / Muertas · Condiciones | `input` / `boolean` | No | Hoja1!AO6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 49 | `condition_PHY_BRANCH_SUCKER_SHOOTS` | Física / Ramas / Chupones · Condiciones | `input` / `boolean` | No | Hoja1!AP6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 50 | `condition_PHY_BRANCH_POORLY_PRUNED` | Física / Ramas / Mal podadas · Condiciones | `input` / `boolean` | No | Hoja1!AQ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 51 | `condition_PHY_CROWN_ALTERED` | Física / Copa / Alterada · Condiciones | `input` / `boolean` | No | Hoja1!AR6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 52 | `condition_PHY_CROWN_UNALTERED` | Física / Copa / Sin alteración · Condiciones | `input` / `boolean` | No | Hoja1!AS6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 53 | `condition_PHY_CROWN_UNBALANCED_BY_PRUNING` | Física / Copa / Desestabilizada por malas podas · Condiciones | `input` / `boolean` | No | Hoja1!AT6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 54 | `condition_PHT_ROOT_VISIBLE_ROOTS` | Fitosanitaria / Sistema radicular / Raices visibles · Condiciones | `input` / `boolean` | No | Hoja1!AU6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 55 | `condition_PHT_ROOT_TWISTED_ROOTS` | Fitosanitaria / Sistema radicular / Raices retorcidas · Condiciones | `input` / `boolean` | No | Hoja1!AV6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 56 | `condition_PHT_ROOT_PRUNED_BY_WORKS` | Fitosanitaria / Sistema radicular / Podas por acción de obras · Condiciones | `input` / `boolean` | No | Hoja1!AW6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 57 | `condition_PHT_STEM_HEALTHY` | Fitosanitaria / Fuste o estípite / Sano · Condiciones | `input` / `boolean` | No | Hoja1!AX6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 58 | `condition_PHT_STEM_GALLS_OR_TUMORS` | Fitosanitaria / Fuste o estípite / Agallas o tumores · Condiciones | `input` / `boolean` | No | Hoja1!AY6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 59 | `condition_PHT_STEM_FUNGI` | Fitosanitaria / Fuste o estípite / Hongos · Condiciones | `input` / `boolean` | No | Hoja1!AZ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 60 | `condition_PHT_STEM_ROT` | Fitosanitaria / Fuste o estípite / Pudrición · Condiciones | `input` / `boolean` | No | Hoja1!BA6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 61 | `condition_PHT_STEM_NECROSIS` | Fitosanitaria / Fuste o estípite / Necrosis · Condiciones | `input` / `boolean` | No | Hoja1!BB6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 62 | `condition_PHT_STEM_CAVITY_UNDER_30` | Fitosanitaria / Fuste o estípite / Oquedad media < 30% · Condiciones | `input` / `boolean` | No | Hoja1!BC6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 63 | `condition_PHT_STEM_CAVITY_OVER_30` | Fitosanitaria / Fuste o estípite / Oquedad grande > 30% · Condiciones | `input` / `boolean` | No | Hoja1!BD6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 64 | `condition_PHT_STEM_GIRDLING` | Fitosanitaria / Fuste o estípite / Anillamiento · Condiciones | `input` / `boolean` | No | Hoja1!BE6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 65 | `condition_PHT_STEM_CONSTRICTING_ELEMENTS` | Fitosanitaria / Fuste o estípite / Elementos de estrangulamiento · Condiciones | `input` / `boolean` | No | Hoja1!BF6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 66 | `condition_PHT_STEM_PARASITIC_HEMIPARASITIC_EPIPHYTES` | Fitosanitaria / Fuste o estípite / Epífitas parásitas o hemiparasitas · Condiciones | `input` / `boolean` | No | Hoja1!BG6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 67 | `condition_PHT_STEM_VINE` | Fitosanitaria / Fuste o estípite / Bejuco o liana · Condiciones | `input` / `boolean` | No | Hoja1!BH6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 68 | `condition_PHT_STEM_STRANGLER` | Fitosanitaria / Fuste o estípite / Matapalo · Condiciones | `input` / `boolean` | No | Hoja1!BI6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 69 | `condition_PHT_STEM_TERMITES` | Fitosanitaria / Fuste o estípite / Termitas o comején · Condiciones | `input` / `boolean` | No | Hoja1!BJ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 70 | `condition_PHT_STEM_INSECT_HOST` | Fitosanitaria / Fuste o estípite / Hospedero insectos · Condiciones | `input` / `boolean` | No | Hoja1!BK6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 71 | `condition_PHT_BRANCH_ROT` | Fitosanitaria / Ramas / Podridas · Condiciones | `input` / `boolean` | No | Hoja1!BL6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 72 | `condition_PHT_BRANCH_FUNGI` | Fitosanitaria / Ramas / Hongos · Condiciones | `input` / `boolean` | No | Hoja1!BM6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 73 | `condition_PHT_BRANCH_PARASITIC_HEMIPARASITIC_EPIPHYTES` | Fitosanitaria / Ramas / Epífitas parásitas o hemiparasitas · Condiciones | `input` / `boolean` | No | Hoja1!BN6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 74 | `condition_PHT_BRANCH_DEAD` | Fitosanitaria / Ramas / Muertas · Condiciones | `input` / `boolean` | No | Hoja1!BO6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 75 | `condition_PHT_FOLIAGE_SCANT` | Fitosanitaria / Follaje / Escaso · Condiciones | `input` / `boolean` | No | Hoja1!BP6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 76 | `condition_PHT_FOLIAGE_ABUNDANT` | Fitosanitaria / Follaje / Abundante · Condiciones | `input` / `boolean` | No | Hoja1!BQ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 77 | `condition_PHT_FOLIAGE_HEALTHY` | Fitosanitaria / Follaje / Sano · Condiciones | `input` / `boolean` | No | Hoja1!BR6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 78 | `condition_PHT_FOLIAGE_CHLOROSIS` | Fitosanitaria / Follaje / Clorosis · Condiciones | `input` / `boolean` | No | Hoja1!BS6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 79 | `condition_PHT_FOLIAGE_CURLING` | Fitosanitaria / Follaje / Entorchamiento · Condiciones | `input` / `boolean` | No | Hoja1!BT6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 80 | `condition_PHT_FOLIAGE_WILTING` | Fitosanitaria / Follaje / Marchitamiento · Condiciones | `input` / `boolean` | No | Hoja1!BU6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 81 | `condition_PHT_FOLIAGE_LOCALIZED_ROT` | Fitosanitaria / Follaje / Pudrición localizada · Condiciones | `input` / `boolean` | No | Hoja1!BV6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 82 | `condition_PHT_FOLIAGE_PARASITIC_HEMIPARASITIC_EPIPHYTES` | Fitosanitaria / Follaje / Epífitas parásitas o hemiparasitas · Condiciones | `input` / `boolean` | No | Hoja1!BW6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 83 | `condition_PHT_FOLIAGE_ABNORMAL_LEAF_ABSCISSION` | Fitosanitaria / Follaje / Absición foliar anormal - No desfoliación genetica · Condiciones | `input` / `boolean` | No | Hoja1!BX6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 84 | `condition_PHT_FLOWER_FRUIT_SCANT` | Fitosanitaria / Flores y frutos / Escasa · Condiciones | `input` / `boolean` | No | Hoja1!BY6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 85 | `condition_PHT_FLOWER_FRUIT_ABUNDANT` | Fitosanitaria / Flores y frutos / Abundante · Condiciones | `input` / `boolean` | No | Hoja1!BZ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 86 | `condition_PHT_FLOWER_FRUIT_HEALTHY` | Fitosanitaria / Flores y frutos / Sana · Condiciones | `input` / `boolean` | No | Hoja1!CA6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 87 | `condition_PHT_FLOWER_FRUIT_LOCAL_OR_GENERAL_ROT` | Fitosanitaria / Flores y frutos / Pudrición localizada o general · Condiciones | `input` / `boolean` | No | Hoja1!CB6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 88 | `condition_PHT_FLOWER_FRUIT_WILTING_BEFORE_POLLINATION_DURING_FRUITING` | Fitosanitaria / Flores y frutos / Marchitamiento antes de polinización - En fructificación · Condiciones | `input` / `boolean` | No | Hoja1!CC6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 89 | `condition_PHT_FLOWER_FRUIT_ABNORMAL_FLOWER_FRUIT_ABSCISSION` | Fitosanitaria / Flores y frutos / Absición anormal de flores y/o frutos · Condiciones | `input` / `boolean` | No | Hoja1!CD6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 90 | `condition_PHT_GENERAL_HEALTHY` | Fitosanitaria / Estado fitosanitario general del árbol / Sano · Condiciones | `input` / `boolean` | No | Hoja1!CG6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 91 | `condition_PHT_GENERAL_DIEBACK` | Fitosanitaria / Estado fitosanitario general del árbol / Muerte descendente · Condiciones | `input` / `boolean` | No | Hoja1!CH6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 92 | `condition_PHT_GENERAL_DYING_OVER_50` | Fitosanitaria / Estado fitosanitario general del árbol / Proceso de muerte > 50% · Condiciones | `input` / `boolean` | No | Hoja1!CI6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 93 | `condition_PHT_GENERAL_DYING_UNDER_50` | Fitosanitaria / Estado fitosanitario general del árbol / Proceso de muerte < 50% · Condiciones | `input` / `boolean` | No | Hoja1!CJ6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 94 | `condition_PHT_GENERAL_BENIGN_INSECT_HOST` | Fitosanitaria / Estado fitosanitario general del árbol / Hospedero insectos benignos - Avispas, abejas · Condiciones | `input` / `boolean` | No | Hoja1!CK6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 95 | `condition_PHT_GENERAL_HARMFUL_INSECT_HOST` | Fitosanitaria / Estado fitosanitario general del árbol / Hospedero insectos dañinos - Trozadores, perforadores · Condiciones | `input` / `boolean` | No | Hoja1!CL6 | 1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada. |
| 96 | `flowering_date` | Floración / fecha · Fenología | `input` / `date` | No | Hoja1!CE6 | Texto AAAA-MM-DD; vacío si no se conoce. |
| 97 | `flowering_development` | Floración / desarrollo · Fenología | `input` / `text` | No | Hoja1!CE6 | Descripción observada; no se inventa un catálogo de estados. |
| 98 | `fruiting_date` | Fructificación / fecha · Fenología | `input` / `date` | No | Hoja1!CF6 | Texto AAAA-MM-DD; vacío si no se conoce. |
| 99 | `fruiting_development` | Fructificación / desarrollo · Fenología | `input` / `text` | No | Hoja1!CF6 | Descripción observada; no se inventa un catálogo de estados. |
| 100 | `fauna_category_codes` | Fauna asociada · Fauna | `input` / `catalog_list` | No | Hoja1!CM6 | Códigos exactos de fauna_category_codes separados por &#124;, sin repetir. |
| 101 | `fauna_observations` | Observaciones de fauna · Fauna | `input` / `text` | No | Hoja1!CM6 | Texto observado; hasta 20.000 caracteres. |
| 102 | `fauna_photo_urls` | Foto fauna · Evidencias | `evidence` / `text` | No | Hoja1!CN4 | Dejar vacío. Las fotografías se cargan por el módulo de evidencias después de importar; no se descargan URLs. |
| 103 | `tree_photo_urls` | Foto individuo · Evidencias | `evidence` / `text` | No | Hoja1!CO4 | Dejar vacío. No se admiten fotografías incrustadas ni enlaces para su carga. |
| 104 | `general_observations` | Observaciones de inspección · Observaciones | `input` / `text` | No | Hoja1!CP4 | Texto; hasta 20.000 caracteres. |
| 105 | `location_description` | Descripción de ubicación · Ubicación | `input` / `text` | No | Campo complementario | Texto; hasta 2.000 caracteres. |
| 106 | `datum` | Datum declarado · Ubicación | `input` / `text` | No | Campo complementario | Declarar WGS84 cuando corresponda; no se transforma otro datum automáticamente. |
| 107 | `accuracy_m` | Exactitud declarada (m) · Ubicación | `input` / `decimal` | No | Campo complementario | Texto numérico no negativo; no se infiere. |
| 108 | `gps_brand` | Marca GPS · Ubicación | `input` / `text` | No | Campo complementario | Texto validado por el contrato de captura. |
| 109 | `gps_model` | Modelo GPS · Ubicación | `input` / `text` | No | Campo complementario | Texto validado por el contrato de captura. |
| 110 | `captured_at` | Fecha de captura de ubicación · Ubicación | `input` / `datetime` | No | Campo complementario | Texto ISO 8601 con zona, por ejemplo 2026-01-31T10:30:00-05:00. |

## Persistencia por responsabilidad

| Grupo | Destino y regla |
| --- | --- |
| Identidad, taxonomía y ubicación | `Tree`; los códigos de catálogo se resuelven a referencias nacionales. Las coordenadas pasan por `TreeLocationService` y el repositorio espacial. |
| Campaña | `Inventory` seleccionado en la pantalla y participación `inventory_trees` creada para cada árbol. El archivo no decide `organization_id` ni el destino. |
| Inspección y dasometría | `TreeInspection`, con entradas y derivados recalculados por `DendrometryCalculationService`. Cada fila registra una primera inspección. |
| Condiciones y fauna | Relaciones de la inspección mediante los servicios y repositorios existentes; no se insertan nuevos catálogos. |
| Fenología y observaciones | Campos de la inspección, conservando fechas, desarrollo y texto en forma independiente. |
| Evidencias | No se crean desde Excel. Se adjuntan luego por las rutas autorizadas de Evidencias. |
| Auditoría de carga | `ImportBatch` y `ImportRow`: fuente leída, errores, normalización, actores, huella y referencias de los registros confirmados. |

Los decimales se conservan como cadenas durante la lectura; los cálculos se obtienen en backend y no proceden del valor cacheado de una fórmula de Excel. Las celdas sin dato no se sustituyen por mediciones de cero. La semántica forestal detallada permanece en los servicios de dominio y en la documentación de inspecciones.

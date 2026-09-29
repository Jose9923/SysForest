# Corrección de estados de Restrepo 2025

La carga inicial asignó `Activo` a los 2.854 árboles por la instrucción recibida durante la preparación. Las condiciones y los 65 registros «Muerto en pie» se conservaron, pero no cambiaban automáticamente el estado del individuo. El usuario posteriormente solicitó corregirlo y eligió **«Plagas, señales de enfermedad y daños estructurales que requieren revisión»** como criterio de seguimiento.

## Significado y resultado

| Estado | Significado operativo | Árboles de Restrepo |
| --- | --- | ---: |
| Activo | Estado general; no certifica salud ni ausencia de hallazgos. Permanecen aquí quienes no cumplen los criterios seleccionados de esta revisión. | 892 |
| En observación | Requiere revisión o seguimiento por señales registradas de plagas, enfermedad o daños estructurales. No es por sí mismo un diagnóstico ni una categoría de riesgo. | 1.897 |
| Muerto | El individuo figura como «Muerto en pie» en la fuente. Una rama muerta o un proceso de muerte no equivalen a todo el individuo muerto. | 65 |

El total sigue siendo **2.854 árboles**. Se aplicaron 1.962 cambios de estado con su motivo: 65 a Muerto y 1.897 a En observación. No se anularon ni eliminaron árboles, ni se generaron órdenes de mantenimiento automáticamente. INEM no forma parte de esta corrección.

## Fuente y criterio aprobado

Libro: `Planilla Plan Ornato Restrepo 2025 Total para riesgos.xlsx`, hoja `CENSO FORESTAL URBANO`. SHA-256 original: `54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63`. Se conservaron las inspecciones con fecha de referencia de entrega **30/11/2025** y recolección septiembre–noviembre de 2025. Esta operación es una corrección de la clasificación cargada; **no representa una visita de campo nueva ni certifica la condición del árbol en la fecha de corrección**.

La muerte explícita tiene prioridad sobre los demás hallazgos. Para los otros individuos se utiliza al menos una de las 41 condiciones seleccionadas inequívocamente. Las categorías se solapan; no deben sumarse sus cantidades:

| Grupo de hallazgos | Individuos sin marca explícita «Muerto en pie» |
| --- | ---: |
| Daños físicos y mecánicos | 1531 |
| Raíces retorcidas o afectadas por obras | 70 |
| Daños y señales de enfermedad en el fuste | 619 |
| Insectos dañinos o termitas | 169 |
| Daños y señales de enfermedad en ramas | 299 |
| Señales de enfermedad en follaje | 460 |
| Señales de afectación en flores o frutos | 4 |
| Deterioro general, muerte descendente o proceso de muerte | 112 |

Se incluyen, por ejemplo, anclaje malo, confinamiento insuficiente, inclinación mayor de 30 grados, fisuras, marcas por impacto, ramas colgantes o muertas, malas podas, copa alterada o desestabilizada, afectación radicular, agallas, hongos, pudrición, necrosis, oquedades, estrangulamiento, parasitismo, termitas, clorosis y marchitamiento. Se usa la presencia registrada para solicitar seguimiento; no se deduce su severidad ni una probabilidad de caída.

Los insectos benignos (como abejas y avispas), un hospedero genérico de insectos sin daño declarado, bejucos solo por su presencia, raíces visibles y rasgos morfológicos normales no activan seguimiento por sí solos. Tampoco lo hacen las selecciones Sano/Abundante ni la escasez de flores o follaje sin otro signo elegido. Entre los 892 que permanecen Activo hay cinco con insectos benignos y diez con hospedero genérico sin otros criterios de esta revisión.

Las 50 marcas ambiguas originales no se convirtieron en condiciones seleccionadas. Cinco textos en la columna de insectos dañinos ya correspondían a individuos con otros daños inequívocos; no fue necesario interpretar «hormigas» como plaga. Los pendientes de identificación, medición, coordenadas y otros datos originales permanecen disponibles para revisión.

## Trazabilidad y verificación

Se utilizaron `TenantContextService` y `TreeLifecycleService::status`, que revalidan permisos, bloquean actor/organización/árbol, comprueban `lock_version`, actualizan el estado y registran `tree_status_histories` y `tree.status_changed`. Una única transacción confirmó el conjunto. El motivo de cada árbol identifica la fila o celda del Excel y sus hallazgos; la fecha del historial es la fecha real de esta corrección.

Antes de escribir se verificó que la última inspección siguiera siendo la importada, con versión 1, campaña ORNATO-2025 y evidencia coincidente. Una discrepancia habría revertido toda la corrección. No se editan filas de importación, selecciones de condiciones ni inspecciones para hacerlas coincidir artificialmente con el nuevo estado.

La conciliación confirmó:

- Los estados exactos de los 2.854 individuos y los 1.962 incrementos de versión esperados.
- 1.962 historiales nuevos y 1.962 eventos de cambio de estado, con motivo y autor.
- Conservación de todos los historiales anteriores, incluidos los estados iniciales de la importación.
- Inspecciones, revisiones, condiciones, participaciones, importaciones, ubicaciones, evidencias, mantenimientos, inventarios y organizaciones sin cambios.
- Identidad y atributos no relacionados con el estado preservados; los árboles fuera del manifiesto, incluido INEM, sin modificaciones.

La interfaz incorpora una ayuda compartida en el formulario de árbol y en el cambio de estado. Se verificaron compilación Blade, sintaxis de las tres vistas y renderizado de la ayuda. No se crearon ni ejecutaron suites automatizadas. La inspección de un árbol y su estado continúan siendo decisiones separadas; no se añadió una regla automática global para futuras visitas o importaciones.

El manifiesto de operación está en almacenamiento privado: `/var/www/html/storage/app/private/reviewed-statuses/restrepo-e94b6021b8f81e4556c5e10f554e1b9277e81cd2b0155fc2fc91451431849e6c.json` (SHA-256 `e94b6021b8f81e4556c5e10f554e1b9277e81cd2b0155fc2fc91451431849e6c`). El respaldo previo está en `/home/josev/.local/state/sysforest/backups/before-status-review-20260929T035904Z.dump`; se comprobó su lectura y no se ejecutó una restauración. La conciliación, copia revisada y evidencia por registro se conservan en `.status-review-work` y `.restrepo-condition-work` del espacio de trabajo de Windows.

## Condiciones consideradas

| Código | Nombre en catálogo | Grupo |
| --- | --- | --- |
| `PHY_ROOT_BAD_ANCHORAGE` | Anclaje malo | Daños físicos y mecánicos |
| `PHY_ROOT_INSUFFICIENT_CONFINEMENT` | Confinamiento insuficiente | Daños físicos y mecánicos |
| `PHY_STEM_LEAN_OVER_30` | Inclinado > de 30 grados | Daños físicos y mecánicos |
| `PHY_STEM_VERTICAL_CRACKS` | Grietas o fisuras  verticales | Daños físicos y mecánicos |
| `PHY_STEM_HORIZONTAL_CRACKS` | Grietas o fisuras horizontales | Daños físicos y mecánicos |
| `PHY_STEM_IMPACT_MARKS` | Marcas por impacto | Daños físicos y mecánicos |
| `PHY_BRANCH_HANGING` | Colgantes | Daños físicos y mecánicos |
| `PHY_BRANCH_DEAD` | Muertas | Daños físicos y mecánicos |
| `PHY_BRANCH_POORLY_PRUNED` | Mal podadas | Daños físicos y mecánicos |
| `PHY_CROWN_ALTERED` | Alterada | Daños físicos y mecánicos |
| `PHY_CROWN_UNBALANCED_BY_PRUNING` | Desestabilizada por malas podas | Daños físicos y mecánicos |
| `PHT_ROOT_TWISTED_ROOTS` | Raices retorcidas | Raíces retorcidas o afectadas por obras |
| `PHT_ROOT_PRUNED_BY_WORKS` | Podas por acción de obras | Raíces retorcidas o afectadas por obras |
| `PHT_STEM_GALLS_OR_TUMORS` | Agallas o tumores | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_FUNGI` | Hongos | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_ROT` | Pudrición | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_NECROSIS` | Necrosis | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_CAVITY_UNDER_30` | Oquedad media < 30% | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_CAVITY_OVER_30` | Oquedad grande > 30% | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_GIRDLING` | Anillamiento | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_CONSTRICTING_ELEMENTS` | Elementos de estrangulamiento | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_PARASITIC_HEMIPARASITIC_EPIPHYTES` | Epífitas parásitas o hemiparasitas | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_STRANGLER` | Matapalo | Daños y señales de enfermedad en el fuste |
| `PHT_STEM_TERMITES` | Termitas o comején | Insectos dañinos o termitas |
| `PHT_GENERAL_HARMFUL_INSECT_HOST` | Hospedero insectos dañinos - Trozadores, perforadores | Insectos dañinos o termitas |
| `PHT_BRANCH_ROT` | Podridas | Daños y señales de enfermedad en ramas |
| `PHT_BRANCH_FUNGI` | Hongos | Daños y señales de enfermedad en ramas |
| `PHT_BRANCH_PARASITIC_HEMIPARASITIC_EPIPHYTES` | Epífitas parásitas o hemiparasitas | Daños y señales de enfermedad en ramas |
| `PHT_BRANCH_DEAD` | Muertas | Daños y señales de enfermedad en ramas |
| `PHT_FOLIAGE_CHLOROSIS` | Clorosis | Señales de enfermedad en follaje |
| `PHT_FOLIAGE_CURLING` | Entorchamiento | Señales de enfermedad en follaje |
| `PHT_FOLIAGE_WILTING` | Marchitamiento | Señales de enfermedad en follaje |
| `PHT_FOLIAGE_LOCALIZED_ROT` | Pudrición localizada | Señales de enfermedad en follaje |
| `PHT_FOLIAGE_PARASITIC_HEMIPARASITIC_EPIPHYTES` | Epífitas parásitas o hemiparasitas | Señales de enfermedad en follaje |
| `PHT_FOLIAGE_ABNORMAL_LEAF_ABSCISSION` | Absición foliar anormal - No desfoliación genetica | Señales de enfermedad en follaje |
| `PHT_FLOWER_FRUIT_LOCAL_OR_GENERAL_ROT` | Pudrición localizada o general | Señales de afectación en flores o frutos |
| `PHT_FLOWER_FRUIT_WILTING_BEFORE_POLLINATION_DURING_FRUITING` | Marchitamiento antes de polinización - En fructificación | Señales de afectación en flores o frutos |
| `PHT_FLOWER_FRUIT_ABNORMAL_FLOWER_FRUIT_ABSCISSION` | Absición anormal de flores y/o frutos | Señales de afectación en flores o frutos |
| `PHT_GENERAL_DIEBACK` | Muerte descendente | Deterioro general, muerte descendente o proceso de muerte |
| `PHT_GENERAL_DYING_OVER_50` | Proceso de muerte > 50% | Deterioro general, muerte descendente o proceso de muerte |
| `PHT_GENERAL_DYING_UNDER_50` | Proceso de muerte < 50% | Deterioro general, muerte descendente o proceso de muerte |

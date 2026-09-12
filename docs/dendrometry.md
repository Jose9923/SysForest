# Dasonomía y clasificación de inspecciones

## Alcance y trazabilidad

La Fase 7 implementa las variables I:W de la [matriz de 94 campos](excel-field-mapping.md) en TreeInspection. Tree conserva la identidad, ubicación y estado del individuo; no recibe copias de CAP, DAP, alturas, volúmenes ni clases.

Las siete fórmulas del Excel y sus unidades se mantienen. La madurez W7 era un texto manual en la fuente; su cálculo operativo se activa mediante EXCEL_V1, aprobada y sellada en Fase 5. La columna «Derivada» de la matriz describe el archivo de referencia y no se altera para simular que W7 tenía fórmula.

## Entradas observadas

DendrometryInput centraliza rules(), messages() y validate(array): array. Requests y servicios de inspección reutilizan estas reglas. validate devuelve exactamente siete claves, con cadenas decimales canónicas de seis posiciones, number_of_stems entero y valores desconocidos NULL.

| Columna Excel | Campo | Unidad | PostgreSQL | Validación si existe |
| --- | --- | --- | --- | --- |
| I | cap_cm | cm | NUMERIC(18,6), nullable | Mayor que cero. |
| M | number_of_stems | fustes o estípites | BIGINT, nullable | Entero positivo, hasta 9223372036854775807. |
| N | form_factor | adimensional | NUMERIC(7,6), nullable | Entre 0.45 y 0.70 inclusive. |
| O | total_height_m | m | NUMERIC(18,6), nullable | Mayor que cero. |
| P | commercial_height_m | m | NUMERIC(18,6), nullable | Mayor o igual a cero; no supera total_height_m cuando ambas se conocen. |
| S | crown_x_m | m | NUMERIC(18,6), nullable | Mayor o igual a cero. |
| T | crown_y_m | m | NUMERIC(18,6), nullable | Mayor o igual a cero. |

number_of_stems adopta el nombre explícito de la petición F7 §12 y corresponde al destino anteriormente propuesto como stem_count. No existen ambos campos ni se cambia el encabezado de la variable M.

No se exige completar todas las medidas. Una altura comercial conocida puede registrarse aunque no se conozca la altura total; no se inventa esta última para validar la primera. Cero significa una observación válida únicamente en altura comercial y ejes de copa. La ausencia es NULL y nunca se convierte en cero.

El rango del factor de forma procede del encabezado N6. No se exige un paso de 0.05 ni se copia el step del mockup. Los doce enteros y seis decimales del resto de medidas son un límite técnico de representación, no un máximo biológico. No se asignan máximos de altura, diámetro o copa derivados de suposiciones sobre especies.

Las entradas decimales se reciben como cadenas con punto; también se admiten enteros JSON. Se rechazan floats JSON, booleanos, NaN, INF, notación científica, comas decimales y exceso de precisión. Se requiere una parte entera: 0.5 es válido; .5 no. El formulario envía las medidas como texto para conservar su representación decimal. No se redondea una entrada inválida para aceptarla.

La comparación entre alturas usa BCMath con seis decimales. Distingue incluso 999999999999.999998 de 999999999999.999999, aunque una conversión a double pudiera igualarlos.

## Fórmulas y precisión

DendrometryArithmetic implementa únicamente DENDROMETRY_V1. Usa BCMath con escala interna 40 y esta aproximación técnica fija de PI, con 50 decimales:

~~~text
3.14159265358979323846264338327950288419716939937510
~~~

La identidad de versión fija tanto las fórmulas como PI, las escalas y el redondeo. Cambiar cualquiera de esos elementos requiere una versión nueva y conservar la implementación anterior para las inspecciones históricas.

| Resultado | Fórmula aplicada |
| --- | --- |
| dap_m | cap_cm / 100 / PI |
| radius_m | dap_m / 2 |
| basal_area_m2 | PI × radius_m² |
| commercial_volume_m3 | basal_area_m2 × commercial_height_m × form_factor |
| total_volume_m3 | basal_area_m2 × total_height_m × form_factor |

Cada fórmula usa el resultado interno de la anterior. Solo después de obtener todos los resultados se normaliza la persistencia a 12 decimales, con round-half-up para valores no negativos. No se calcula el área a partir del DAP mostrado ni el volumen a partir del área ya normalizada.

| Resultado | Unidad | PostgreSQL |
| --- | --- | --- |
| dap_m / radius_m | m | NUMERIC(24,12), nullable |
| basal_area_m2 | m² | NUMERIC(36,12), nullable |
| commercial_volume_m3 / total_volume_m3 | m³ | NUMERIC(48,12), nullable |
| diameter_class_number / height_class_number | número de clase | BIGINT, nullable, positivo |
| maturity_state_id | referencia | UUID nullable a maturity_states |
| classification_rule_version_id | referencia | UUID requerido a classification_rule_versions |
| calculation_rule_version | código | varchar(40), requerido; DENDROMETRY_V1 |

Las capacidades mayores de área y volumen admiten resultados de todo el rango técnico de entradas sin introducir máximos biológicos. Los números de clase usan BIGINT porque el rango decimal admitido puede producir clases superiores a un entero de 32 bits.

La resolución almacenada es 10⁻¹² en la unidad de cada resultado. Un área positiva menor que media unidad de esa resolución puede normalizarse a cero; se conserva el CAP observado y los cálculos posteriores no reutilizan ese cero. Por ejemplo, CAP=0.000001 cm produce un área inferior a la resolución almacenada, pero con altura=999999999999.999999 m y factor=0.5 el volumen se calcula internamente y persiste como 0.000003978874 m³. Esta es una prueba de estabilidad numérica, no un escenario biológico ni una exactitud atribuida al levantamiento.

La presentación puede mostrar menos posiciones decimales; ese texto nunca vuelve como operando de cálculo.

## Nulos y dependencias

- Sin CAP: DAP, radio, área, clase diamétrica y madurez quedan NULL.
- Sin altura total: la clase de altura y el volumen total quedan NULL.
- Cada volumen requiere CAP, factor de forma y su propia altura. Con alguno ausente no se fabrica un resultado.
- Altura comercial cero produce volumen comercial cero cuando los otros operandos son conocidos.
- Copa X/Y y número de fustes son observaciones; no intervienen en las fórmulas documentadas.

El ejemplo del libro tiene M7=3, pero Q7/R7 no multiplican por fustes. Fase 7 conserva number_of_stems y no aplica una fórmula multifuste. Sigue pendiente definir si deben medirse individualmente los fustes y cómo agregar sus áreas o volúmenes.

## Clases sin redondeo previo

EXCEL_V1 usa diámetro en centímetros, paso 5, y altura total en metros, paso 10. Cada intervalo es abierto por abajo y cerrado por arriba:

~~~text
diameter_class_number = ceil(DAP_cm / 5)
height_class_number = ceil(total_height_m / 10)
~~~

ClassificationService::diameter y height conservan el contrato F5: entrada decimal positiva de hasta doce enteros y seis decimales. DecimalClassification::classify continúa rechazando exceso de precisión y entradas no positivas.

Para el diámetro calculado se añade ClassificationService::diameterRatio(numerator, denominator, versionId), respaldado por DecimalClassification::classifyRatio. DendrometryCalculationService entrega CAP como numerador y la constante PI como denominador. Así se resuelve:

~~~text
ceil((CAP_cm / PI) / diameter_step)
= ceil(CAP_cm / (PI × diameter_step))
~~~

El cociente entero y el resto se calculan exactamente sobre cadenas decimales, respecto a la constante PI versionada. No se redondea CAP/PI a seis o doce posiciones para decidir la clase. La precisión interna del cociente no amplía el contrato público de entrada manual de Fase 5.

| Entrada | Resultado esperado |
| --- | --- |
| DAP=5.000000 cm | Clase 1 |
| DAP=5.00000000000000000001 cm, cociente interno | Clase 2 |
| DAP=10 cm | Clase 2 |
| DAP=10.00000000000000000001 cm, cociente interno | Clase 3 |
| CAP=15.707964 cm | Clase 2; DAP_cm redondeado a seis posiciones sería 5.000000 |
| CAP=31.415927 cm | Clase 3; DAP_cm redondeado a seis posiciones sería 10.000000 |
| Altura total=10 m | Clase 1 |
| Altura total=10.000001 m | Clase 2 |
| Altura total=20 m | Clase 2 |

No hay un máximo de clases sembrado. El límite entero técnico se comprueba antes de convertir el resultado a un int de PHP.

## Madurez y versiones

La clase diamétrica determina la madurez conforme a las bandas selladas:

| Clase | Código de madurez | Etiqueta de origen |
| --- | --- | --- |
| 1–3 | MAT_YOUNG | Fustal joven |
| 4–6 | MAT_MEDIUM | Fustal medio |
| 7–9 | MAT_MATURE | Fustal maduro |
| ≥10 | MAT_OVERMATURE | Fustal sobremaduro |

No se introducen equivalencias con Juvenil, Adulto ni Senescente. No se calcula madurez si CAP falta.

calculate(array $inputs, ?string $versionId=null): array devuelve exclusivamente las cinco medidas derivadas, dos clases, maturity_state_id y las dos referencias de versión: diez claves. No devuelve nombres para la vista ni consulta datos de Tree.

Con versionId=NULL se selecciona EXCEL_V1 activa y sellada. La creación nueva también requiere que la madurez derivada esté activa. Un ID explícito procede del registro histórico autorizado, permite resolver esa misma versión sellada aunque se desactive y conserva sus estados históricos. El servicio de inspección nunca toma dicho ID de un campo manipulable del cliente.

En una corrección se fusionan primero las siete entradas existentes con los cambios solicitados. Se recalcula a partir de esas medidas usando classification_rule_version_id original. Fase 7 soporta una sola versión de fórmulas, DENDROMETRY_V1, que queda registrada; no existe un selector libre de algoritmos en el formulario. TreeInspectionService comprueba que calculation_rule_version corresponda al algoritmo soportado antes de corregir. Si una versión histórica no está disponible, bloquea la operación con un error explícito; no interpreta entradas antiguas con otra fórmula. Ambos identificadores de versión son inmutables también mediante trigger.

Los triggers existentes impiden alterar parámetros o bandas de una versión sellada. Cambiar nombre, descripción, orden o disponibilidad no modifica la interpretación matemática. Una versión nueva no actualiza silenciosamente resultados previos ni se convierte automáticamente en la versión de una corrección.

## Ejemplo de control

CAP=154 cm, factor=0.5, altura total=12 m y altura comercial=0.8 m:

| Resultado | Persistencia de 12 decimales |
| --- | --- |
| DAP | 0.490197224723 m |
| Radio | 0.245098612362 m |
| Área basal | 0.188725931518 m² |
| Volumen comercial | 0.075490372607 m³ |
| Volumen total | 1.132355589110 m³ |
| Clase diamétrica / de altura | 10 / 2 |
| Madurez | MAT_OVERMATURE |

Los resultados coinciden con la fila de control documentada, dentro de una tolerancia absoluta de 10⁻¹² para los decimales de esa fila. Las pruebas usan referencias independientes de Decimal con 100 dígitos y área=CAP²/(40000×PI), además de tolerancias explícitas cuando se compara una aproximación decimal.

## Autoridad del servidor y pruebas

TreeInspectionInput reutiliza DendrometryInput. TreeInspectionService valida, autoriza el tenant, calcula y confirma medidas, resultados, relaciones y auditoría en la misma transacción. DendrometryCalculationService no persiste por sí solo ni sustituye la autorización del servicio coordinador.

DAP, área, volúmenes, clases, madurez o versiones enviados por el cliente se descartan. La prueba de manipulación usa CAP=154 junto con derivados=999 y verifica los valores recalculados. El navegador puede solicitar una previsualización; el guardado vuelve a calcular.

Las pruebas unitarias nuevas cubren fórmulas, nulos, escala, validación, multifuste, límites, madurez, datos manipulados y versiones. En conjunto con las 30 pruebas de DecimalClassification existentes pasaron 116 pruebas y 245 aserciones sin acceder a PostgreSQL.

DendrometryCalculationTest pasó sus cuatro pruebas y 21 aserciones contra PostgreSQL: catálogo real, versiones selladas/desactivadas, reglas nuevas y capacidad NUMERIC/BIGINT. Pint verificó los diez archivos de cálculo y pruebas sin incidencias. El resultado de la suite global se consigna en [phase-7.md](phase-7.md). Las funciones de inspección y su historial se describen en [inspections.md](inspections.md).

## Fenología vinculada a inspección

CE y CF siguen siendo dos variables lógicas compuestas, independientes de las 42 condiciones fitosanitarias. Su representación F7 consiste en flowering_date, flowering_development, fruiting_date y fruiting_development dentro de TreeInspection.

Las fechas son DATE opcionales y las estimaciones de desarrollo son texto libre opcional, preservado por PhenologyService. No hay tabla de estados inventados, porcentaje obligatorio, condición booleana ni inferencia de ausencia por campos vacíos. La fecha y el texto pueden conocerse por separado. No se agrega una tabla de episodios porque la fuente no exige multiplicidad dentro de una inspección; las inspecciones sucesivas preservan su historia.

Las observaciones generales, fauna y fenología no modifican automáticamente Tree.current_status. Fotos CN/CO y sus evidencias permanecen para Fase 8.

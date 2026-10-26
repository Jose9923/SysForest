# Gestión del riesgo: prioridad fitosanitaria institucional

## Alcance y decisión profesional

La ampliación del 25/10/2026 incorpora **Prioridad de Gestión Fitosanitaria** en las vistas privadas. Es una prioridad ordinal de seguimiento y revisión, no una probabilidad de caída, muerte o daño a terceros. No genera órdenes de intervención ni cambia `Tree.current_status`.

Cada organización conserva sus propias metodologías. La plantilla inicial procede de la especificación y del bloque AU:CL del Excel suministrados por el usuario; se aplica a todas las instituciones, sin limitarse a INEM. Una copia inicial común no constituye una configuración global: editar o publicar en una institución no modifica otra.

El formulario de inspección consulta una sugerencia del servidor y muestra severidad, extensión, determinantes, prevalencias y advertencias. El profesional selecciona y confirma un nivel antes de guardar. Puede elegir un nivel superior o inferior al sugerido; si difiere, debe argumentarlo en `general_observations`. Una corrección posterior exige nueva confirmación y conserva la evaluación anterior.

La identidad del árbol y la visita siguen siendo operaciones separadas: la evaluación ocurre cuando se registran las condiciones de la inspección. El alta de la identidad, por sí sola, no acredita una evaluación sanitaria.

## Condiciones registradas y cobertura de la metodología

Desde el ajuste del 26/10/2026, no seleccionar condiciones ya no produce una sugerencia automática **Bajo**. El resultado conserva `status=valid` si no hay contradicciones, pero devuelve prioridad, severidad y extensión automáticas nulas, junto con una advertencia de captura insuficiente. La ausencia de registros no equivale a un árbol sano. Una condición «Sano» seleccionada explícitamente sí participa según su peso y las reglas de la metodología.

La cobertura se informa por separado de la consistencia:

| Campo / valor | Significado |
| --- | --- |
| `coverage_status=missing` | Ninguna condición registrada participa en la metodología; no se emite sugerencia. |
| `coverage_status=partial` | Algunas condiciones registradas participan y otras están excluidas; se calcula con las participantes y se advierte la limitación. |
| `coverage_status=complete` | Todas las condiciones registradas participan. **No acredita que se hayan inspeccionado todos los órganos ni que la visita sea exhaustiva.** |
| `evaluated_condition_count` | Número de condiciones distintas registradas que participan; un peso cero también cuenta como condición evaluada por la metodología. |
| `excluded_codes` / `excluded_conditions` | Condiciones registradas que no puntúan; se conservan código, nombre y órgano cuando están disponibles. |

El formulario y la ficha muestran las exclusiones; la explicación exportada incluye «Condición registrada que no puntúa». Las nuevas evaluaciones congelan sus nombres y órganos desde el catálogo nacional. Si un resultado antiguo no conservó la etiqueta de una condición excluida, se muestra su código como referencia, sin inventar el nombre ni alterar el snapshot.

La web sigue exigiendo decisión y confirmación profesional. Si no existe sugerencia, el profesional puede asignar una prioridad **justificándola en observaciones generales**; el servidor rechaza una confirmación sin esa justificación. Esto no convierte la ausencia de condiciones en un diagnóstico automático. Los orígenes histórico, importación y móvil continúan sin fabricar confirmaciones.

Las evaluaciones anteriores son inmutables. La presentación reconoce las que se calcularon sin condiciones participantes, devuelve la sugerencia actual como nula y conserva el antiguo resultado automático en `historical_suggestion` como antecedente explícito. La prioridad profesional confirmada permanece intacta. No se reclasifican árboles, no se modifican sus condiciones y no se vuelven a aplicar reglas actuales sobre snapshots anteriores.

## Parametrización por entidad

`/risk` utiliza el tenant seleccionado. `risk.manage` corresponde a SUPER_ADMIN dentro de la institución seleccionada y ORGANIZATION_ADMIN de su propia institución. OFFICIAL y DEPARTMENT_ADMIN pueden consultar con `risk.view`, sin publicar parámetros. El acceso departamental sigue siendo de lectura.

El módulo permite incorporar o excluir condiciones del catálogo fitosanitario existente, configurar pesos 0–4, componente anatómico, prioridad mínima, matriz 5×4, contradicciones y advertencias. Las reglas se editan mediante controles de formulario, sin código libre. Un peso 0 es informativo; excluir una condición requiere retirar también las reglas que la referencian. Un mínimo puede elevar el resultado incluso con peso 0; ambos controles tienen funciones diferentes.

Antes de publicar se exige una matriz completa de severidades 0–4 y extensiones E0–E3 con prioridades enteras 1–4. La prioridad puede mantenerse o aumentar al subir cualquiera de los dos ejes, pero nunca disminuir. Se validan todas las celdas, incluidas las de respaldo, y los errores indican en español el paso incompatible. El orden de llegada de las claves no cambia su significado. La restricción se aplica a nuevas publicaciones; no invalida ni reescribe metodologías o evaluaciones históricas.

Para añadir un nuevo hallazgo, primero debe existir en los catálogos nacionales y en los controles de captura. Su administración sigue reservada a SUPER_ADMIN. Cada institución decide después si incorpora esa condición a su metodología. No se crean columnas booleanas adicionales en la inspección ni copias institucionales de los catálogos nacionales. La parametrización no admite CAP, DAP, alturas, inclinación, condiciones físico-mecánicas ni fenología como entradas puntuables.

Publicar crea una versión inmutable y actualiza únicamente el puntero de esa organización. El formulario usa control de concurrencia; una parametrización obsoleta se rechaza. Las versiones anteriores siguen consultables. Un cambio no recalcula silenciosamente visitas ni sustituye decisiones profesionales; una nueva inspección o una corrección confirmada utiliza la metodología vigente y conserva su versión aplicada.

## Metodología inicial

- Las 42 condiciones fitosanitarias reutilizan los códigos de `conditions`, no sus etiquetas ni posiciones en la plantilla V2. AU:CL contiene además CE/CF, que siguen como fenología estructurada y no puntúan.
- La severidad es el **máximo**, nunca la suma de pesos.
- La extensión considera raíz, fuste, ramas, follaje y flores/frutos. El estado general no constituye un sexto componente.
- E0: ningún componente afectado; E1: uno; E2: dos o tres; E3: cuatro o cinco. Solo cuentan condiciones con peso positivo.
- La matriz y los mínimos producen Bajo, Moderado, Alto o Crítico. CH/CI fuerzan Crítico; CJ/CL establecen mínimo Alto en la versión inicial.
- Las contradicciones definidas bloquean nuevas altas, correcciones e importaciones ordinarias. No se desmarcan selecciones automáticamente. Las advertencias permiten confirmar, conservando el aviso.

Las celdas de la matriz inicialmente inalcanzables también conservan un valor de respaldo: una institución puede configurar otras condiciones generales o mínimos en una nueva versión. Eso no altera los casos alcanzables de la especificación inicial.

La plantilla oficial sigue siendo `SYSFOREST_IMPORT_V2_ES`, con 110 campos; conserva compatibilidad V1 y sus reglas 0/1. El Excel original con marcas x/X es la referencia metodológica, no una nueva estructura de carga aceptada por el lector oficial.

## Persistencia, histórico y recuperación

La migración aditiva `000028` crea:

| Tabla | Responsabilidad |
| --- | --- |
| `risk_methodologies` | Versión institucional y configuración completa, con etiquetas congeladas. |
| `risk_settings` | Versión vigente y control de concurrencia por organización. |
| `risk_condition_rules` | Pesos, mínimos y componentes vinculados mediante FK al catálogo existente. |
| `risk_evaluations` | Resultado explicable, metodología, versión de inspección, decisión, responsable, fecha y observaciones. |

Las FK compuestas impiden vincular metodología e inspección de instituciones distintas. Los triggers impiden modificar o borrar metodologías, reglas y evaluaciones. La decisión profesional se guarda aparte de la sugerencia, nunca reemplazándola. El resultado conserva entradas fitosanitarias, condiciones participantes, determinantes, extensión, prevalencias, advertencias y errores.

`RiskMethodologySeeder` crea únicamente las copias iniciales que faltan. `RiskHistoricalEvaluationSeeder` agrega evaluaciones a visitas existentes que aún no las tienen. Ambos forman parte de `DatabaseSeeder`; repetirlos no cambia parámetros publicados ni decisiones posteriores.

El histórico válido queda **Pendiente de confirmación**, con aviso de **Captura insuficiente** cuando no había condiciones participantes; el contradictorio queda **Inconsistente** y sin prioridad definitiva. Ninguno se confirma en nombre del autor original. La proyección no actualiza las inspecciones, sus fechas, versiones, condiciones, importaciones ni historiales anteriores.

Para recuperar evaluaciones faltantes:

```bash
docker compose exec -T app php artisan sysforest:risk-backfill
docker compose exec -T app php artisan sysforest:risk-backfill --organization=UUID
```

La recuperación de la carga original de Restrepo conserva una excepción estricta: `HistoricalRiskImportScope` se activa solo desde el servicio local de Ornato, después de comprobar los archivos y huellas originales, para la institución y campaña autorizadas. Permite recuperar datos históricos contradictorios y guardarlos como inconsistentes, sin confirmarlos. El alcance se restaura con `finally`; no existe una bandera HTTP ni una excepción para nuevas importaciones de Restrepo. No se modifican huellas, particiones ni recibos de los 29 lotes.

## Mapas, filtros, reportes y privacidad

Árboles, fichas, inspecciones, cartografía, reportes y tablero utilizan la prioridad profesional de la **última inspección determinista**. Una visita posterior pendiente no hereda la decisión de una visita anterior. Los filtros distinguen los cuatro niveles, Pendiente de confirmación, Inconsistente y Sin evaluar. Las inspecciones históricas conservan filtros sobre cada visita.

En el mapa privado el relleno indica estado del árbol y el borde indica prioridad; las agrupaciones siguen contando individuos y el calor sigue representando densidad, no riesgo. La ficha emergente muestra la prioridad confirmada o su situación pendiente. La lectura requiere el permiso de inspecciones en el alcance correspondiente.

El reporte privado de prioridades también alimenta el tablero. Las exportaciones privadas de inventario agregan siete campos de decisión, sugerencia, metodología, explicación, responsable, fecha y observaciones; CSV, XLSX, PDF y GeoJSON reutilizan sus proyecciones existentes. Al agregar instituciones se advierte que sus metodologías pueden ser diferentes.

Los reportes, filtros y proyecciones públicos excluyen el riesgo, incluso si el árbol o la inspección están publicados. Las notificaciones se limitan al formulario; no se crean envíos de correo, alertas a terceros ni intervenciones automáticas. Los cambios se incorporan a la auditoría privada sin ampliar sus roles autorizados.

## Operación local

Conservar una copia recuperable antes de migrar. La aplicación usa el rol de ejecución; las migraciones se ejecutan mediante `init` y su credencial migradora. Para aplicar exclusivamente esta ampliación, sin ejecutar migraciones anteriores pendientes:

```bash
docker compose run --rm --no-deps init php artisan migrate --force --no-interaction --path=database/migrations/2026_10_25_000028_create_institutional_risk_management.php
docker compose exec -T app php artisan db:seed --class=RiskMethodologySeeder --force
docker compose exec -T app php artisan db:seed --class=RiskHistoricalEvaluationSeeder --force
```

Las pruebas temporales solicitadas expresamente por el usuario se ejecutan en bases independientes del piloto y sus archivos se retiran después de aprobarlas. No se incorpora una suite permanente ni se agregan comandos de pruebas al proyecto. El piloto sigue local.

## Verificación ejecutada el 25/10/2026

- Los 20 casos de la especificación y la independencia entre configuraciones aprobaron en PHP 8.4.
- Aprobaron 54 verificaciones temporales de integración: aislamiento institucional, permisos, versiones, concurrencia, decisión profesional, justificación, bloqueos y reversión transaccional, histórico, filtros, mapas, reportes, exportaciones, gráficas, exclusión pública y renderizado de vistas. Las comprobaciones de decisión utilizaron una reducción de Crítico a Bajo y una corrección posterior a Alto; el contrato admite también incrementos.
- La recuperación completa de Restrepo se ejecutó en una base temporal: 2.854 árboles e inspecciones en 29 lotes, 2.854 evaluaciones sin confirmar y 867 inconsistentes. Su repetición omitió los 2.854 registros, sin nuevas altas ni cambios de estado. La excepción histórica no se extendió a capturas ni importaciones ordinarias.
- Aprobaron sintaxis PHP y PSR-12 en los 52 archivos PHP modificados, sintaxis de los cinco archivos JavaScript, compilación Blade y la comprobación temporal de marcadores: borde de prioridad, estado independiente, título accesible y ausencia de información de riesgo en puntos públicos. La imagen local de Node necesitó habilitar la sintaxis de asignación lógica que ya utilizaba `map/state.js`; no se instaló Node en el host.
- En el piloto se aplicó únicamente `000028`, después de conservar una copia privada recuperable de PostgreSQL. `000026` y `000027`, que ya estaban pendientes, no se ejecutaron como parte de esta ampliación. No se lanzó `DatabaseSeeder` completo ni se resincronizaron permisos ajenos a riesgo.
- Se inicializaron dos metodologías institucionales y 2.854 evaluaciones históricas: cero confirmadas, 867 inconsistentes y 1.987 válidas pendientes de confirmación profesional. Repetir `sysforest:risk-backfill` agregó cero evaluaciones.
- Coinciden los conteos y las huellas completas anteriores/posteriores de `trees` (2.854), `tree_inspections` (2.854), `inspection_conditions` (29.227), `inspection_revisions` (2.854), `import_batches` (29), `import_rows` (2.854) y `tree_status_histories` (4.816). La ampliación no modificó sus filas.
- `/health/ready` devolvió `{"status":"ok"}`; `/risk` sin autenticación redirigió con HTTP 302. Los scripts de prueba y las dos bases temporales se eliminaron después de aprobar las verificaciones; la copia de recuperación del piloto se conserva fuera del repositorio.

Límites: el renderizado y los flujos de negocio se comprobaron con roles reales sobre datos aislados, sin una sesión gráfica de navegador. No se acredita aquí revisión en Excel de escritorio ni entrega de los nuevos eventos a MongoDB. No se publicó el servicio en Internet.

En un arranque posterior solicitado el mismo día, se completó la configuración de MongoDB, se reconstruyó la imagen y el inicializador normal aplicó las migraciones de auditoría que estaban pendientes. La [verificación de arranque de auditoría](audit.md#arranque-del-piloto-del-25102026) registró 16.639 entradas coincidentes y cero pendientes. Esto no amplía las pruebas funcionales del módulo de riesgo descritas arriba.

## Presentación sin referencias de columna

Por solicitud posterior del 25/10/2026, los usuarios ven el órgano y el nombre de la condición, sin letras ni direcciones del Excel original. La parametrización, las sugerencias, las contradicciones nuevas y las evaluaciones históricas utilizan ese formato. Las explicaciones exportadas son texto legible y las descripciones de catálogos y detalles visibles de auditoría omiten las referencias de procedencia al libro. El cálculo conserva sus entradas, pesos y resultados.

La carga de Ornato había conservado un bloque JSON de celdas originales dentro de las observaciones. Ese bloque se oculta al presentar fichas, formularios, importaciones y reportes; las advertencias de revisión siguen visibles. La observación libre del encabezado OBSERVACIONES del libro autorizado se presenta como «Observaciones de origen», sin inferir equivalencias con otras variables. Al guardar una corrección se conserva el bloque interno original; guardar otros metadatos de un catálogo tampoco elimina su procedencia solo porque se haya ocultado visualmente. Las versiones, recibos y snapshots SQL/Mongo no se reescriben.

Se verificaron mediante una transacción de solo lectura las 2.854 evaluaciones existentes, el renderizado de parametrización y fichas históricas, las filas de un lote de importación y la proyección privada de reportes. Se comprobaron las 75 descripciones iniciales de categorías/condiciones, la conservación de observaciones originales al editar y de las 110 claves de importación. La comparación de severidad, extensión y prioridad permaneció correcta; no se escribió en las tablas operativas ni se aplicaron migraciones o seeders para este ajuste. El alcance de esta revisión es de presentación y no amplía las verificaciones funcionales anteriores.

## Verificación aislada del ajuste del 26/10/2026

- Aprobaron 35 comprobaciones temporales en PHP 8.4 sin acceso a base de datos: captura vacía frente a sano explícito, códigos repetidos, condiciones excluidas, cobertura parcial, contradicciones, presentación de históricos sin alterar el snapshot ni la decisión, matrices monótonas y no monótonas por ambos ejes, claves incompletas y desordenadas, justificación web y ausencia de confirmación automática histórica/importada/móvil. Dos de estas comprobaciones verificaron la proyección privada de exportación, preservando la decisión profesional y el antecedente histórico. La validación de decisión usó repositorios simulados; no acredita por sí sola una prueba transaccional SQL.
- Aprobaron nueve comprobaciones Blade: compilación y sintaxis de las cuatro vistas modificadas y renderizado aislado de captura insuficiente, sano explícito, antecedente histórico con decisión conservada, historial de revisiones y exclusiones con escape de HTML.
- Aprobaron cuatro comprobaciones JavaScript con DOM simulado: mensaje de insuficiencia, confirmación profesional explícita habilitada, detalle de exclusiones y bloqueo de contradicciones. También aprobó la sintaxis del archivo.
- Aprobaron la sintaxis PHP y PSR-12 en los seis archivos PHP modificados o añadidos. Los scripts se ejecutaron por entrada estándar; los archivos temporales de compilación se retiraron en `finally`. No se creó una suite permanente, no se aplicaron migraciones ni se escribieron datos operativos para estas verificaciones.

Límites de este ajuste: la cobertura describe las condiciones registradas frente a la metodología, no observaciones negativas por cada órgano. No se añadió captura por órgano ni se infirió información ausente. No se acredita una sesión gráfica real de navegador ni una prueba de integración SQL por estas comprobaciones aisladas.

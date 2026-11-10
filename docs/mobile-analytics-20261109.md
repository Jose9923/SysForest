# Contexto móvil para gráficas sin conexión · 9 de noviembre de 2026

La ampliación incorpora metadatos de lectura a los GET privados existentes para que Android pueda agrupar y filtrar la copia descargada con las mismas referencias y criterios del tablero web. No añade un módulo de Reportes a Android, endpoints públicos, permisos, reglas de cálculo, migraciones ni seeders. La implementación local está verificada; despliegue y comprobación HTTPS de esta ampliación permanecen pendientes al redactar.

## Contrato del manifiesto y catálogos

`GET /api/mobile/v1/parameterizations` añade `data.supports_analytics_context: true`. Para una cuenta con consulta de árboles e inspecciones en su institución agrega a `data.catalogs` y `data.analytics_catalogs`:

- `analytics_condition_categories`.
- `analytics_physical_conditions`.
- `analytics_phytosanitary_conditions`.

Cada nombre se consulta mediante el GET de catálogo existente. Su proyección conserva `id`, `code`, `name`, `description`, `active`, `sort_order`, `updated_at` y `assessment_type`; las condiciones incluyen `condition_category_id`. Incluye referencias inactivas, también las que pertenecen a un órgano inactivo. La respuesta declara `meta.active_only: false` y `meta.analytics_only: true`.

Los nombres originales de captura mantienen sus opciones activas y `meta.active_only: true`. `data.active_only` del manifiesto conserva ese valor por compatibilidad; `data.analytics_catalogs` identifica expresamente las excepciones analíticas. Los clientes deben conservar estos catálogos bajo sus nombres propios y usarlos solo para análisis. Recibir una referencia histórica no autoriza seleccionarla en una escritura nueva.

El listado dinámico del manifiesto permite que clientes anteriores descarguen estos nombres sin modificar sus formularios. La petición directa de un catálogo analítico reautoriza institución, consulta de catálogos, árboles e inspecciones; omitirlo en el menú no sustituye la guarda del servidor. No se añade un parámetro general para pedir inactivos de cualquier catálogo.

## Árboles: referencias históricas

Los GET `/inventories/{inventory}/trees` y `/trees/{tree}` añaden `analytics_reference`:

| Campo | Contenido |
| --- | --- |
| `species` | `id`, `scientific_name`, `family_id`, `active`, `common_names`. |
| `species.common_names[]` | `id`, `code`, `name`, `is_primary`, `active`, incluidos nombres inactivos. |
| `family` | `id`, `scientific_name`, `active`. |
| `placement`, `growth_habit`, `establishment_origin`, `biogeographic_origin` | `id`, `code`, `name`, `active`. |

Una relación ausente es `null`. Son las referencias del árbol consultado; no se descarga un catálogo completo adicional de especies inactivas. Los UUID originales del árbol permanecen en los campos superiores y son los que determinan los filtros. El cliente debe unir nombres comunes por ID para conservar alias históricos aun cuando la especie también esté en el catálogo activo.

## Visitas: contexto analítico versionado

Los GET `/inventories/{inventory}/inspections`, `/trees/{tree}/inspections` y `/inspections/{inspection}` agregan `analytics`. Conservan las mediciones, clases numéricas, `classification_rule_version_id`, `calculation_rule_version`, `risk` y `carbon` originales.

| Campo dentro de `analytics` | Significado |
| --- | --- |
| `schema_version` | Entero `1`. |
| `inspection_id`, `inspection_version` | Identidad y versión de la visita que aporta el contexto. |
| `tree_id`, `tree_lock_version` | Árbol y versión consultados. |
| `tree_species_id`, `tree_growth_habit_id`, `tree_current_status` | Insumos actuales contra los que el cliente valida la compatibilidad. |
| `classification` | `null` o `{id, code, label}` de la regla histórica. `EXCEL_V1` se presenta como «Clasificación forestal · versión 1». |
| `maturity_state` | Referencia `{id, code, name, active}` o `null`. |
| `conditions[]` | Referencia de condición, `assessment_type`, `condition_category_id` y su `category`, incluidas referencias inactivas. |
| `carbon` | Estado y cantidades aptas para el stock vivo, con las guardas siguientes. |

`analytics.carbon` contiene `status`, `label`, `compatible`, `included_in_live_stock`, `inputs_species_id`, `inputs_growth_habit_id`, `reference_status`, `biomass_kg`, `carbon_kg` y `co2_kg`.

La relación de carbono existente ya exige la misma visita/versión, especie y hábito actuales. La proyección vuelve a contrastarlos con el contexto cargado y reutiliza el resultado almacenado; no ejecuta el motor ni cambia perfiles. Solo los estados `active`, `under_observation` y `transplanted`, con resultado `estimated` compatible, tienen `included_in_live_stock: true`. Los demás estados del árbol producen `excluded_status`; sin estimación compatible, un árbol vivo produce `unprocessed`. Otros estados de estimación conservan su significado existente.

Las tres masas son cadenas decimales de seis posiciones únicamente cuando el resultado se incluye; en otro caso son `null`, nunca ceros inventados. `reference_status` conserva la distinción bibliográfica provisional/profesional cuando existe una referencia compatible. El objeto superior `carbon` continúa describiendo el resultado histórico de esa visita, incluso si el estado actual del árbol lo excluye del stock vivo. No se sustituyen el histórico, su fecha ni su referencia por este contexto de agregación.

El objeto superior `risk` mantiene `risk.view` y su vínculo con la misma versión de visita. No se añade otra decisión dentro de `analytics`. Una visita local posterior o una corrección pendiente no puede heredar la prioridad confirmada de una visita anterior; debe mostrarse como pendiente o no disponible hasta obtener su propio resultado autorizado.

## Semántica de lectura y consistencia

Las relaciones se cargan por lotes, sin consultas de catálogo por cada fila. Los GET de árboles e inspecciones usan el repositorio existente de instantáneas `REPEATABLE READ READ ONLY` para mantener coherencia dentro de cada petición. No existe una instantánea global compartida entre todas las páginas, inventarios y peticiones de una sincronización; el teléfono conserva fecha, completitud e identidades/versiones y no debe mezclar contextos incompatibles.

La última visita se determina por `inspection_date DESC`, `created_at DESC`, `id DESC`, antes de aplicar filtros de inspección. La pertenencia a inventario selecciona árboles; el historial móvil de cada uno incluye todas sus campañas autorizadas de la misma institución. Para la serie temporal, el inventario y las fechas se aplican después a cada visita histórica. Las clases se agrupan por regla y número; no confundir el número de clase con la versión ni usar `calculation_rule_version` como nombre de la clasificación.

Una categoría sin condiciones delimita la serie de condiciones, no la población. Las condiciones específicas usan ANY/ALL global sobre la misma última visita y ambas evaluaciones. Un cero en una serie seleccionada no acredita ausencia evaluada. Los catálogos analíticos completos permiten conservar esos ceros y las referencias históricas.

En Android las ausencias comprobadas se guardan en `meta.analytics_absences_v1`, separadas de historia, operaciones y recibos. Tras terminar todas las páginas se marcan árboles por inventario y visitas por árbol; una descarga parcial o cancelada no acredita ausencias. Solo Gráficas consume esas marcas. Un GET vigente puede retirarlas; un recibo histórico no prueba presencia actual y no las retira. Nuevas altas no pertenecen a sets de ausencias anteriores. Las fichas, los pendientes y los archivos conservan su flujo existente.

La comparación de inventarios cuenta cada pertenencia; un árbol compartido puede aparecer en varias barras sin duplicarse en el total de individuos. La copia móvil pertenece a una sola cuenta/plataforma/institución y no reproduce alcances nacional o departamental que no hayan sido descargados y autorizados. Los permisos de riesgo y mantenimiento siguen separados. La lectura analítica local elimina resultados de riesgo también de snapshots anidados si la capacidad fue revocada y elige la copia de mantenimiento más reciente cuando confluyen una clave alias y su identificador definitivo.

## Compatibilidad y comprobaciones

No cambiaron los métodos de creación/corrección ni sus proyecciones de recibos. Las proyecciones superiores `risk` y `carbon` conservan su implementación. Se compararon exactamente siete métodos históricos/de mutación contra su baseline; no se regeneraron UUID ni se reescribieron antecedentes.

La verificación local del backend registró 30 comprobaciones manuales aprobadas: modelos Eloquent ficticios en memoria y GET de servicios sobre un árbol/una visita existentes dentro de una transacción de solo lectura. Las 236 consultas observadas no incluyeron SQL mutante. Otras 17 comprobaciones estáticas aprobaron sintaxis de ocho archivos, PSR-12, diff y comparación de los siete métodos preservados.

El helper de ausencias se observó mediante Dart real y un ejecutor en memoria, sin runner de pruebas; nueve observaciones preservaron las filas, pendientes y payloads. El lector tuvo una revisión independiente con observaciones de alias, ámbito, ausencias y retirada de riesgo. Esto no valida SQLite nativo, carreras de sincronización completas ni un teléfono físico. No se añadieron suites permanentes.

El verificador HTTPS está preparado fuera del repositorio y aún no ejecutado: exige imagen activada/verificada y hashes de las ocho fuentes, usa credenciales existentes en memoria, limita los POST a autenticación/cierre y compara huellas de 17 tablas de negocio/recibos antes y después. Registra muestras y omisiones sin crear árboles, visitas, resultados o fixtures. El cierre definitivo debe incorporar el resultado real de despliegue/HTTPS; esta preparación no los acredita.

# Modelo de datos de SYSFOREST

## Alcance del diseño

Este es el modelo conceptual objetivo para las fases posteriores a la infraestructura. **La Fase 2 no implementa las tablas de dominio de este documento.** Las migraciones efectivamente disponibles deben consultarse en `database/migrations`; la presencia de una entidad en este diseño no implica que ya exista en la base.

Fase 5 concreta los catálogos nacionales, la taxonomía, las condiciones y las reglas de clasificación descritas más abajo. Tree, TreeInspection, TreePhenology, evidencias y mantenimientos operativos siguen pendientes de sus fases: sus relaciones en este documento son diseño, no tablas o flujos ya disponibles.

Se utiliza PostgreSQL con PostGIS, una única base y un esquema compartido. Los modelos principales disponen de UUID interno; el código visible de un árbol es un identificador funcional diferente. Los catálogos forestales nacionales se comparten, no contienen `organization_id` y solo se administran con el permiso nacional `catalogs.manage`. Los registros operativos pertenecen a una organización.

## ERD conceptual principal

El diagrama expresa cardinalidades y responsabilidades. Los nombres físicos y detalles de columnas se concretarán con las migraciones de la fase correspondiente, conservando estas relaciones.

```mermaid
erDiagram
    COUNTRY ||--o{ DEPARTMENT : contiene
    DEPARTMENT ||--o{ MUNICIPALITY : contiene
    MUNICIPALITY ||--o{ ORGANIZATION : ubica
    USER ||--o{ ORGANIZATION_MEMBERSHIP : participa
    ORGANIZATION ||--o{ ORGANIZATION_MEMBERSHIP : autoriza
    ORGANIZATION ||--o{ INVENTORY : organiza
    ORGANIZATION ||--o{ TREE : posee
    INVENTORY ||--o{ INVENTORY_TREE : incluye
    TREE ||--o{ INVENTORY_TREE : participa
    FAMILY ||--o{ SPECIES : agrupa
    SPECIES ||--o{ SPECIES_COMMON_NAME : denomina
    SPECIES ||--o{ TREE : identifica
    CLASSIFICATION_RULE_VERSION ||--o{ MATURITY_RULE_BAND : define
    MATURITY_STATE ||--o{ MATURITY_RULE_BAND : clasifica
    TREE ||--o{ TREE_INSPECTION : conserva
    INVENTORY o|--o{ TREE_INSPECTION : contextualiza
    TREE_INSPECTION ||--o{ INSPECTION_CONDITION : registra
    CONDITION_CATEGORY ||--o{ CONDITION : clasifica
    CONDITION ||--o{ INSPECTION_CONDITION : describe
    TREE_INSPECTION ||--o{ INSPECTION_FAUNA : observa
    FAUNA_CATEGORY ||--o{ INSPECTION_FAUNA : clasifica
    TREE ||--o{ TREE_STATUS_HISTORY : transita
    TREE ||--o{ MAINTENANCE : recibe
    MAINTENANCE_TYPE ||--o{ MAINTENANCE : clasifica
    MAINTENANCE ||--o{ MAINTENANCE_STATUS_HISTORY : transita
    EVIDENCE_TYPE ||--o{ EVIDENCE : clasifica
    ORGANIZATION ||--o{ IMPORT_BATCH : recibe
    IMPORT_BATCH ||--o{ IMPORT_ROW : contiene
```

`inventory_trees` representa la participación de un individuo en campañas sin cambiar su identidad ni su organización propietaria. Una inspección siempre corresponde a un árbol y puede indicar una campaña de la misma organización. Las evidencias se vinculan a su árbol, inspección o mantenimiento con integridad explícita; su vínculo concreto se documentará al implementar la fase de evidencias.

## Entidades por responsabilidad

| Grupo | Entidades del diseño | Relación o regla principal |
| --- | --- | --- |
| Territorio | countries, departments, municipalities | Jerarquía país → departamento → municipio; geometría de límites cuando exista información oficial. |
| Tenant | organizations, organization_memberships | Municipio obligatorio; membresía única por usuario y organización. |
| Acceso | users, roles, permissions, role_permissions, asignaciones de rol con alcance | Roles globales, departamentales u organizacionales; el alcance autorizado no se deriva de un ID arbitrario del cliente. |
| Autenticación | jwt_refresh_tokens y tablas necesarias para recuperación/verificación | Revocación, expiración, rotación y detección de reutilización de refresh token. |
| Campañas | inventories, inventory_tree | Una organización puede tener varias campañas; un individuo puede participar en varias. |
| Identidad forestal | trees, species, families, species_common_names | Tree futuro; taxonomía nacional con varios nombres comunes por especie y como máximo uno principal. Código humano del árbol único por organización. |
| Catálogos comunes | establishment_origins, biogeographic_origins, growth_habits, placements, maturity_states | Semántica nacional administrada por SUPER_ADMIN, sin organization_id. |
| Reglas de clasificación | classification_rule_versions, maturity_rule_bands | EXCEL_V1 con pasos de 5 cm/10 m y cuatro tramos de madurez; parámetros y bandas sellados e inmutables. |
| Seguimiento | tree_inspections, condition_categories, conditions, inspection_conditions | Mediciones fechadas y condiciones múltiples por categoría/órgano. |
| Fauna | fauna_categories, inspection_fauna | Categoría estructurada y observaciones adicionales. |
| Estados | tree_statuses, tree_status_histories | Estado operativo actual y trazabilidad de transiciones. |
| Evidencias | evidence_types, evidences | Exactamente un tipo por fotografía; metadatos y clasificación de publicación. |
| Intervenciones | maintenance_types, maintenances, maintenance_status_histories | Orden manual, responsable de la organización, programación, resultado y seguimiento. |
| Integridad | deletion_reasons, audit_logs | Anulación recuperable de errores y registro de modificaciones. |
| Intercambio | import_batches, import_rows | Validación, errores por fila, trazabilidad y resultado del lote. |
| Comunicación | notifications | Notificaciones privadas según destinatario y alcance autorizado. |

Las asignaciones de rol deben impedir combinaciones inválidas de alcance: SUPER_ADMIN es global, DEPARTMENT_ADMIN tiene departamento y los roles organizacionales requieren una membresía activa en la organización correspondiente. No basta con una etiqueta de rol sin alcance.

## Tree frente a TreeInspection

| Tree: identidad permanente | TreeInspection: observación temporal |
| --- | --- |
| Organización propietaria y código | Árbol y campaña opcional |
| Geometría y datos de localización | Fecha de inspección y autor |
| Taxonomía, especie, nombre común/científico y familia | CAP, DAP, radio y área basal |
| Hábito, orígenes de establecimiento y biogeográfico, emplazamiento | Fustes/estípites, factor de forma y alturas |
| Estado operativo actual | Volúmenes, copa X/Y y clases |
| Clasificación patrimonial/protegida | Madurez, condiciones físico-mecánicas y fitosanitarias |
| Autor original y versión de concurrencia | Fauna, observaciones y evidencias de la inspección |

Los nombres taxonómicos y la familia se relacionan mediante el catálogo de especies, evitando valores contradictorios entre referencias. Fase 5 incorpora `species_common_names`, con varios nombres por especie y como máximo uno principal; los ejemplos respaldados no acreditan una revisión botánica externa. La política de correcciones taxonómicas sobre árboles y de conservación de su histórico se documentará antes de implementar esa capacidad. Una modificación de ubicación u otro dato permanente queda auditada.

`establishment_origins` conserva Espontánea y Plantada; `biogeographic_origins` conserva Especie nativa, Especie exótica, Especie naturalizada y Especie invasora. La separación de catálogos queda definida en Fase 5. La captura futura no inferirá una dimensión a partir de la otra: H7 del Excel permite una sola elección y no establece sus combinaciones. Introducido del HTML no se siembra ni recibe una equivalencia automática.

El autor original no cambia cuando otro funcionario modifica un registro. Una nueva inspección crea historia; no reemplaza las mediciones anteriores. Los reportes actuales deben utilizar una selección determinista de la inspección pertinente; los históricos consideran explícitamente fecha y campaña.

## Condiciones y variables del Excel

La plantilla fuente contiene 94 campos lógicos en A:CP; su dimensión técnica llega a CV por formato adicional. Incluye **23 condiciones físico-mecánicas y 42 fitosanitarias**. El bloque fitosanitario AU:CL contiene 44 variables porque incorpora además **dos campos compuestos de fenología, CE/CF**. Así se conservan las 94 columnas sin reducir fecha/estado de floración y fructificación a dos casillas.

La categoría identifica el ámbito físico-mecánico o fitosanitario mediante `assessment_type` y el órgano evaluado. Fase 5 incorpora diez categorías y 65 condiciones nacionales; la FK compuesta conserva la coincidencia entre categoría y evaluación. Cada condición mantiene identidad estable dentro de su categoría. La futura `inspection_conditions` relacionará la inspección con las condiciones observadas. No se generarán decenas de columnas booleanas en `tree_inspections`, ni se fusionarán condiciones de diferentes órganos por compartir etiqueta.

Las observaciones con datos adicionales, como fecha/estado de floración o fructificación, conservarán una representación estructurada en la futura TreePhenology; no se reducen a un checkbox. CE7/CF7 están vacías y el Excel no aporta estados discretos de desarrollo, por lo que Fase 5 no inventa ese catálogo.

Los cálculos confirmados son:

- `DAP_m = CAP_cm / (100 × π)`.
- `radio_m = DAP_m / 2`.
- `área_basal_m2 = π × radio_m²`.
- `volumen_comercial_m3 = área_basal_m2 × altura_comercial_m × factor_forma`.
- `volumen_total_m3 = área_basal_m2 × altura_total_m × factor_forma`.
- Clase diamétrica para DAP positivo: `ceil(DAP_cm / 5)`, equivalente a `ceil(DAP_m × 100 / 5)`.
- Clase de altura para altura positiva: `ceil(altura_total_m / 10)`.

Fase 5 centraliza la clasificación en `ClassificationService`; la captura y persistencia de medidas y volúmenes corresponden a las futuras inspecciones. El backend recalculará y validará esos resultados antes de persistirlos. El número de fustes no introduce por sí mismo un multiplicador de volumen: no existe una regla confirmada que lo autorice. La precisión completa de las medidas y las reglas de importación se definirán en sus fases operativas.

La contradicción de clasificación con el HTML queda resuelta en Fase 5 a favor del Excel. Las clases tienen límite inferior exclusivo y superior incluido: DAP de 5 cm pertenece a clase 1 y de 5,000001 cm a clase 2; altura de 10 m pertenece a clase 1 y de 10,000001 m a clase 2. No se fija un máximo arbitrario de clases ni se siembran las bandas manuales del HTML.

La madurez adopta Fustal joven (clases 1–3), Fustal medio (4–6), Fustal maduro (7–9) y Fustal sobremaduro (≥10). W7 es texto manual en el Excel. `maturity_states` y `maturity_rule_bands` conservan esos cuatro estados y tramos, sin equivalencias inventadas con Juvenil, Adulto o Senescente. Su aplicación automática a inspecciones sigue fuera de Fase 5.

`classification_rule_versions` conserva `EXCEL_V1` con parámetros y bandas sellados: los triggers impiden reescribirlos. Cambiar la fórmula requiere una nueva versión; name, description, active y sort_order pueden editarse sin cambiar el cálculo. Los parámetros usan `DECIMAL(18,6)` y el cálculo usa BCMath con cadenas decimales: hasta doce dígitos enteros y seis decimales, rechazando cero, negativos, notación científica y exceso de precisión.

Las futuras inspecciones guardarán el ID de versión junto a sus resultados. Una versión desactivada deja de ofrecerse en nuevas capturas, pero puede consultarse explícitamente por ID para reproducir historia. No se recalculan registros históricos al publicar otra regla. La matriz de códigos y discrepancias se documenta en [catálogos forestales](forestry-catalogs.md); las 94 variables y defectos de validación están en [la matriz del Excel](excel-field-mapping.md).

## Integridad territorial y organizacional

Las tablas operativas deben incluir `organization_id` cuando sea necesario para asegurar alcance y referencias entre tenants. La base debe impedir que una inspección, campaña, mantenimiento o evidencia de una organización referencie recursos de otra.

Constraints previstos:

- Claves foráneas reales y políticas de borrado que conserven el historial institucional.
- `organizations.municipality_id` obligatorio.
- Código de árbol único dentro de su organización; el UUID permanece independiente.
- Índice único de membresía por `(organization_id, user_id)`.
- Referencias compuestas `(organization_id, resource_id)` en relaciones operativas que deban garantizar coincidencia de tenant.
- Unicidad de `(inspection_id, condition_id)` y de los códigos nacionales de catálogo.
- Índices por organización, fecha, especie, estado y relaciones usadas en filtros.
- Campos y constraints para concurrencia optimista en entidades críticas.

Los índices y constraints concretos se diseñarán con sus consultas y migraciones. El aislamiento se verifica además con pruebas IDOR: conocer el UUID de un árbol ajeno no autoriza su consulta ni modificación.

## PostGIS

Un mecanismo controlado debe garantizar `CREATE EXTENSION IF NOT EXISTS postgis`. El individuo utiliza `geometry(Point, 4326)` como representación espacial; X es longitud y Y es latitud. Los límites territoriales y futuras capas urbanas admitirán geometrías adecuadas a su naturaleza.

Se crean índices espaciales GiST. Las distancias métricas se resuelven con `geography` o una proyección apropiada; nunca se interpreta un grado de longitud/latitud como un metro. Los repositorios centralizan SQL espacial con parámetros vinculados para bbox, inclusión en polígono, proximidad, radio y agregaciones.

Cuando existan se conservan `coordinate_raw`, `coordinate_format`, `datum`, `accuracy_m`, `gps_brand`, `gps_model` y `captured_at`. Una cadena de coordenadas no reemplaza la geometría.

## Historial, evidencias e importación

Los estados operativos previstos son `active`, `under_observation`, `dead`, `felled`, `transplanted`, `removed` y `replaced`, con etiquetas en español. Patrimonial/protegido es una clasificación adicional. La anulación de errores conserva `deleted_at`, `deleted_by`, motivo y notas; no se expone eliminación definitiva en la interfaz habitual.

Cada evidencia registra al menos tipo obligatorio, disk, path, MIME, tamaño, nombre original, autor, fecha y metadatos pertinentes. Las fotografías privadas no se sirven mediante rutas públicas indiscriminadas. El dominio utiliza Laravel Filesystem para permitir almacenamiento local persistente y migración posterior a S3 compatible.

Los lotes de importación registran organización, autor, archivo, estado y resultado; sus filas preservan errores de validación y trazabilidad. La unidad transaccional y la política de aceptación del lote deben quedar explícitas antes de importar datos. Ningún dato inválido se importa silenciosamente.

La auditoría registra actor, organización, acción, recurso, fecha, valores anteriores/nuevos y contexto técnico apropiado, excluyendo contraseñas y tokens. Se autoriza como información privada y no integra Resources ni exportaciones públicas.

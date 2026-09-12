# Tree e Inventory en Fase 6

## Alcance y fuentes

La Fase 6 implementa la identidad permanente del individuo, inventarios, ubicación actual e histórica, estado, colaboración institucional, anulación y restauración. La petición aprobada tiene SHA-256 `9a27d539dbc7847509489ec8bbbdd4c30624f17f25c065f371e5f268d87c6b16`.

El Excel conserva sus 94 variables en la [matriz de trazabilidad](excel-field-mapping.md). En esta fase se materializan A:H mediante Tree y sus relaciones nacionales. TreeInspection, medidas, cálculos dasométricos, condiciones, fenología, fauna, fotos, mantenimiento e importación siguen pendientes. El catálogo existente de una condición o una regla no equivale a una inspección implementada.

Las decisiones de catálogos de Fase 5 se mantienen: [catálogos y reglas](forestry-catalogs.md). La pertenencia a una organización se resuelve con el modelo vigente de [multitenancy](multitenancy.md), sin trasladar catálogos nacionales a cada tenant.

## Identidad y campos permanentes

| Campo de Tree | Tipo y nulabilidad en persistencia | Semántica |
| --- | --- | --- |
| id | UUID, requerido | Identidad técnica permanente. |
| organization_id | UUID, requerido | Dueño institucional; FK a organizations. |
| code | varchar(100), requerido | Código humano automático y único por organización, incluidos registros anulados. |
| external_number | varchar(100), nullable | Número o identificador legado; conserva N. ÁRBOL del Excel sin sustituir el código automático. No es PK ni tiene unicidad inferida de la fuente. |
| species_id | UUID, nullable | Especie del catálogo nacional. |
| placement_id | UUID, nullable | Emplazamiento nacional. |
| growth_habit_id | UUID, nullable | Hábito nacional. |
| establishment_origin_id | UUID, nullable | Espontánea o Plantada. |
| biogeographic_origin_id | UUID, nullable | Especie nativa, exótica, naturalizada o invasora. |
| location_description | text, nullable | Descripción operativa adicional de ubicación, solicitada en Fase 6; no reemplaza C ni la observación CP del Excel. |
| geometry y metadatos de captura | Nullable | [Contrato de georreferenciación](georeferencing.md). No se exige una coordenada inventada para crear el registro. |
| current_status | varchar(30), requerido; default active | Estado del individuo real. |
| is_protected / is_heritage | boolean, requeridos; default false | Indicadores independientes del estado; no se atribuye una norma jurídica ni una calificación técnica automática. |
| created_by / updated_by | BIGINT, requeridos | Cuenta creadora y última editora; FK a users. created_by permanece inmutable. |
| lock_version | integer, requerido; default 1 | Versión de concurrencia; mínimo 1. |
| created_at / updated_at | timestamp con zona | Timestamps mantenidos por Eloquent. |
| deleted_at / deleted_by | timestamp con zona / BIGINT, nullable | Momento y autor de anulación administrativa. |
| deletion_reason_id / deletion_notes | UUID / text, nullable | Motivo nacional y nota de anulación. Si hay deleted_at, la base exige autor y motivo. |
| restored_by / restored_at | BIGINT / timestamp con zona, nullable | Última restauración; restored_at exige autor. |

La única fila de ejemplo del Excel no establece obligatoriedad de especie, emplazamiento, hábito, origen ni ubicación. Sus referencias se admiten como NULL. Los requisitos operativos sí exigen organización, actor, código, versión y estado válido.

No existe Tree.family_id ni un nombre científico duplicado en Tree. Species resuelve Family y SpeciesCommonName. Una especie puede tener varios nombres comunes y como máximo uno principal; no se fabrica un nombre principal si no existe. Las etiquetas taxonómicas sembradas son ejemplos respaldados por las fuentes, no una certificación botánica externa.

Las dos dimensiones de origen son independientes. Si solo se conoce Plantada, no se deduce el origen biogeográfico; si solo se conoce Especie nativa, no se deduce cómo se estableció. Introducido permanece sin equivalencia sembrada. No se fusionan los literales de distintas fuentes.

Una referencia vigente puede seleccionarse. Una referencia inactiva que ya pertenece al árbol sigue resolviéndose y puede conservarse; elegir una especie distinta requiere que estén activas tanto la especie como su familia. Los catálogos no llevan organization_id.

## Inventory y participación del individuo

Inventory identifica una campaña o inventario de una organización. Sus campos son UUID, organization_id, code, name, description, starts_at, ends_at, status, active, created_by, updated_by, lock_version y timestamps. Código y nombre son requeridos; descripción y fechas son opcionales. El código es único dentro de la organización e inmutable una vez creado. Esta estabilidad conserva la identidad funcional de la campaña y hace idempotente el seed del piloto, aunque cambien su nombre o disponibilidad. Cuando existen ambas fechas, ends_at no puede preceder a starts_at.

Los estados admitidos son draft, active, closed y archived. El indicador active expresa disponibilidad del registro; para incorporar árboles deben cumplirse simultáneamente active=true y status=active. Cerrar o archivar el inventario conserva su información y sus participaciones.

Tree no tiene inventory_id. inventory_trees registra la relación muchos a muchos mediante UUID, organization_id, inventory_id, tree_id, included_at, included_by, notes y timestamps:

- Un árbol puede participar en varios inventarios de su organización sin duplicar su identidad.
- La combinación inventory_id/tree_id es única. Una inclusión repetida conserva al autor, la fecha y la nota iniciales.
- Las FK compuestas (organization_id, inventory_id) y (organization_id, tree_id) impiden cruzar organizaciones incluso al escribir SQL directamente.
- La creación normal de Tree requiere al menos un inventario activo. Las inclusiones posteriores añaden participaciones; no sustituyen ni eliminan campañas previas.
- Un inventario cerrado no permite nuevas inclusiones. Official puede editar el árbol si permanece asociado al menos a un inventario activo. OrganizationAdmin conserva gestión de los árboles de su organización mediante su permiso administrativo.

PilotInventorySeeder crea de forma idempotente el inventario `PILOT-SYSFOREST`, nombre `Inventario Forestal Institucional - Piloto SysForest`, en INEM-VVC. El seed conserva ajustes existentes y no inventa fechas ni un funcionario: sus autores son nullable para este registro institucional. No crea árboles de demostración.

## Código humano y concurrencia

TreeCodeService obtiene el siguiente valor mediante OrganizationSequenceRepository. La fila de organization_sequences se identifica por organization_id y name=tree; un UPSERT con RETURNING incrementa el contador bajo bloqueo dentro de la misma transacción que crea Tree.

El código combina el prefijo de la organización y un ancho mínimo de seis dígitos: `COL-MET-VVC-INEM-000001`. No se usa MAX+1. El ancho puede crecer después de seis dígitos. Las organizaciones tienen secuencias separadas.

Un código confirmado permanece reservado tras la anulación; la restricción UNIQUE incluye las filas con deleted_at. Un fallo de creación revierte también la reserva que nunca se confirmó. Restaurar recupera el mismo UUID, code y created_by. Cambiar el prefijo institucional afecta las creaciones posteriores y no reescribe códigos existentes.

La escritura bloquea primero al actor vigente, después el contexto institucional y finalmente al recurso. lock_version enviado debe coincidir con la versión bloqueada; un formulario obsoleto recibe 409 y no sobrescribe cambios ajenos. TreeWriteService incrementa una sola vez por operación; los servicios auxiliares de estado y ubicación no incrementan por su cuenta.

## Estado, ubicación y anulación

| Estado del individuo | Valor |
| --- | --- |
| Activo | active |
| En observación | under_observation |
| Muerto | dead |
| Talado | felled |
| Trasplantado | transplanted |
| Retirado | removed |
| Reemplazado | replaced |

TreeStatusService conserva estado anterior, nuevo estado, motivo, actor y fecha en tree_status_histories. La primera fila usa from_status=NULL; una restricción parcial permite una sola captura inicial. Guardar el mismo estado no añade una transición. Los estados pertenecen a un enum cerrado; no se infieren causas, autorizaciones ambientales ni una secuencia legal de transiciones.

tree_location_histories conserva las geometrías, textos originales y metadatos de cada captura o cambio efectivo. Véase [historial de ubicación](georeferencing.md#historial-y-atomicidad). Un trasplante como estado y un cambio de coordenada son hechos explícitos: cambiar uno no inventa automáticamente el otro.

SoftDeletes se reserva para un registro administrativo erróneo. Marcar dead, felled o removed no asigna deleted_at. Anular exige un motivo disponible de deletion_reasons y registra actor, fecha y nota opcional; el árbol desaparece de las consultas operativas normales.

Restaurar conserva identidad, taxonomía, estado, ubicación e inclusiones. Se retira deleted_at, se registran restored_by/restored_at y se limpian los campos de anulación vigentes. Los eventos tree.voided y tree.restored conservan su trazabilidad de seguridad; los historiales de estado y ubicación no se eliminan. No existe operación de borrado físico de Tree y un trigger la rechaza también desde SQL.

## Permisos y límites institucionales

Se añaden ocho permisos forestales al catálogo RBAC existente. Los permisos nacionales son concesiones explícitas del seeder; no hay acceso implícito basado en un nombre de rol.

| Permiso | SUPER_ADMIN | DEPARTMENT_ADMIN | ORGANIZATION_ADMIN | OFFICIAL |
| --- | --- | --- | --- | --- |
| inventories.view | Organización seleccionada | Organización del departamento asignado | Organización de la membresía | Organización de la membresía |
| inventories.create | Sí | No | Sí | No |
| inventories.update | Sí | No | Sí | No |
| trees.view | Organización seleccionada | Organización del departamento asignado | Organización de la membresía | Organización de la membresía |
| trees.create | Sí | No | Sí | Sí |
| trees.update | Sí | No | Sí | Sí, con participación en inventario activo |
| trees.void | Sí | No | Sí | No |
| trees.restore | Sí | No | Sí | No |

Cada celda de escritura se limita al contexto institucional seleccionado. SUPER_ADMIN no necesita una membresía ficticia, pero sus escrituras usan TenantContext nacional y un organization_id concreto. department_read permite consulta y rechaza todas las mutaciones forestales. Un UUID de otra organización no se resuelve en el repositorio del tenant actual; no se trata como una oportunidad para cambiar de contexto.

El dato pertenece a la organización, por lo que Official A puede editar un árbol creado por Official B cuando ambos mantienen acceso vigente y el árbol satisface la condición de inventario activo. created_by no cambia; updated_by refleja al último editor.

ForestryAccessService revalida cuenta, verificación, contexto y concesiones vigentes. El middleware de autenticación valida el JWT y la sesión antes de los endpoints. Las Policies comparan el recurso con el contexto; los Services vuelven a comprobar permisos dentro del flujo de escritura. Los Repositories aplican organization_id explícito, sin un global scope que oculte el criterio.

## Integridad y contratos

La migración de Fase 6 incorpora inventories, organization_sequences, trees, inventory_trees, tree_status_histories y tree_location_histories. Tree e Inventory tienen UNIQUE(organization_id,id) para las FK compuestas de sus relaciones. Las FK territoriales, de catálogos y actores usan RESTRICT. La identidad de Tree, su organización, código y autor original quedan protegidos por trigger. Los historiales solo admiten INSERT.

Las reglas principales se distribuyen así:

| Componente | Responsabilidad |
| --- | --- |
| TreeService | Crear, editar e incorporar a inventarios; transacción completa. |
| TreeWriteService | Revalidar actor/contexto, bloquear, autorizar, comparar e incrementar versión. |
| TreeRelationService | Validar referencias y campañas activas sin borrar participaciones. |
| TreeCodeService / OrganizationSequenceRepository | Reservar el consecutivo institucional atómico. |
| TreeStatusService | Estado e historial; evento tree.status_changed. |
| TreeLocationService / TreeLocationRepository | GPS, PostGIS, historial y evento tree.location_changed. |
| TreeLifecycleService | Cambiar estado/ubicación, anular y restaurar mediante el mismo control de escritura. |
| TreeReadService / TreeRepository | Preparar listados, ficha, referencias e historiales por tenant. |
| InventoryService / InventoryRepository | Inventarios, versiones e inclusiones idempotentes. |

Los Controllers coordinan Requests y Services. Blade recibe las relaciones y permisos preparados; no ejecuta consultas. El navegador permite previsualizar una ubicación, pero guardar siempre vuelve a validarla en el servidor.

Los resultados de pruebas, migraciones, rollback y revisión de interfaz de la fase se registran en [phase-6.md](phase-6.md). El detalle técnico espacial y sus pruebas propias están en [georeferencing.md](georeferencing.md).

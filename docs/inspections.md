# Inspecciones forestales históricas

## Alcance y separación del dominio

Fase 7 implementa visitas fechadas, mediciones, resultados, condiciones, fenología, fauna y observaciones. Tree conserva identidad, taxonomía, ubicación y estado permanente. Ningún dato de inspección se copia a trees. La fuente forestal es la matriz de 94 variables aprobada; las fotografías CN/CO continúan pendientes de Fase 8. No se incorporan mantenimientos, mapas generales, importación ni reportes finales.

Una inspección tiene UUID, organization_id, tree_id, inventory_tree_id nullable, inspection_date, created_by, updated_by, lock_version y timestamps con microsegundos. Las variables numéricas y sus fórmulas se describen en [dendrometry.md](dendrometry.md). Las clasificaciones guardan resultados e ID de la versión sellada; el código DENDROMETRY_V1 identifica las fórmulas. Las correcciones utilizan la versión original, incluso si se desactiva. El navegador nunca decide esos valores.

## Contexto opcional de campaña

Una visita puede registrarse sin campaña formal. Cuando tiene contexto, inventory_tree_id identifica una participación existente de ese mismo árbol en una campaña de su propia organización. Se admiten participaciones en campañas cerradas o desactivadas para transcribir visitas históricas; esto no abre la campaña ni incorpora nuevos individuos. No se infiere que las fechas de la visita deban estar dentro de fechas administrativas del inventario. No se exige una campaña activa para que Official registre o corrija una inspección.

La creación inicial de Tree conserva el requisito de participación de Fase 6. Su ficha ofrece inmediatamente «Nueva inspección». Son dos operaciones separadas: guardar identidad y ubicación, luego registrar la visita. TreeService y TreeInspectionService no dependen circularmente ni ofrecen una transacción combinada ficticia.

## Integridad institucional

`UNIQUE(organization_id,id)` en tree_inspections permite las FK de sus hijos. La FK `(organization_id,tree_id)` apunta a Tree; la FK `(organization_id,tree_id,inventory_tree_id)` apunta al triple único de inventory_trees. Así no basta con que la campaña sea de la misma organización: la participación debe corresponder también al individuo.

Los pivotes inspection_conditions e inspection_fauna y las revisiones llevan organization_id y FK compuesta a la inspección. Sus claves impiden duplicar una condición o categoría de fauna. Los catálogos permanecen nacionales y no llevan organization_id. Los registros usan referencias reales, nunca etiquetas visibles para resolver identidad.

La identidad, organización, Tree, autor original, fecha de creación y versiones de reglas son inmutables también por trigger. La fecha observada y el contexto de campaña sí se pueden corregir con auditoría. No hay endpoint de borrado ni anulación de inspecciones y la base prohíbe hard delete. Queda pendiente definir formalmente un futuro flujo de anulación de una visita completa.

## Condiciones y referencias históricas

Se utilizan las 23 condiciones físicas y 42 fitosanitarias de Fase 5. La evaluación y órgano distinguen identidades que comparten nombre. No existen columnas booleanas por condición ni exclusiones automáticas entre Sano y otras selecciones. Una casilla sin marcar no significa ausencia evaluada.

Cada selección conserva inspection_id, condition_id, organization_id y created_at. Las selecciones retenidas conservan su fecha original. El servicio valida el tipo de evaluación, existencia y disponibilidad de condición y categoría. La misma condición no puede repetirse. Una referencia histórica desactivada continúa visible y puede conservarse al corregir la visita, pero no se admite como selección nueva. Al retirarla y volver a elegirla se aplica la disponibilidad actual.

## Fenología: CE y CF

El instrumento distingue floración y fructificación; cada variable contiene fecha y desarrollo. Se representan con cuatro campos opcionales en tree_inspections: flowering_date, flowering_development, fruiting_date y fruiting_development. Una fila de inspección tiene hasta una observación compuesta por proceso. La fuente no justifica una tabla adicional de episodios múltiples.

Las fechas se validan como fechas de calendario; cada una puede estar presente sin la otra y sin un texto obligatorio. Los desarrollos son texto libre que conserva espacios y saltos de línea; no se inventan estados, escalas, porcentajes ni equivalencias de valores discretos. Un valor desconocido puede registrarse literalmente sin asignarle significado. El middleware no recorta los textos del instrumento. La multiplicidad de episodios y los estados discretos quedan como decisiones forestales pendientes.

## Fauna y observaciones

inspection_fauna relaciona múltiples categorías: Aves, Mamíferos, Reptiles, Insectos, Quirópteros y Otros. fauna_observations conserva una descripción global de la fauna de la visita, incluso sin clasificarla; no se inventa una especie ni se asigna automáticamente cada frase a una categoría. Las referencias inactivas siguen la misma conservación histórica.

general_observations conserva CP del instrumento. No sustituye location_description del Tree ni notas administrativas o de mantenimiento. La interfaz escapa todo texto y mantiene saltos de línea. No existen rutas falsas de fotos ni controles de evidencia inactivos que aparenten una función implementada.

## Permisos, autorización y transacciones

| Permiso | SUPER_ADMIN | DEPARTMENT_ADMIN | ORGANIZATION_ADMIN | OFFICIAL |
| --- | --- | --- | --- | --- |
| inspections.view | Tenant seleccionado | Tenant de su departamento | Su organización | Su organización |
| inspections.create | Sí, tenant seleccionado | No | Sí | Sí |
| inspections.update | Sí, tenant seleccionado | No | Sí | Sí |

Los tres permisos son explícitos en el catálogo RBAC; hay 31 permisos totales. SuperAdmin no necesita membresías artificiales. Los Services revalidan actor, sesión/contexto y concesiones vigentes. Los repositorios resuelven UUID por tenant antes de autorizar o validar contenido; conocer un UUID ajeno no permite leerlo ni modificarlo. Official puede corregir a otro funcionario de la misma institución. created_by permanece intacto; updated_by cambia.

Tree anulado no admite visitas nuevas ni correcciones. La lectura histórica sigue TreePolicy: necesita autorización para ver el Tree, incluyendo permiso de restauración si está anulado. La bandeja ordinaria excluye esos árboles.

El orden de bloqueo es actor → organización/contexto → Tree → TreeInspection → participación. La versión se valida antes de convertirla a entero; una versión obsoleta produce HTTP 409 con «Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.» No existe sobrescritura silenciosa ni incremento duplicado.

Una única transacción incluye campos, cálculo, condiciones, fauna y revisión. Un fallo tardío de relaciones o auditoría revierte todos los cambios. Cambiar una condición no modifica Tree.current_status: las transiciones continúan siendo operaciones explícitas separadas.

## Auditoría

inspection_revisions conserva una fila por versión, actor, momento y evento inspection.created/inspection.updated. old_values y new_values son JSONB estructurados por context, measurements, calculations, conditions, phenology, fauna y observations. La creación tiene objeto anterior vacío. Las listas de IDs se ordenan para facilitar comparación; las revisiones son append-only mediante trigger y no se modifican ni eliminan.

security_events conserva además el evento, actor, organización y UUID del recurso, sin duplicar todo el formulario ni registrar cookies o tokens. La revisión es parte de la misma transacción de negocio. La ficha muestra las versiones y responsables; el detalle estructurado queda disponible en el repositorio para trazabilidad posterior.

## Historia, última visita y filtros

TreeInspectionRepository ordena por inspection_date DESC, created_at DESC, id DESC. created_at es inmutable y conserva microsegundos; UUID solo resuelve el empate final. updated_at no participa, por lo que corregir una visita antigua no la convierte en una visita nueva. Todas permanecen en la línea de tiempo.

latestForTrees usa DISTINCT ON y carga relaciones por lotes. latestTrees selecciona primero la última inspección global de cada Tree y después filtra sus valores. No busca «la última visita que coincide» ni agrega condiciones de años diferentes. Eager loading prepara condiciones, categorías, fauna, taxonomía, autores y versiones antes de Blade.

La consulta histórica admite Tree, campaña propia de la inspección, intervalo de fecha, especie actual del Tree, madurez, clase diamétrica, clase de altura, condiciones por tipo y rangos de CAP/DAP/altura total. Los rangos pueden tener solo un extremo. El catálogo histórico desactivado sigue siendo válido para filtros.

Las condiciones usan ANY o ALL; ALL cuenta condiciones distintas del mismo inspection_id y ANY usa EXISTS, sin duplicar árboles mediante joins. latestTrees mantiene inventory_id como filtro de participación del Tree (semántica Fase 6), mientras la bandeja de inspecciones filtra la campaña que contextualiza esa visita. Los Repositories contienen una sola implementación de filtros de inspección reutilizable por los futuros servicios de exportación/reportes.

## Interfaz y endpoints

Blade organiza captura en contexto, dasonomía, condiciones físicas, fitosanitarias, fenología, fauna y observaciones. Checkboxes por órgano, Select2 en relaciones, navegación por secciones, tarjetas responsive y outputs «Calculado automáticamente». JavaScript envía las medidas al servidor y muestra su respuesta; no tiene fórmulas ni decide clases. El guardado recalcula siempre, aunque el cliente envíe DAP/área/volumen falsos. Las respuestas antiguas de previsualización se descartan.

Rutas privadas: GET /inspections; GET/POST /trees/{tree}/inspections[/create]; GET /inspections/{inspection}; GET /inspections/{inspection}/edit; PUT /inspections/{inspection}; POST /dendrometry/preview. La previsualización de una corrección autoriza su inspección y reutiliza su regla original. No hay consultas directamente en Controllers o Blade.

El formulario completo normaliza grupos de casillas omitidos como arreglos vacíos, para permitir desmarcarlas todas sin JavaScript. En llamadas directas al servicio, los campos omitidos conservan el valor anterior y NULL explícito limpia un dato opcional.

Los resultados ejecutados, inventario de archivos y deudas se registran en [phase-7.md](phase-7.md).

# Guía de uso

Esta guía describe las pantallas implementadas. Los botones disponibles dependen del rol vigente, la organización seleccionada y el estado del registro. La [matriz de autorización](authorization.md) detalla cada permiso.

## Entrar y elegir organización

Abre [el portal](http://localhost:8080/portal) y utiliza **Iniciar sesión**, o entra directamente en [login](http://localhost:8080/login). Las cuentas se reciben por invitación o, durante revisión local, mediante el [seeder explícito de desarrollo](development-accounts.md). No hay registro público abierto.

Una vez autenticado, entra en **Organizaciones** y selecciona la institución en la que trabajarás. Comprueba esa selección antes de crear o modificar información. El sistema la revalida en cada petición: una cuenta, membresía u organización desactivada deja de autorizar operaciones aunque una pestaña anterior permanezca abierta.

| Perfil | Uso principal |
| --- | --- |
| SUPER_ADMIN | Administración nacional; selecciona una organización para escribir datos forestales, importar o publicar. Mapa, reportes y auditoría admiten su alcance nacional. |
| DEPARTMENT_ADMIN | Consulta dentro de su departamento. No importa, publica ni consulta auditoría privada. |
| ORGANIZATION_ADMIN | Gestiona la institución y sus campañas, miembros, publicación y auditoría. |
| OFFICIAL | Captura árboles, inspecciones, evidencias, órdenes e importaciones de su organización; consulta reportes. No administra miembros ni publicación. |

El perfil permite gestionar datos propios, contraseña y sesiones. **Cerrar sesión** revoca la sesión autenticada.

Mientras completas un formulario institucional, el acceso se renueva automáticamente si la sesión continúa vigente. También se comprueba antes de guardar, navegar o realizar solicitudes internas; puedes tardar más que la duración del token sin tener que cerrar el formulario. La renovación conserva lo escrito y las fotografías seleccionadas.

Si la sesión termina, se revoca o cambia la cuenta o institución en otra pestaña, el formulario permanece abierto y muestra un aviso. Conserva los datos; cuando corresponda, usa **Iniciar sesión en otra pestaña** y abre un formulario nuevo en la institución correcta. Ante un fallo temporal de conexión, revisa la red y utiliza **Comprobar sesión**. El vencimiento absoluto y los permisos siguen aplicándose: mantener una página abierta no evita una revocación. La comprobación no reenvía automáticamente una escritura que ya fue enviada.

## Capturar un inventario

1. En **Inventarios**, el administrador crea o selecciona la campaña institucional y revisa su estado. El código de una campaña existente es permanente.
2. En **Árboles**, registra el individuo en una campaña que permita captura. Selecciona las referencias de catálogo y conserva como ausente cualquier dato desconocido.
3. Informa coordenadas decimales o DMS y revisa la previsualización. Declara el datum únicamente cuando se conozca; la marca del GPS no lo determina.
4. Desde la ficha del árbol, crea una **Inspección** con su fecha, medidas y observaciones. El árbol conserva la identidad y la inspección representa una visita histórica.
5. Adjunta fotografías desde **Evidencias** del árbol, inspección u orden correspondiente. Los originales son privados y se consultan mediante acceso autorizado.

La **Fecha y hora de captura** de la ubicación se selecciona con calendario en **hora de Colombia (America/Bogota)**, tanto al registrar el árbol como al cambiar su ubicación. Déjala vacía si se desconoce. La ficha muestra esa misma hora local. El servidor conserva el instante en UTC; la plantilla Excel y las integraciones que envían `captured_at` mantienen su contrato ISO con zona. Cambiar otros datos sin modificar el calendario conserva la precisión histórica de la fecha.

Los cálculos derivados se realizan en el servidor. Una medida desconocida no equivale a cero. Consulta [dasonomía](dendrometry.md) para unidades, fórmulas y precisión, e [inspecciones](inspections.md) para condiciones y fenología.

Un individuo puede participar en varias campañas sin duplicar su identidad. **Anular** se reserva para registros creados por error; muerto, talado o retirado son estados de un individuo real y conservan su historial. La restauración requiere permiso y conserva UUID, código y relaciones.

## Distinguir estado y condiciones del árbol

El estado actual y las condiciones de una inspección son datos distintos:

- **Activo**: estado operativo del individuo; no certifica que esté sano ni sustituye la revisión de sus inspecciones.
- **En observación**: requiere revisión o seguimiento por plagas, señales de enfermedad o daños estructurales registrados.
- **Muerto**: el individuo se reporta muerto. Una rama muerta aislada, la muerte descendente o un proceso de deterioro no acreditan por sí solos la muerte del árbol completo.

Los insectos benignos, como abejas, no justifican por sí solos poner un árbol En observación. Conserva en la inspección las condiciones observadas y distingue los insectos dañinos de los benignos o no identificados.

Desde la ficha abre **Cambiar estado del árbol**, selecciona el estado y explica el motivo y su fuente. Guardarlo conserva el estado anterior y registra la fecha real del cambio y la persona responsable. No reemplaza las inspecciones históricas. Registrar o corregir una inspección no cambia automáticamente el estado del árbol.

## Registrar y cerrar mantenimientos

Abre **Mantenimientos** y entra en la orden. Al comienzo de su ficha aparece **Seguimiento y cierre**, con el siguiente paso disponible abierto:

1. **Asignar responsable**: selecciona la persona que hará el trabajo. Incluso si el responsable quedó anotado al crear la orden, confirma esta transición para pasar de Pendiente a Asignado.
2. **Iniciar ejecución**: registra que la intervención comenzó.
3. **Registrar ejecución**: selecciona la fecha en el calendario y escribe el resultado. La orden pasa a Ejecutado.
4. Un administrador de la organización o superadministrador revisa el resultado y las evidencias y pulsa **Verificar y cerrar mantenimiento**. La ficha muestra **Mantenimiento cerrado y verificado**.

El funcionario puede registrar la ejecución. Después ve **Pendiente de verificación administrativa** hasta que un administrador la verifique. Los permisos no cambian y el historial registra cada transición.

**Cancelar trabajo** se utiliza cuando la intervención no se realizará y requiere un motivo. **Anular registro por error** corrige una orden creada por equivocación; no acredita su ejecución ni su cierre verificado.

No se generan intervenciones automáticas por el simple hecho de detectar una condición en una inspección. Consulta [mantenimientos](maintenances.md) para el contrato de estados y responsables.

## Importar Excel

1. Selecciona la organización y abre **Importaciones**. Descarga la plantilla oficial desde el módulo.
2. Completa únicamente la hoja **Datos**, desde la fila 2, sin modificar encabezados ni orden. Usa códigos de catálogo, fechas como texto `AAAA-MM-DD` y decimales con punto. Conserva vacías las columnas reservadas para derivados y fotografías.
3. Selecciona la campaña y carga el XLSX. El límite predeterminado es 100 filas y 5 MiB. No se admiten fórmulas, macros ni formatos arbitrarios.
4. Revisa la vista previa: filas, errores, referencias y resultados calculados. La carga todavía no crea árboles. Un lote inválido requiere corregir el archivo y cargar uno nuevo.
5. Confirma solo cuando toda la revisión sea correcta. El servidor revalida y crea árboles, participaciones e inspecciones en una transacción. Si una referencia o cálculo cambió, exige revisar la vista previa actualizada antes de confirmar nuevamente.

Cada fila crea un **árbol nuevo**. No utilices la importación para corregir o actualizar un individuo existente. `external_number` conserva un número legado; no detecta duplicados. Un código existente, incluso anulado, bloquea el lote. La protección por huella impide repetir el mismo archivo binario confirmado, pero no identifica copias regrabadas como individuos equivalentes.

La vista previa vence a las 24 horas por defecto. Puedes cancelar un lote pendiente. Una confirmación repetida de un lote completado devuelve el resultado previo, sin duplicarlo. Las fotos se adjuntan después mediante Evidencias. Consulta [importación](import-export.md) y su [diccionario](import-field-mapping.md).

## Consultar mapas y reportes

El **Mapa general** privado y las bandejas comparten filtros. Los filtros de condición actual consultan la última inspección pertinente; seleccionar todas las condiciones exige que coincidan en esa misma visita. El mapa de calor representa densidad, no riesgo forestal. Los individuos sin coordenadas pueden aparecer en los conteos y listados sin generar un marcador.

En **Reportes**, aplica los filtros y el alcance antes de descargar CSV, Excel, GeoJSON o PDF. La vista del mapa y su límite de marcadores no recortan las exportaciones. Los límites predeterminados son 50.000 filas privadas, 10.000 públicas y 200 para PDF tabular. Si se supera un límite, restringe los filtros; no se entrega un archivo silenciosamente truncado. Una ficha PDF individual contiene el perfil permitido completo. Un archivo de consulta exportado no sustituye la plantilla oficial de importación.

## Publicar información ciudadana

En **Publicación ciudadana**, el administrador autoriza explícitamente la organización, una campaña activa fuera de Borrador y los árboles. Inspecciones y fotografías tienen autorización propia. Revisa después el portal sin sesión para comprobar la proyección ciudadana.

Todo comienza privado. Autorizar un árbol no publica automáticamente sus notas internas, funcionarios, originales fotográficos ni auditoría. Despublicar un propietario retira sus dependientes en la siguiente consulta. Consulta [política pública](public-data-policy.md) para las condiciones exactas de visibilidad.

## Consultar auditoría y resolver conflictos

**Auditoría** muestra los últimos 30 días UTC por defecto. Puedes filtrar por fechas, nombre actual del actor, acción, tipo e identificador del recurso; el administrador nacional dispone además de organización. El detalle distingue valores anteriores y nuevos. Los eventos históricos sin captura de valores lo indican expresamente; no se reconstruye un estado supuesto. El historial es de solo lectura y no tiene exportación.

Si otra persona modificó un registro abierto en tu formulario, el sistema devuelve conflicto y evita sobrescribir su cambio. Conserva tus observaciones, abre la versión vigente, compara y vuelve a aplicar únicamente lo que siga siendo necesario. Reenviar una pestaña obsoleta no resuelve el conflicto. Consulta [concurrencia](concurrency.md).

| Mensaje o estado | Qué revisar |
| --- | --- |
| Debes seleccionar organización | Selecciona una institución vigente en Organizaciones. |
| Acceso denegado (`403`) | Cuenta, verificación, asignación, membresía y permiso actuales; la selección no otorga privilegios. |
| No encontrado (`404`) | El enlace puede no pertenecer al alcance actual o el recurso puede no estar disponible. |
| Conflicto (`409`) | Recarga el registro o revisión y compara antes de volver a guardar. |
| Datos no válidos (`422`) | Corrige los campos señalados; en importación revisa también la estructura y cada fila. |
| Demasiadas solicitudes (`429`) | Espera el intervalo indicado antes de repetir la operación. |
| Servicio no disponible (`503`) | Revisa [operación y diagnóstico](operations.md); la pantalla no revela SQL ni rutas internas. |

En móvil, los filtros y acciones siguen las mismas reglas del servidor. Las tablas amplias pueden desplazarse horizontalmente dentro de su contenedor; el contenido debe seguir siendo legible al ampliar texto y navegar con teclado.

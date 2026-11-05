# Guía de uso

Esta guía describe las pantallas implementadas. Los botones disponibles dependen del rol vigente, la organización seleccionada y el estado del registro. La [matriz de autorización](authorization.md) detalla cada permiso.

## Entrar y elegir organización

Abre [el portal](https://sysforest.varcor.tech/portal) y utiliza **Iniciar sesión**, o entra directamente en [login](https://sysforest.varcor.tech/login). El dominio y el acceso HTTPS fueron verificados el 4 de noviembre de 2026. Las cuentas requieren autorización administrativa; no hay registro público abierto. La instalación local conserva http://localhost:8080 solo para pruebas. En producción, las contraseñas comunes de desarrollo se retiraron y el correo transaccional sigue pendiente: solicita ayuda al administrador para habilitar o recuperar acceso.

Una vez autenticado, entra en **Organizaciones** y selecciona la institución en la que trabajarás. Comprueba esa selección antes de crear o modificar información. El sistema la revalida en cada petición: una cuenta, membresía u organización desactivada deja de autorizar operaciones aunque una pestaña anterior permanezca abierta.

| Perfil | Uso principal |
| --- | --- |
| SUPER_ADMIN | Administración nacional; selecciona una organización para escribir datos forestales, importar o publicar. Mapa, reportes y auditoría admiten su alcance nacional. |
| DEPARTMENT_ADMIN | Consulta dentro de sus departamentos y auditoría departamental. No captura, importa ni publica por este rol. |
| ORGANIZATION_ADMIN | Gestiona la institución, campañas, miembros, publicación y parámetros de riesgo y carbono. No accede a la bandeja de auditoría. |
| OFFICIAL | Captura árboles, inspecciones, evidencias, órdenes e importaciones de su organización; consulta reportes. No administra miembros ni publicación. |

El perfil permite gestionar datos propios, contraseña y sesiones. **Cerrar sesión** revoca la sesión autenticada.

Mientras completas un formulario institucional, el acceso se renueva automáticamente si la sesión continúa vigente. También se comprueba antes de guardar, navegar o realizar solicitudes internas; puedes tardar más que la duración del token sin tener que cerrar el formulario. La renovación conserva lo escrito y las fotografías seleccionadas.

Si la sesión termina, se revoca o cambia la cuenta o institución en otra pestaña, el formulario permanece abierto y muestra un aviso. Conserva los datos; cuando corresponda, usa **Iniciar sesión en otra pestaña** y abre un formulario nuevo en la institución correcta. Ante un fallo temporal de conexión, revisa la red y utiliza **Comprobar sesión**. El vencimiento absoluto y los permisos siguen aplicándose: mantener una página abierta no evita una revocación. La comprobación no reenvía automáticamente una escritura que ya fue enviada.

## Capturar un inventario

1. En **Inventarios**, el administrador crea o selecciona la campaña institucional y revisa su estado. El código de una campaña existente es permanente.
2. En **Árboles**, registra el individuo en una campaña que permita captura. Selecciona las referencias de catálogo y conserva como ausente cualquier dato desconocido.
3. Informa coordenadas decimales o DMS y revisa la previsualización. Declara el datum únicamente cuando se conozca; la marca del GPS no lo determina.
4. En el mismo formulario de registro completa la **Primera inspección**: fecha, medidas, condiciones físico-mecánicas y fitosanitarias, fauna, fenología y observaciones. Confirma la prioridad profesional y pulsa **Guardar árbol y primera inspección**. Ambos registros se guardan juntos; si falla la inspección, tampoco queda creado el árbol. Para una segunda visita o posteriores entra en **Inspecciones** y selecciona el árbol existente.
5. Adjunta fotografías desde **Evidencias** del árbol, inspección u orden correspondiente. Los originales son privados y se consultan mediante acceso autorizado.

La **Fecha y hora de captura** de la ubicación se selecciona con calendario en **hora de Colombia (America/Bogota)**, tanto al registrar el árbol como al cambiar su ubicación. Déjala vacía si se desconoce. La ficha muestra esa misma hora local. El servidor conserva el instante en UTC; la plantilla Excel y las integraciones que envían `captured_at` mantienen su contrato ISO con zona. Cambiar otros datos sin modificar el calendario conserva la precisión histórica de la fecha.

Los cálculos derivados se realizan en el servidor. Una medida desconocida no equivale a cero. Consulta [dasonomía](dendrometry.md) para unidades, fórmulas y precisión, e [inspecciones](inspections.md) para condiciones y fenología.

Un individuo puede participar en varias campañas sin duplicar su identidad. **Anular** se reserva para registros creados por error; muerto, talado o retirado son estados de un individuo real y conservan su historial. La restauración requiere permiso y conserva UUID, código y relaciones.

## Elegir órganos y condiciones en la web

En **Condiciones físico-mecánicas** y **Condiciones fitosanitarias**, elige primero uno o varios **Órganos o grupos**. Puedes buscar por nombre y mantener, por ejemplo, sistema radicular y ramas seleccionados a la vez. Cada órgano abre su propio selector de **Condiciones**, donde puedes elegir varios hallazgos. Las dos evaluaciones conservan selecciones independientes.

Este recorrido se utiliza en la primera inspección del árbol, en visitas posteriores y al corregir una visita. Al abrir una corrección aparecen los órganos de las condiciones ya registradas; las referencias históricas inactivas se identifican y pueden conservarse según las reglas de la visita. Si la validación señala un error, se recuperan las selecciones para corregirlo.

Quitar un órgano limpia únicamente sus condiciones en el formulario. Revisa ese cambio antes de guardar; los demás órganos y la otra evaluación conservan lo elegido. Una condición sin seleccionar o un órgano vacío no declaran una ausencia evaluada. Las casillas de fauna siguen en su apartado.

## Distinguir estado y condiciones del árbol

El estado actual y las condiciones de una inspección son datos distintos:

- **Sano**: estado general del árbol; consulta sus inspecciones para ver las condiciones y observaciones registradas.
- **En observación**: requiere revisión o seguimiento por plagas, señales de enfermedad o daños estructurales registrados.
- **Muerto**: el individuo se reporta muerto. Una rama muerta aislada, la muerte descendente o un proceso de deterioro no acreditan por sí solos la muerte del árbol completo.

Los insectos benignos, como abejas, no justifican por sí solos poner un árbol En observación. Conserva en la inspección las condiciones observadas y distingue los insectos dañinos de los benignos o no identificados.

Desde la ficha abre **Cambiar estado del árbol**, selecciona el estado y explica el motivo y su fuente. Guardarlo conserva el estado anterior y registra la fecha real del cambio y la persona responsable. No reemplaza las inspecciones históricas. Registrar o corregir una inspección no cambia automáticamente el estado del árbol.

## Confirmar la prioridad fitosanitaria y consultar carbono

Al crear o corregir una inspección web, registra las condiciones observadas y pulsa **Actualizar sugerencia** en Prioridad de Gestión Fitosanitaria. Lee los avisos, selecciona **Prioridad profesional**, explica en observaciones una diferencia con la sugerencia y marca la confirmación. Si no hay condiciones evaluables, no se sugiere Bajo; una decisión profesional exige justificación explícita. Corrige condiciones contradictorias antes de guardar. Si añades o retiras una condición, incluso al quitar su órgano, la confirmación anterior se desmarca: revisa la sugerencia actualizada y confirma nuevamente.

La cobertura indica cuántas condiciones capturadas participan en la metodología institucional, no que se hayan evaluado todos los órganos. Las capturas históricas, importadas o móviles no inventan una confirmación. Pendiente de confirmación, Inconsistente y Sin evaluar son distintos de una prioridad confirmada. La prioridad no cambia el estado del árbol ni genera una orden de mantenimiento.

El panel **Carbono aéreo almacenado** muestra el stock estimado de biomasa aérea, carbono y CO₂ correspondiente. Requiere datos y referencia aplicables; consulta el motivo cuando no haya estimación. No equivale a cero, a emisiones o a captura anual. Las referencias bibliográficas provisionales se identifican y requieren revisión local. No inventes especie, fustes ni medidas para obtener un número.

En **Prioridad fitosanitaria** y **Carbono almacenado**, los administradores autorizados publican parámetros propios de su organización. Las nuevas versiones conservan la historia. Para actualizar carbono en visitas existentes se solicita **Actualizar estimaciones de esta entidad** y se consulta el progreso. Revisa [riesgo](risk-management.md) y [carbono](carbon-estimation.md) para interpretación y límites.

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

1. Selecciona la organización y abre **Importaciones**. Descarga la plantilla oficial en español desde el módulo. Incluye **Guía de campos**, **Instrucciones** y hojas separadas de estados, especies, emplazamientos, hábitos, orígenes, fauna y condiciones. Las plantillas V1 anteriores siguen siendo compatibles.
2. Completa únicamente la hoja **Datos**, desde la fila 2, sin modificar encabezados ni orden. Elige opciones de las listas desplegables de catálogos (nombre y código), o escribe los códigos exactos. Protegido, Patrimonial y condiciones tienen listas 0/1. En Fauna el desplegable elige una categoría; para varias escribe los códigos separados por `|`. Usa fechas como texto `AAAA-MM-DD` y decimales con punto. Conserva vacías las columnas reservadas para derivados y fotografías.
3. Selecciona la campaña y carga el XLSX. El límite predeterminado es 100 filas y 5 MiB. No se admiten fórmulas, macros ni formatos arbitrarios.
4. Revisa la vista previa: filas, errores, referencias y resultados calculados. La carga todavía no crea árboles. Un lote inválido requiere corregir el archivo y cargar uno nuevo.
5. Confirma solo cuando toda la revisión sea correcta. El servidor revalida y crea árboles, participaciones e inspecciones en una transacción. Si una referencia o cálculo cambió, exige revisar la vista previa actualizada antes de confirmar nuevamente.

Cada fila crea un **árbol nuevo**. No utilices la importación para corregir o actualizar un individuo existente. `external_number` conserva un número legado; no detecta duplicados. Un código existente, incluso anulado, bloquea el lote. La protección por huella impide repetir el mismo archivo binario confirmado, pero no identifica copias regrabadas como individuos equivalentes.

La vista previa vence a las 24 horas por defecto. Puedes cancelar un lote pendiente. Una confirmación repetida de un lote completado devuelve el resultado previo, sin duplicarlo. Las fotos se adjuntan después mediante Evidencias. Consulta [importación](import-export.md) y su [diccionario](import-field-mapping.md).

## Consultar mapas y reportes

El **Mapa general** privado y las bandejas comparten filtros. Los filtros de condición actual consultan la última inspección pertinente; seleccionar todas las condiciones exige que coincidan en esa misma visita. El mapa de calor representa densidad, no riesgo forestal. Los individuos sin coordenadas pueden aparecer en los conteos y listados sin generar un marcador.

Para filtrar condiciones, elige varios órganos o grupos y después las condiciones concretas de cada uno, igual que en la captura. Elegir solo el órgano no añade un criterio. Puedes combinar condiciones físicas y fitosanitarias con **Cualquiera** o **Todas**; quitar un órgano limpia solo los criterios de ese órgano. Este control se comparte entre listados, mapas, análisis y reportes, con las opciones disponibles en cada alcance.

En **Reportes**, aplica los filtros y el alcance antes de descargar CSV, Excel, GeoJSON o PDF. La vista del mapa y su límite de marcadores no recortan las exportaciones. Los límites predeterminados son 50.000 filas privadas, 10.000 públicas y 200 para PDF tabular. Si se supera un límite, restringe los filtros; no se entrega un archivo silenciosamente truncado. Una ficha PDF individual contiene el perfil permitido completo. Un archivo de consulta exportado no sustituye la plantilla oficial de importación.

Los mapas y reportes privados permiten consultar prioridad, stock de carbono y cobertura con los mismos filtros. El reporte distingue estimaciones provisionales y revisadas y conserva el aviso de cobertura en las descargas. Un PNG muestra **Sin estimación** para valores ausentes. El stock vivo solo suma la última visita compatible de árboles Sanos, En observación o Trasplantados; no suma visitas históricas ni representa una precisión certificada.

## Publicar información ciudadana

En **Publicación ciudadana**, el administrador autoriza explícitamente la organización, una campaña activa fuera de Borrador y los árboles. Inspecciones y fotografías tienen autorización propia. Revisa después el portal sin sesión para comprobar la proyección ciudadana.

Publicar o despublicar una visita conserva sus evaluaciones sin representar una nueva decisión profesional. Riesgo y carbono permanecen excluidos de la proyección ciudadana.

Todo comienza privado. Autorizar un árbol no publica automáticamente sus notas internas, funcionarios, originales fotográficos ni auditoría. Despublicar un propietario retira sus dependientes en la siguiente consulta. Consulta [política pública](public-data-policy.md) para las condiciones exactas de visibilidad.

## Consultar auditoría y resolver conflictos

**Auditoría** está reservada a SUPER_ADMIN y DEPARTMENT_ADMIN dentro de sus alcances. Los filtros Desde y Hasta usan fechas nativas y calendario UTC inclusivo; también se filtra por módulo, acción, usuario y organización autorizados. El detalle distingue valores anteriores y nuevos. Los eventos históricos sin captura de valores lo indican expresamente. La descarga Excel conserva los filtros e incluye Eventos, Cambios y Criterios; el límite predeterminado es 50.000 eventos y no se truncan resultados. El historial sigue siendo de solo lectura. Consulta [auditoría](audit.md).

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

Los [manuales ilustrados](manuales.md) reúnen estos procedimientos, capturas y documentación técnica en Word y texto. Para el trabajo offline y el intercambio entre SysForest Mobile y el servidor, consulta el [contrato móvil](mobile-api.md).


## Acceso a SysForest Mobile

Abre SysForest en el teléfono con conexión a internet. La pantalla solicita Correo electrónico y Contraseña; la dirección de la plataforma viene incluida en la aplicación y no necesitas escribir un código de institución.

1. Escribe el correo de tu cuenta y su contraseña; pulsa Continuar.

1. Si tienes acceso a una sola institución, la aplicación entra automáticamente. Si tienes varias, selecciona el nombre de la institución donde vas a trabajar y pulsa Ingresar. Ninguna opción queda elegida de antemano.

1. Comprueba la cuenta y la institución antes de descargar inventarios o registrar datos. Para corregir el correo mientras se muestra la lista, utiliza Cambiar cuenta: se borra la contraseña y debes escribirla otra vez.

La lista se obtiene después de comprobar tu contraseña. Si no aparecen instituciones, solicita al administrador que revise el acceso de tu cuenta. Si las credenciales no son aceptadas, revisa el correo y la contraseña; la aplicación no permite consultar instituciones indicando únicamente un correo.

Cuando se necesita iniciar sesión de nuevo para sincronizar trabajo guardado, la cuenta y la institución permanecen fijas: escribe la contraseña de esa cuenta. Los árboles, visitas y fotografías pendientes conservan su autor y su institución. El primer ingreso no se puede realizar sin internet; la consulta offline requiere datos descargados previamente.

Si la aplicación informa que la plataforma no está configurada o que cambió respecto de tu cuenta guardada, comunica el aviso al administrador. La consulta local sigue disponible y los pendientes se conservan; la conexión se bloquea hasta disponer de una configuración compatible. No borres los datos de la aplicación para resolver ese aviso.


## Acceso a la instalación de producción

La plataforma web de producción se consulta en https://sysforest.varcor.tech. El dominio y el acceso HTTPS fueron verificados el 4 de noviembre de 2026. La dirección local 127.0.0.1 corresponde únicamente al equipo de pruebas. SysForest Mobile incorpora la dirección de la plataforma: al entrar escribes correo y contraseña, y eliges una institución por nombre cuando hay varias. El primer acceso y la descarga inicial requieren internet; después puedes consultar y capturar los datos preparados sin conexión.

### Acceso después de la migración

La migración conserva las cuentas, instituciones e inventarios existentes. En producción se retiraron las contraseñas comunes de desarrollo y se cerraron las sesiones trasladadas. Utiliza la contraseña personal habilitada por el administrador; una sesión abierta en la instalación local no te conecta automáticamente a producción.

El correo transaccional está pendiente de configuración por decisión del responsable de la instalación. Mientras tanto, las invitaciones, verificaciones y recuperaciones por correo no tienen entrega habilitada. Si necesitas recuperar acceso, solicita asistencia al administrador y no repitas el formulario esperando un mensaje que todavía no se puede enviar.

### Actualizar la app para usar producción

SysForest Mobile 0.2.1+3 incorpora https://sysforest.varcor.tech. Una vez habilitado el dominio con HTTPS válido, la conexión utiliza internet y no requiere cable USB ni que el computador local esté encendido. El APK se instala como actualización sobre la versión anterior cuando conserva la misma firma. No desinstales la app ni borres sus datos.

Antes de actualizar, revisa si tienes capturas, visitas o fotos pendientes de la plataforma local y coordina su envío y traslado con el administrador. El servidor local y producción son instalaciones independientes: los cambios posteriores a la copia no se transfieren automáticamente.

Si al abrir la versión nueva aparece el aviso de otra plataforma, los datos locales siguen conservados. Para entrar a producción, abre el menú de cuenta, pulsa Cerrar sesión y vuelve a escribir tu correo y contraseña. Si hay varias instituciones, elige la que corresponda. Cerrar sesión conserva los pendientes de la cuenta anterior, pero no los traslada ni los envía al servidor nuevo.

Los pendientes de la plataforma local solo pueden enviarse usando una configuración y un servidor compatibles con su cuenta original. Solicita asistencia para resolverlos; no vuelvas a crear esos árboles en producción como solución al aviso. La compilación del APK no acredita por sí sola una prueba en un teléfono físico o en iPhone.

## Bandeja de árboles en Gestión del riesgo

Abre **Gestión del riesgo → Árboles y riesgo** para consultar un árbol por fila con la prioridad de su última inspección. La prioridad profesional y la sugerencia automática están separadas; Sin evaluar o Captura insuficiente no significa Bajo. Puedes abrir su ficha, revisar la visita o iniciar una nueva inspección si tienes permiso.

Usa la búsqueda, inventario, prioridad, estado y fechas para elegir el conjunto. **Más filtros** incluye nombres y selección de condiciones por órgano. Los contadores corresponden a los filtros aplicados. **Reporte de riesgo por árbol** permite ver el detalle y descargar Excel, CSV o PDF según permisos; la descarga incluye todas las filas filtradas, no solo la página actual. Para PDF, limita la selección a 200 filas. **Metodología institucional** conserva la configuración y sus versiones.

## Continuidad de captura y riesgo profesional · Android 0.3.2+7

Al cerrar un selector de órganos, condiciones o catálogos, el formulario conserva su posición y no vuelve al campo de texto anterior. El botón Aplicar permanece visible sobre el teclado; la búsqueda mantiene las selecciones y Cancelar no las aplica.

En la primera inspección y las visitas posteriores, **Gestión del riesgo** permite consultar la sugerencia con internet, elegir Bajo, Moderado, Alto o Crítico y marcar la confirmación profesional. **Usar la prioridad sugerida** selecciona el nivel; la confirmación es una acción separada. Justifica en Observaciones generales si eliges otro nivel o no hay sugerencia. Cambiar condiciones físicas o fitosanitarias invalida la confirmación y exige consultar nuevamente. Una contradicción impide confirmar; la falta de condiciones no se interpreta como riesgo Bajo.

Sin conexión puedes guardar una captura con el riesgo pendiente. Si habías empezado a elegir una prioridad sin confirmarla, usa **Dejar el riesgo pendiente** para continuar. La consulta no guarda la visita ni envía la cola. Guardar sigue siendo local y **Sincronizar ahora** sigue siendo el único inicio de los envíos.

Para una visita ya descargada, abre su ficha o el listado de Inspecciones y pulsa **Revisar riesgo**. Revisa las observaciones, consulta, selecciona y confirma; se prepara una corrección de esa misma visita, conservando su historial. Ante un cambio de versión, consulta la visita vigente y revisa nuevamente la decisión. Una justificación local escrita o pendiente se conserva al actualizar; puedes elegir explícitamente usar las observaciones del servidor. Los envíos inciertos conservan su identificador y contenido.

Después de instalar la actualización, pulsa **Sincronizar ahora** para actualizar las capacidades de tu cuenta. Consulta y confirmación requieren los permisos de riesgo y de captura o corrección correspondientes. Las versiones anteriores continúan enviando sus capturas sin decisión profesional.

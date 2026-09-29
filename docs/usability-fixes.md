# Ajustes de usabilidad del piloto local

Fecha: 28 de septiembre de 2026. Alcance: las tres observaciones del usuario después de la aceptación local. Se conserva Laravel 13/PHP 8.4, Blade y el flujo Controller → Service → Repository → Model. No corresponde a una fase nueva ni a un despliegue externo.

## Continuidad de sesión en formularios

Antes, al caducar el JWT durante una captura, la siguiente navegación o envío podía sacar al usuario del formulario. La interfaz institucional ahora comprueba la sesión antes del envío y renueva las cookies sin recargar la página, mientras la sesión y el refresh sigan vigentes. El formulario conserva sus datos y las evidencias seleccionadas.

- `SessionContinuityService` verifica la familia autenticada y el contexto institucional. El estado se consulta sin rotación; la renovación exige POST y CSRF.
- Access y refresh siguen en cookies HttpOnly. El refresh permanece opaco, rotatorio y revocable; no se agrega una tolerancia que permita reutilizarlo.
- El navegador coordina la renovación entre pestañas mediante Web Locks. No recibe el contenido de los tokens y no decide permisos.
- Un contexto distinto, una sesión revocada o su vencimiento absoluto bloquea el envío y muestra un aviso conservando el formulario. La renovación no prolonga la fecha límite absoluta.
- Antes de enviar formularios se actualiza CSRF y se adjunta el contexto de la página. Las solicitudes internas y el XHR de evidencias mantienen esa comprobación.
- Un fallo de red conserva el contenido. Una escritura ya enviada nunca se reenvía automáticamente; se evita duplicar operaciones.

La continuidad requiere JavaScript y un navegador con Web Locks para coordinar una renovación. El camino anterior de renovación manual permanece disponible; no se modificó la duración del JWT del piloto principal. La instancia temporal usa un minuto únicamente para comprobar el caso observado.

## Calendario para la captura del árbol

El campo que exigía escribir fecha, hora y zona en formato ISO se reemplaza por un calendario nativo `datetime-local`. La etiqueta y la ficha indican hora de Colombia (`America/Bogota`). Se aplica al alta y a la acción **Cambiar ubicación o datos de captura**.

`TreeCaptureDateService` prepara el valor de presentación y convierte `captured_at_local` a UTC en el servidor. No depende de la zona del equipo del usuario ni de JavaScript. La entrada `captured_at` utilizada por integraciones conserva su contrato anterior; se rechaza enviar ambos formatos a la vez. Las fechas inexistentes, horas `24:00` y segundos `60` se rechazan, sin corregir silenciosamente el instante.

Omitir la fecha conserva el valor existente; vaciar el calendario declara el dato desconocido. Cuando el valor visible coincide con la fecha almacenada, se conservan sus microsegundos originales aunque el calendario represente solo milisegundos. Cambiar otros metadatos no reduce la precisión histórica. No se modifican el contrato de importación, los registros existentes ni los calendarios de evidencia fotográfica.

## Seguimiento y cierre de mantenimientos

La ficha sitúa **Seguimiento y cierre** al comienzo y abre el siguiente paso disponible. `MaintenanceReadService` prepara orientación, acciones y mensajes a partir de estados, transiciones y permisos existentes; Blade solo los presenta.

El recorrido visible es **Asignar responsable → Iniciar ejecución → Registrar ejecución → Verificar y cerrar mantenimiento**. Registrar la ejecución exige fecha y resultado. El funcionario deja la orden en **Ejecutado** y ve **Pendiente de verificación administrativa**. Un ORGANIZATION_ADMIN o SUPER_ADMIN autorizado puede verificarla; entonces aparece **Mantenimiento cerrado y verificado**.

Cancelar un trabajo que no se hará requiere motivo. Anular una orden creada por error conserva su trazabilidad y no equivale a ejecutarla o verificarla. No se añaden estados, permisos, migraciones ni cierres automáticos. El [paso a paso](user-guide.md#registrar-y-cerrar-mantenimientos) explica dónde realizar cada acción.

## Verificación

Resultados de esta revisión, separados de las cifras históricas de otras fases:

| Comprobación | Resultado |
| --- | --- |
| Regresión general `bash bin/check all` | 1837 pruebas PHP, 11989 aserciones; 79 pruebas JavaScript y 585 archivos PSR-12. Código 0, sin omisiones. |
| Verificación posterior de identidad (SessionContinuityTest e IdentityHttpTest) | 35 pruebas, 399 aserciones; código 0. Incluye conservación de errores, valores anteriores, avisos y URL de regreso durante el polling. |
| JavaScript final `bash bin/check frontend` | 80 pruebas aprobadas; sin fallos ni omisiones. Incluye el aviso visible tras un envío bloqueado y los encabezados AJAX. |
| Formatter del código modificado | 18 archivos PHP revisados, incluido el ajuste final de mensajes de validación de identidad. Los archivos ajustados después se volvieron a formatear; PSR-12 aprobado. |
| Formulario abierto más que el JWT | Navegador con JWT de un minuto: conservó texto, selección de campaña y fecha; guardó un solo árbol después de varios minutos. Siete rotaciones ya consumidas y sesión vigente al verificar la creación. |
| Calendario y UTC | Selector nativo abierto y campo local comprobado: 28/09/2026 23:30 en Colombia quedó persistido como 29/09/2026 04:30 UTC. |
| Cierre de mantenimiento | Navegador completó Asignado, En ejecución, Ejecutado y Verificado; ficha «Mantenimiento cerrado y verificado», versión 5, cinco entradas de historial. Permisos de funcionario y administrador comprobados en pruebas PHP. |
| Cierre de sesión en otra pestaña | El envío quedó bloqueado, sin abandonar la página ni borrar sus campos. El enlace para iniciar sesión por separado permaneció visible tras volver a comprobar. No creó un segundo árbol. |
| Datos del piloto | Conteos y huellas de metadatos de 15 tablas idénticos antes y después. Un árbol, una inspección, cuatro evidencias, un mantenimiento y 112 eventos de seguridad en el piloto, sin datos sintéticos añadidos. |
| Retirada del entorno temporal | Eliminados sus nueve contenedores, cuatro volúmenes propios, volumen anónimo de inicialización, red y directorio temporal de secretos. El piloto conserva sus siete servicios saludables y puertos 8080/8025. |
| Revisión de cambios | `git diff --check` sin errores. No se agregaron migraciones ni dependencias. |

La regresión general quedó en `/home/josev/SYSFOREST/storage/app/checks/20260928T232303Z-BhdrRp`. Las pruebas de identidad y JavaScript posteriores acreditan los últimos ajustes de conservación de mensajes, retorno, traducción de validaciones de longitud y enfoque del aviso; no se suman como casos distintos a los ya incluidos en la regresión. La verificación focal estricta está en `.usability-work/final-focused-check.log`; el JavaScript final está en `storage/app/checks/20260928T234445Z-XHjwUu`.

La revisión de navegador utilizó el proyecto Docker separado `sysforest-usability-20260928`, en `http://usability.localhost:8097`, con Redis, PostgreSQL, almacenamiento, correo y claves propios. Se inició sin reconstruir ni descargar imágenes: siete servicios saludables, inicializadores con salida 0, 24 migraciones y cuatro cuentas sintéticas. Ese entorno ya fue retirado; la dirección habitual sigue siendo [SYSFOREST](http://localhost:8080/portal). Los resultados y helpers temporales se conservan en `.usability-work` del área de trabajo de Windows, sin secretos en este informe.

Las páginas abiertas antes de esta actualización deben recargarse después de conservar cualquier trabajo pendiente. La renovación automática requiere JavaScript y Web Locks en un contexto seguro; no sustituye una sesión revocada ni extiende su vencimiento absoluto. La entrada directa, recarga completa o apertura mediante controles propios del navegador conserva la recuperación manual cuando el acceso ya venció. Los detalles están en [autenticación](authentication.md#continuidad-de-formularios).

No quedan correcciones pendientes de estas tres observaciones. Los pendientes anteriores de Excel de escritorio e importación por navegador siguen descritos en [aceptación local](acceptance-local.md); esta entrega no los acredita nuevamente.

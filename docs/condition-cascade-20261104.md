# Selectores múltiples de órganos y condiciones · 04/11/2026

## Estado y alcance

Implementación publicada y verificada en https://sysforest.varcor.tech el 4 de noviembre de 2026 (America/Bogota). La APK Android 0.3.1+6 también está disponible; esta evidencia distingue las comprobaciones web de la validación pendiente en teléfono físico. Las evidencias de fases anteriores conservan sus fechas y no cuentan como validación de este cambio.

La primera inspección incluida en el alta del árbol, las visitas posteriores y las correcciones comparten el mismo componente. Los filtros lo reutilizan en ocho pantallas: listado privado de árboles, bandeja privada de inspecciones, mapa privado, análisis, reportes privados, mapa público, listado público y reportes públicos. La app móvil y su liberación se documentan por separado.

## Comportamiento

- Cada evaluación tiene un selector múltiple buscable de órganos o grupos. Cada órgano elegido abre su propio selector múltiple, limitado a sus condiciones.
- Se conservan 23 condiciones físico-mecánicas en cuatro grupos y 42 fitosanitarias en seis grupos. Pueden seleccionarse varios órganos y varias condiciones de cada uno simultáneamente, sin mezclar las dos evaluaciones.
- Al abrir una corrección o recuperar errores se deducen los órganos de las condiciones seleccionadas. La captura recupera también órganos abiertos sin condiciones mediante un campo auxiliar de presentación. Las referencias históricas inactivas seleccionadas se mantienen identificadas.
- Quitar un órgano limpia solamente sus condiciones del formulario. La ayuda explica la consecuencia antes de la interacción. No borra condiciones de otros órganos ni de la otra evaluación.
- Un órgano vacío o una condición sin seleccionar no acreditan ausencia evaluada. Fauna conserva sus casillas; medidas, fenología y observaciones mantienen sus campos.
- Añadir o retirar condiciones invalida la confirmación profesional de riesgo. La previsualización recibe las selecciones reales; el servidor mantiene la validación de contradicciones y referencias.
- En filtros, elegir solo el órgano no restringe resultados. El selector auxiliar no envía parámetros. ANY/ALL conserva su semántica sobre una misma visita; privado usa UUID y público códigos estables.

## Contratos y archivos

`physical_condition_ids[]` y `phytosanitary_condition_ids[]` continúan siendo los únicos arrays de condiciones del dominio. No hay nuevas columnas, migraciones, reglas forestales, privilegios ni cambios de identidad de categorías o condiciones.

| Archivo | Responsabilidad |
| --- | --- |
| `resources/views/inspections/condition-fields.blade.php` | Cascada compartida, selecciones anteriores, referencias históricas, errores y modo de filtro. |
| `resources/views/inspections/fields.blade.php` | Uso del componente en los bloques físico-mecánico y fitosanitario. |
| `public/js/condition-cascade.js` | Visibilidad por órgano, limpieza limitada y API de restauración de filtros. |
| `public/css/condition-cascade.css` | Distribución adaptable, Select2 y presentación de filtros. |
| `public/js/inspections.js` | Conserva previsualización de medidas; retira el contador de checkboxes de condiciones. |
| `public/js/risk/inspection.js` | Lee selecciones con FormData e invalida confirmación ante cambios reales. |
| `resources/views/trees/form.blade.php`, `resources/views/risk/form.blade.php` | Ayudas coherentes con condiciones seleccionadas. |

El contenedor usa `data-condition-cascade` y `data-condition-field`; cada grupo contiene `data-condition-panel` y `data-condition-select`. `window.SysforestConditionCascade.setValues(contenedor, ids)` selecciona condiciones, deduce órganos y refresca Select2 sin emitir cambios de negocio. Los filtros reutilizan este contrato para restaurar enlaces y limpiar criterios, y `data-filter-label` identifica evaluación y órgano. Los datos se preparan fuera de Blade.

## Comprobaciones realizadas

Se utilizaron verificadores temporales fuera del repositorio, sin recrear una suite permanente. Las comprobaciones de render utilizaron contexto local autorizado y sesiones transitorias; no iniciaron una sesión web persistente ni guardaron árboles o visitas. Las referencias alteradas para verificar filtros se comprobaron dentro de una transacción revertida.

### Captura y componente

- Sintaxis aprobada con `node --check` en `condition-cascade.js`, `inspections.js` y `risk/inspection.js`; `git diff --check` aprobado en los ocho archivos de captura indicados arriba.
- Render Blade completo: dos selectores de órganos, cuatro grupos físicos con 23 opciones y seis fitosanitarios con 42; al iniciar sin datos, los diez grupos quedan ocultos y deshabilitados.
- El formulario conserva seis casillas de fauna, IDs únicos y ausencia de formularios anidados.
- Recuperación de tres condiciones en tres grupos y errores de validación; conservación de referencias históricas inactivas mediante fixture en memoria; colección vacía y recuperación de órgano vacío sin inventar condiciones.
- Modo de filtro con objetos de catálogo y códigos públicos: selector auxiliar sin nombre y etiquetas de criterio presentes.
- La comprobación HTTP local de rutas públicas detectó que el componente dependía de la bolsa de errores de sesión. Se corrigió con una bolsa local vacía por defecto, tanto para aria-invalid como para los mensajes. Un render nuevo sin sesión ni `errors` compartido pasó; un error de condición individual conservó su mensaje e identificación accesible. La verificación completa de captura anterior volvió a pasar después de la corrección.

### Filtros

- El responsable de filtros verificó 71 comprobaciones de render sobre las ocho pantallas: 65 opciones, cuatro condiciones seleccionadas en cuatro órganos, auxiliares sin nombre, modo ALL conservado e inclusión única del script compartido.
- Tras corregir la bolsa de errores, peticiones HTTP reales locales a `/portal/map`, `/portal/trees` y `/portal/reports` devolvieron 200, con 65 opciones y cuatro selecciones restauradas. Esta comprobación utiliza las rutas públicas reales, sin la bolsa de errores compartida por el render de prueba.
- La carga del catálogo se comprobó con dos consultas. Se verificaron opciones históricas inactivas y su validación para consulta dentro de una transacción revertida.
- En la muestra local autorizada de INEM, los mismos criterios devolvieron un resultado con ANY y ninguno con ALL; no se alteró su semántica. Se aprobaron comprobaciones de sintaxis de los cinco servicios modificados y del JavaScript de filtros.

### Revisión visual de captura y filtros

El responsable de integración abrió el HTML renderizado en navegador y seleccionó varios órganos y condiciones en ambas evaluaciones. Al quitar el sistema radicular físico, se conservaron **Colgantes** en Ramas y dos condiciones de raíces fitosanitarias. La captura resultó legible y los controles permitieron esas selecciones simultáneas.

Sobre las ocho vistas renderizadas, revisó en navegador el mapa privado, mapa público y reportes. En el mapa privado comprobó selección, eliminación por órgano y restablecimiento de órganos, condiciones, etiquetas, URL y modo; se conservaron las 65 opciones sin mezclar evaluaciones. En mapa público y reportes verificó la restauración de cuatro condiciones y cuatro órganos, las etiquetas y los modos ANY/ALL.

## Límites

La revisión visual utilizó una copia estática local con marcador CSRF inválido y recursos locales. La petición de riesgo respondió 501 porque ese servidor de vista previa no ejecuta el backend; ese resultado no demuestra un fallo del endpoint real ni acredita su validación en vivo. Esta ronda verifica render, interacción de selección y conexión del código con el contrato existente, sin afirmar una nueva prueba completa de guardado o de fórmulas científicas.

Las vistas previas de mapa tampoco disponían de API de datos, por lo que su aviso de carga no constituye una comprobación del servicio real. La validación HTTP real y el despliegue completados se registran a continuación. No se regeneraron los documentos Word ni las imágenes históricas de los manuales. La cascada necesita JavaScript; la pantalla muestra un aviso cuando no está disponible. Los verificadores y snapshots temporales no forman parte del producto.

La separación de identidad y visitas, el alta conjunta transaccional y su evidencia previa se mantienen en [field-capture-20261104.md](field-capture-20261104.md). Los contratos de consulta continúan en [map-filters.md](map-filters.md) y los de captura en [inspections.md](inspections.md).

## Publicación y verificación real

Se publicó un delta de 17 archivos y se reconstruyó la imagen `sha256:fae16e6047d7b5055bcc6c2fa75f2ebc7a3f77e5aa4f65c54d5f2a8cbac6e26a`. App, queue, scheduler, Nginx, PostgreSQL, MongoDB y Redis quedaron saludables. Se comprobaron los 17 hashes de fuente, configuración privada idéntica y 34 archivos previos de vendor/descargas sin cambios antes de añadir la nueva APK. No se aplicaron migraciones ni seeders; las 21 rutas móviles existentes y la tabla de recibos siguen disponibles.

La revisión HTTP por HTTPS aprobó nueve páginas: alta de árbol con 65 condiciones y seis categorías de fauna; cinco filtros privados y tres públicos con cuatro condiciones y cuatro órganos restaurados. Privado mantiene UUID y público códigos. El POST de sugerencia de riesgo devolvió 200 sin crear árboles o inspecciones; la sesión de comprobación se cerró. La API pública conserva su proyección anterior. Estas comprobaciones no sustituyen una nueva prueba completa de guardado.

El GET público real detectó una dependencia de mensajes de sesión que el render aislado no revelaba. Se corrigió con una bolsa de errores local vacía cuando no existe contexto de validación, sin añadir sesión al portal. Se repitieron el render sin sesión, el mensaje de validación individual y los GET públicos locales y productivos, todos aprobados.

Respaldo previo: `/opt/sysforest/backups/condition-cascade-20261105T014535Z`. Incluye datos online en `/opt/sysforest/backups/condition-cascade-20261105T014535Z/data/backup-20261105T014607Z-Bn13gI`, fuente e imagen anterior con hashes verificados y tag `sysforest-app:before-condition-cascade-20261105t014535z`. Es un respaldo online de sus componentes, no una instantánea coordinada de todo el sistema.

## Entrega móvil 0.3.1+6

[Descargar Android 0.3.1](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.1.apk). Misma firma, paquete `co.sysforest.mobile`, código 6, variante debug universal y 168,852,621 bytes. SHA-256: `d1f3bbee1ebca53d16344c95078edad1ab8c9ba770df9c72faa81eeef50f3480`. Se instala encima de la versión anterior sin borrar datos.

Los formularios iniciales, nuevas visitas y edición usan varios órganos y sus condiciones propias. Siete escenarios del modelo real, formato de 22 archivos, análisis sin incidencias, compilación, paquete, certificado, ABIs, hash completo en servidor y descarga HTTPS por rangos fueron aprobados. API, SQLite y sincronización manual mantienen su contrato. Los filtros móviles existentes no incluían condiciones; la cascada de filtros se aplicó a la web. La interacción en teléfono físico e iOS sigue pendiente. No se publicaron versiones en tiendas ni se recrearon suites permanentes.

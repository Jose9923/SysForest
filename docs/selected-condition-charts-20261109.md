# Gráficas según órganos y condiciones seleccionados · 9 de noviembre de 2026

Estado final: ajuste activado y verificado en https://sysforest.varcor.tech el 09/11/2026. Aprobaron la revisión estática, las 35 comprobaciones manuales aisladas y las 11 comprobaciones HTTP del recorrido publicado, además de una consulta de catálogo de solo lectura. La validación visual en navegador continúa pendiente.

## Comportamiento solicitado

En las gráficas web privadas **Estado físico-mecánico** y **Estado fitosanitario**, lo elegido determina las condiciones que se representan:

| Selección dentro de una evaluación | Condiciones mostradas |
| --- | --- |
| Uno o varios órganos, sin condiciones específicas | Todas las condiciones de esos órganos. |
| Condiciones específicas | Únicamente esas condiciones. Si también se enviaron órganos, deben pertenecer a ellos. |
| Ningún órgano ni condición | Agregación anterior de las condiciones registradas en los árboles seleccionados. |

Cada evaluación conserva su selección independiente. Elegir Fuste en la física no limita la serie fitosanitaria a Fuste. Si se eligen condiciones de ambas evaluaciones, cada gráfica muestra su propia selección sobre el mismo conjunto de árboles filtrados.

Las categorías limitan las series; por sí solas no excluyen árboles. Las condiciones específicas mantienen el filtro poblacional existente: **Al menos una** exige cualquiera de las elegidas y **Todas** exige todas, uniendo ambos grupos y consultando la misma última inspección. Se conserva su orden determinista por fecha de inspección, creación e identificador. No se busca una visita antigua para hacer coincidir un árbol.

La consulta cuenta individuos distintos por condición y conserva filas con cero para la selección explícita. Un cero indica que ningún árbol del conjunto filtrado registra esa condición; no acredita que se haya evaluado su ausencia. Un individuo puede aparecer en varias condiciones, por lo que sus cantidades no deben sumarse como si fueran categorías excluyentes.

## Presentación y descargas

Elegir órganos o condiciones retira el recorte de 12 grupos únicamente en la gráfica de esa evaluación. Su altura se adapta a las filas y todas las opciones elegidas permanecen visibles, incluidas las de valor cero. Si todas tienen cero, se presenta un aviso. Al aplicar una selección de condiciones se abre la sección **Condición y gestión**.

Gráfica, tabla, CSV y PNG comparten los datos preparados por el servidor. Los enlaces al reporte y a CSV, Excel y PDF conservan la selección; siguen aplicándose permisos, límites de exportación y paginación. La tabla del reporte y sus descargas utilizan la misma agregación. Los gráficos sin selección explícita mantienen sus límites anteriores.

El selector conserva órganos sin condiciones al recargar la página. Quitar un órgano elimina únicamente sus condiciones seleccionadas. Captura y corrección de inspecciones, mapas, portal público y aplicación móvil conservan su comportamiento. No se añade una gráfica móvil ni se modifica la API pública.

## Contrato técnico

Se añaden dos parámetros privados: `physical_condition_category_ids[]` y `phytosanitary_condition_category_ids[]`. Son arrays de UUID distintos, con un máximo de 100 elementos; se valida existencia, tipo de evaluación y pertenencia de las condiciones cuando también se eligieron categorías. Los filtros permiten referencias históricas inactivas, conforme al contrato de consulta existente.

`ReportSelectionDTO.values` conserva las categorías para presentación y enlaces; no las incorpora al filtro de población de `TreeFilterDTO`. `conditionSeries()` devuelve la selección de cada evaluación y `ReportRepository` parte del catálogo seleccionado para conservar ceros mediante el conteo de su última inspección autorizada. Elegir categorías requiere también permiso de consulta de inspecciones; no amplía el alcance institucional, departamental o nacional.

`ReportReadService` prepara etiquetas legibles y permite estas claves únicamente en la configuración privada. Se excluyen del enlace a cartografía y de las consultas remotas de opciones. El componente de captura conserva sus nombres y comportamiento; el envío de categorías se activa solo en el modo de series de gráficas y reportes privados.

No requiere migraciones, seeders ni cambios de datos. Se mantienen las reglas de captura, riesgo, publicación, sincronización móvil y recibos.

## Evidencia y límites

El registro de trabajo `.charts-selected-work/static-checks.json`, fuera del paquete productivo, documenta 17 archivos de implementación, siete archivos PHP formateados y salida 0 de la revisión estática. También se verificó sintaxis de los JavaScript modificados y de cinco vistas Blade compiladas. No se añadieron suites automatizadas.

La revisión del código comprobó separación entre población y series, independencia de ambas evaluaciones, conservación de selección en enlaces, exclusión del contrato público y retirada del recorte solo cuando corresponde. La comprobación manual aislada en PostGIS completó **35 de 35 casos**, con datos temporales: órganos y condiciones de ambas evaluaciones, ANY/ALL global, última inspección, ceros, referencias históricas inactivas, más de 12 series, coincidencia entre gráfica/tabla/CSV, validación de parámetros y autorización por organización, departamento y ámbito nacional. El entorno QA temporal fue retirado; no se creó una suite permanente. El resultado está en `.condition-series-work/manual-chart-review.json`.

## Activación y comprobación publicada

La imagen activa es `sha256:5f4a345d04e9dfa397a411a8969ad51d41dfff82db0a1406af5bd67d308ee4a6` en `/opt/sysforest/releases/20261104-initial`. El respaldo previo se conserva en `/opt/sysforest/backups/selected-condition-charts-20261109t225559z`. Fue un respaldo online; esta entrega no acredita un ensayo de restauración ni una instantánea coordinada de todos los almacenes.

Se verificaron 851 hashes de archivos de fuente, 195 rutas y los siete servicios saludables. App, queue y scheduler usan la imagen nueva. La configuración privada y los 46 archivos protegidos permanecieron sin cambios. No se ejecutaron nuevas migraciones ni seeders para este ajuste.

El recorrido publicado registró **12 comprobaciones aprobadas: 11 sobre HTTP, HTML y descargas, y una consulta de catálogo de solo lectura**. La evidencia comenzó el 09/11/2026 a las 18:04, hora de Bogotá. Comprobó las series exactas de Fuste, selección de condiciones específicas, independencia de ambas evaluaciones, conservación de población al elegir solo órganos, más de 12 series, selección recuperada en HTML y coincidencia de tablas con los datos del gráfico. El CSV se comparó por filas; Excel y PDF aprobaron cabeceras y estructura o firma de archivo. También se verificaron limpieza de filtros y rechazo de los parámetros privados en el portal público.

No se modificaron registros operativos ni el contexto institucional. La comprobación autenticada generó los eventos normales de inicio/cierre de sesión y preparación de exportaciones; la sesión se cerró al terminar. Los resultados se conservan en `.charts-selected-work/production-deployment-result.json` y `.charts-selected-work/public-charts-verification.json`.

No se completó una sesión visual en navegador: el control de Opera bloqueó la navegación porque no pudo verificar la URL. La revisión HTTP no ejecuta JavaScript ni renderiza el canvas o la imagen PNG. La interacción visual, Select2 y la exportación PNG en navegador permanecen sin validación visual en esta entrega. La revisión de fuente y los datos/HTML publicados sí quedaron comprobados.

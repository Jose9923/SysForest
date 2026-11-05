# Bandeja de árboles y reporte de riesgo · 04/11/2026

## Comportamiento

Gestión del riesgo abre `/risk` como bandeja de árboles de la institución seleccionada. Cada fila muestra identidad, especie, ubicación, estado, fecha y campaña de la última inspección, prioridad profesional, sugerencia automática diferenciada, metodología y confirmación disponible. Incluye enlaces a ficha, última inspección y nueva visita según permisos. Los árboles sin visita o sin evaluación permanecen visibles como tales; no se les asigna riesgo bajo.

Los contadores distinguen Crítico, Alto, Moderado, Bajo, Pendiente de confirmación, Inconsistente y Sin evaluar. Corresponden al conjunto actualmente filtrado, incluida la prioridad. Cada tarjeta permite consultar ese estado. La búsqueda admite código, número anterior, especie o ubicación; hay filtros de inventario, prioridad, estado y fechas, y filtros avanzados de taxonomía y condiciones por órgano. Se mantienen selecciones múltiples, ANY/ALL y restauración de enlaces.

La parametrización previa se consulta en `/risk/methodology`. Conserva permisos, validación, valores anteriores, control de versión y versiones históricas; publicar sigue usando el mismo POST y vuelve a esa pantalla. Abrir la bandeja o descargar un reporte no publica metodologías ni recalcula o confirma resultados.

## Selección y reporte

`RiskTreeReadService` reutiliza la selección común y el reporte privado `risk_trees`. La bandeja fuerza `scope=organization`, incluso si el actor posee un alcance nacional o departamental. Los filtros se aplican a la última inspección escogida por fecha, creación e ID; la evaluación debe corresponder a su `lock_version`. No se busca una visita anterior que coincida con el riesgo. Filtrar inventario selecciona la pertenencia del árbol; la campaña mostrada identifica la última visita y puede ser distinta.

`risk_trees` ofrece una fila por árbol, paginación real y descargas del conjunto completo filtrado. Excel y CSV incluyen explicación, observaciones, nombre del confirmador y fecha cuando existen; PDF resume los campos de la bandeja. Mantiene límites, snapshot de lectura, protección de texto frente a fórmulas, manifiesto y reautorización antes de entregar. El límite actual de PDF es 200 filas; aplicar filtros para consultas mayores. No ofrece GeoJSON. El reporte agregado `risk` continúa disponible y no cambió para los consumidores existentes.

La bandeja exige `risk.view`, `trees.view` e `inspections.view` en la institución elegida; filtros de mantenimiento requieren además su permiso. No exige `reports.view`. Consultar el reporte sí añade `reports.view`, y descargar requiere `reports.export`. Los enlaces se ocultan cuando faltan esos permisos. Departamento conserva lectura institucional sin acciones de escritura. El nuevo reporte se rechaza en la página y descargas públicas, y el lector contiene una guarda adicional. La proyección dedicada no incorpora fotografías, evidencia ni carbono.

## Comprobaciones locales

- Formato PSR-12 y sintaxis PHP/JavaScript aprobados en archivos del cambio; `git diff --check` sin incidencias.
- 19 comprobaciones de render: una fila por árbol, formulario único, 65 condiciones, recuperación de cuatro selecciones y sus órganos, ALL, permisos de enlaces y conservación de metodología/validación.
- 20 comprobaciones independientes de acceso en transacción de solo lectura con permisos en memoria: bandeja sin permisos de reporte, denegación de consulta/exportación, falta de cada permiso de dominio, alcance ajeno/público/nacional/departamental, anulados y aislamiento para superadmin. No se modificaron permisos persistentes.
- 31 comprobaciones de reporte: CSV/Excel/PDF, conteos, límites, proyección dedicada sin evidencia/carbono, rechazo público y ausencia de sugerencias inventadas ante falta de captura. Las escrituras de auditoría de exportación de esa comprobación local se revirtieron y los temporales se limpiaron.
- Revisión en navegador de HTML local con recursos reales: resumen, tabla, enlaces de reporte, filtros restaurados y navegación a Metodología. Las vistas estáticas no ejecutaron consultas backend ni publicaciones de parámetros.
- PDF local de una fila renderizado a PNG e inspeccionado: una página legible, once columnas resumidas, sin recortes ni superposiciones. No acredita todos los tamaños posibles de textos o consultas.

La publicación y comprobación HTTP real se completaron; su evidencia está registrada a continuación. No se añadieron migraciones ni suites permanentes. Esta entrega no cambia reglas científicas, estado del árbol, resultados históricos, API móvil o APK.

## Publicación y comprobación HTTPS

Publicado en [Gestión del riesgo](https://sysforest.varcor.tech/risk) el 04/11/2026 (America/Bogota). Delta de 18 archivos con hashes coincidentes e imagen activa `sha256:8affc5d16977a9bc0da375f4b8443922e6c80e30f55d14431cc5b872676e6761`. App, queue, scheduler, Nginx, PostgreSQL, MongoDB y Redis están saludables. La configuración privada y los 35 archivos previos de vendor/descargas permanecen idénticos, incluida la APK 0.3.1. No se ejecutaron migraciones, seeders ni cambios de datos forestales.

La revisión autenticada por HTTPS comprobó `/risk`, `/risk/methodology` y `/reports?report=risk_trees`, paginación sin repetir árboles, alcance institucional forzado, consulta vacía y recuperación de cuatro condiciones y cuatro órganos con modo ALL. Las descargas CSV, Excel y PDF respondieron 200 y coincidieron con el número de filas de la selección acotada. Se usaron árboles existentes; las tres exportaciones generaron su auditoría normal. La página y los tres formatos públicos rechazaron el reporte privado. La sesión propia de comprobación quedó cerrada y `/health/ready` respondió correctamente.

Respaldo previo: `/opt/sysforest/backups/risk-inbox-20261105T030635Z`. Datos online en `/opt/sysforest/backups/risk-inbox-20261105T030635Z/data/backup-20261105T030707Z-0d4gUP`, fuente e imagen anterior con hashes verificados; tag de recuperación `sysforest-app:before-risk-inbox-20261105t030635z`. El respaldo online de sus componentes no constituye una instantánea global coordinada. Los scripts temporales de comprobación se retiran tras conservar sus resultados, manifiestos y previews.

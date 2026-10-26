# Manuales de SysForest

Edición ilustrada 1.2, 26 de octubre de 2026. Contenido contrastado con `43d83e8` y los ajustes locales de riesgo, carbono e integración móvil de esta fecha. Describe el piloto local y los procedimientos que cada instalación debe verificar.

| Documento | Word editable | Contenido en texto |
| --- | --- | --- |
| Manual de usuario · 43 páginas | [Descargar manual de usuario](SysForest_Manual_Usuario.docx) | [Consultar capítulos](manual-usuario.md) |
| Manual técnico · 31 páginas | [Descargar manual técnico](SysForest_Manual_Tecnico.docx) | [Consultar capítulos](manual-tecnico.md) |
| Integración móvil API v1 · 22 páginas | [Descargar contrato móvil](SysForest_API_movil.docx) | [Consultar contrato](mobile-api.md) |

El manual de usuario reúne acceso y sesiones, roles, organizaciones, inventarios, árboles e inspecciones, nombres comunes, fotografías, mantenimiento, plantilla Excel, importaciones, filtros, cartografía, gráficas, reportes, publicación y auditoría. Integra la confirmación de la prioridad fitosanitaria, avisos de captura incompleta, interpretación del carbono aéreo y seguimiento del recálculo institucional en segundo plano. Incluye diecisiete capturas reales con explicación de los controles.

El manual técnico describe arquitectura, modelo, configuración, seguridad, ámbitos institucionales, JWT, sincronización, concurrencia, fotografías, cartografía, auditoría SQL/MongoDB, operación y recuperación. Explica las instantáneas versionadas de riesgo y carbono, su conservación al publicar, las cantidades ausentes y la cola durable de recálculo. Conserva cuatro diagramas, comandos de operación y referencias a la implementación.

El documento de integración móvil conserva sus diecinueve capítulos de autenticación, catálogos, altas, edición, fotografías y reintentos. Las consultas GET de inspecciones incorporan objetos privados de riesgo y carbono; POST, PATCH y recibos idempotentes mantienen su contrato. Explica permisos, nulos, versiones, lectura posterior al envío y tratamiento offline. La API no confirma la prioridad profesional desde el teléfono.

Los manuales incluyen portada, índice navegable, numeración de páginas y fuentes. Se exportaron con Word nativo y se rasterizaron con el renderizador de documentos para revisar visualmente sus 96 páginas, tablas, listas, imágenes y saltos. Los Markdown permiten revisar los cambios en Git; los Word conservan su composición para lectura e impresión.

Las quince capturas anteriores se conservan desde el 13 de octubre de 2026. Las nuevas pantallas de Carbono almacenado y Gestión del riesgo se capturaron el 26 de octubre en el piloto autorizado. Los totales y opciones corresponden al momento de consulta; la imagen de riesgo muestra una metodología inicial pendiente de publicación, sin acreditar una aprobación profesional. Las capturas no requirieron cambiar mediciones ni referencias científicas.

Las comprobaciones aisladas de servicios, análisis estático y compilación Android se distinguen de la aceptación en dispositivos: esta edición no acredita pruebas en iPhone ni en un teléfono físico. La evidencia se reúne en el [informe de revisión del 26 de octubre](risk-carbon-review-20261026.md). La migración local y activación de servicios del recálculo se registran en [operación](operations.md); no se ejecutó una actualización masiva de estimaciones operativas para elaborar los manuales.

Al cambiar permisos, formularios, contratos o procedimientos, actualiza los capítulos afectados y sus formatos Word y texto. Conserva las capturas vigentes, indica su fecha, actualiza el índice en Word y vuelve a revisar las páginas antes de distribuir la nueva edición. Los detalles de dominio se mantienen en [riesgo](risk-management.md), [carbono](carbon-estimation.md) y [API móvil](mobile-api.md).

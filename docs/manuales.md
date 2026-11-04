# Manuales de SysForest

Edición ilustrada 1.4, 4 de noviembre de 2026. Incorpora el traslado a producción, su URL, preparación del acceso, límites de correo y configuración móvil 0.2.1+3. Conserva los procedimientos y capturas de las ediciones anteriores. El dominio y el acceso HTTPS fueron verificados el 4 de noviembre de 2026.

| Documento | Word editable | Contenido en texto |
| --- | --- | --- |
| Manual de usuario · 44 páginas | [Descargar manual de usuario](SysForest_Manual_Usuario.docx) | [Consultar capítulos](manual-usuario.md) |
| Manual técnico · 35 páginas | [Descargar manual técnico](SysForest_Manual_Tecnico.docx) | [Consultar capítulos](manual-tecnico.md) |
| Integración móvil API v1 · 24 páginas | [Descargar contrato móvil](SysForest_API_movil.docx) | [Consultar contrato](mobile-api.md) |

El manual de usuario reúne acceso y sesiones, roles, organizaciones, inventarios, árboles e inspecciones, nombres comunes, fotografías, mantenimiento, plantilla Excel, importaciones, filtros, cartografía, gráficas, reportes, publicación y auditoría. Integra la confirmación de la prioridad fitosanitaria, avisos de captura incompleta, interpretación del carbono aéreo y seguimiento del recálculo institucional en segundo plano. Explica el ingreso móvil sin dirección de plataforma ni código institucional, la selección por nombre y la conservación de datos offline. Incluye diecisiete capturas reales con explicación de los controles.

El manual técnico describe arquitectura, modelo, configuración, seguridad, ámbitos institucionales, JWT, sincronización, concurrencia, fotografías, cartografía, auditoría SQL/MongoDB, operación y recuperación. Explica las instantáneas versionadas de riesgo y carbono, su conservación al publicar, las cantidades ausentes y la cola durable de recálculo. Documenta la configuración compilada, el descubrimiento autenticado de instituciones y la reautenticación de una cuenta guardada. Conserva cuatro diagramas, comandos de operación y referencias a la implementación.

El documento de integración móvil conserva sus diecinueve capítulos de autenticación, catálogos, altas, edición, fotografías y reintentos. Las consultas GET de inspecciones incorporan objetos privados de riesgo y carbono; POST, PATCH y recibos idempotentes mantienen su contrato. Explica permisos, nulos, versiones, lectura posterior al envío y tratamiento offline. La API no confirma la prioridad profesional desde el teléfono. Añade POST /auth/organizations sin alterar el contrato de /auth/login: la institución se obtiene tras comprobar las credenciales y se revalida al emitir el JWT.

Los manuales incluyen portada, índice navegable, numeración de páginas y fuentes. Se exportaron con Word nativo y se rasterizaron con el renderizador de documentos para revisar visualmente sus 103 páginas, tablas, listas, imágenes y saltos. Los Markdown permiten revisar los cambios en Git; los Word conservan su composición para lectura e impresión.

Las quince capturas anteriores se conservan desde el 13 de octubre de 2026. Las nuevas pantallas de Carbono almacenado y Gestión del riesgo se capturaron el 26 de octubre en el piloto autorizado. Los totales y opciones corresponden al momento de consulta; la imagen de riesgo muestra una metodología inicial pendiente de publicación, sin acreditar una aprobación profesional. Las capturas no requirieron cambiar mediciones ni referencias científicas.

Las comprobaciones aisladas de servicios, análisis estático y compilación Android se distinguen de la aceptación en dispositivos: la edición de noviembre no acredita una nueva aceptación del acceso en iPhone ni en un teléfono físico. La comprobación del nuevo acceso se distingue de la instalación Android realizada anteriormente. La evidencia se reúne en el [informe de revisión del 26 de octubre](risk-carbon-review-20261026.md). El despliegue de producción se describe en [su procedimiento](../docker/production/README.md). La migración local y activación de servicios del recálculo se registran en [operación](operations.md); no se ejecutó una actualización masiva de estimaciones operativas para elaborar los manuales.

Al cambiar permisos, formularios, contratos o procedimientos, actualiza los capítulos afectados y sus formatos Word y texto. Conserva las capturas vigentes, indica su fecha, actualiza el índice en Word y vuelve a revisar las páginas antes de distribuir la nueva edición. Los detalles de dominio se mantienen en [riesgo](risk-management.md), [carbono](carbon-estimation.md) y [API móvil](mobile-api.md).

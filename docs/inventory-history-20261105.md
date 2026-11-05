# Historial y fotografías completos por inventario · 05/11/2026

Backend activado y verificado por HTTPS el 05/11/2026. La publicación de la APK se realiza como paso posterior; este informe no la da por publicada. No se ejecutaron migraciones, seeders ni escrituras de negocio durante esta entrega.

La API privada añade lecturas paginadas de inspecciones y fotografías por inventario. La pertenencia selecciona los árboles, sin limitar sus visitas a una campaña. Las fotos incluyen el árbol, sus visitas y mantenimientos según permisos vigentes. Cada contenido se reautoriza con la política de su propietario. `include_all=1` amplía la lista autorizada a campañas cerradas/inactivas para descarga, conservando el comportamiento legacy por defecto. No modifica reglas científicas ni permisos de escritura.

El contrato completo, capacidades, proyecciones y códigos HTTP están en [mobile-api.md](mobile-api.md#descarga-completa-por-inventario--05112026). Los árboles ya proporcionan la ficha completa desde su página de inventario. Las dos consultas bulk evitan solicitar historial y galería individualmente para cada árbol.

## Comprobaciones locales realizadas

- Sintaxis PHP y Pint de los 14 archivos runtime modificados: PASS. `git diff --check`: PASS.
- 48 comprobaciones de referencias, árboles e inspecciones: `include_all` por HTTP con FormRequest real; compatibilidad legacy; capacidades; historial de otras campañas y sin campaña; selecciones inactivas; campos iguales a fichas individuales; árbol anulado excluido; orden y páginas completas sin duplicados; permisos revocados; riesgo oculto sin `risk.view`; inventarios ajenos/inexistentes y filtros rechazados; JWT y UUID mayúscula. Rollback completo con conteos y SHA256 idénticos de 20 tablas.
- 67 comprobaciones de fotografías: tres propietarios, foto histórica de otra campaña y foto publicada, exclusión de evidencias/árboles/mantenimientos anulados, ramas filtradas por permisos, galería legacy sin cambio, orden y paginación, metadatos explícitos, JWT y aislamiento. Lectura y entrega reales con bytes en memoria, sin archivos ni journals persistentes. Rollback completo con conteos y SHA256 idénticos de 22 tablas.
- Revisión independiente de los 11 archivos de referencias/inspecciones sin hallazgos accionables. 17 comprobaciones adicionales del modo departamental: lecturas completas del tenant seleccionado, rechazo de otros tenants y ausencia de capacidades de escritura/confirmación. Cero consultas de escritura durante este probe; huellas conservadas tras rollback.
- Consultas acotadas: la página de fotos ejecuta exactamente dos consultas de evidencias (COUNT y SELECT), tanto para una fila como para página completa; la autorización previa añade consultas constantes. Para 35 visitas, pasar de una fila a página completa pasó de 147 a 149 consultas totales, por las relaciones precargadas con IDs no nulos, sin una consulta por árbol o visita.

Evidencias locales de esta entrega: `.inventory-history-work/backend-format-verification.json`, `inspections-verification.json`, `photos-verification.json`, `department-verification.json`, `backend-manifest.txt` y `backend-sha256.json`. Los probes son temporales y no recrean una suite permanente. Los manifiestos y las huellas permiten revisar el delta exacto.

## Límites

Las comprobaciones locales usaron fixtures revertidos y, en el probe de fotos, un stream de bytes en memoria. La verificación HTTPS posterior sí utilizó datos y fotografías existentes de producción. Este informe backend no acredita una prueba en teléfono físico, la persistencia SQLite de la app ni una carga de miles de fotografías. No se probó una carrera multiproceso ni existe snapshot entre páginas. La app debe completar todas las páginas y contenidos autorizados antes de afirmar que la descarga terminó.


## Activación y verificación HTTPS

- Imagen activada: `sha256:a69706ad5d76957bf8186f5200cb9f355cae23a6e92b41802260bdfac6181046`.
- Respaldo previo de fuente, imagen y datos: `/opt/sysforest/backups/full-sync-20261105T050718Z`.
- Verificación de despliegue: siete servicios saludables, hashes exactos de los 14 archivos runtime, entorno conservado y los 37 assets/descargas previos sin cambios antes de publicar la APK.
- Lecturas HTTPS con autenticación y contexto institucional normales; mínimo 820 ms entre solicitudes. Se recorrieron ambos inventarios y todas las páginas de sus árboles, visitas, fotografías, riesgo y mantenimientos, además de los 14 catálogos en cada institución. `include_all=1`, capacidades, IDs únicos, totales y metadata de ámbito se comprobaron en las respuestas reales.

| Institución / inventario | Árboles | Visitas | Fotos | Filas de riesgo | Mantenimientos |
| --- | ---: | ---: | ---: | ---: | ---: |
| INEM-VVC / PILOT-SYSFOREST | 5 | 4 | 4 | 5 | 1 |
| RESTREPO-META / ORNATO-2025 | 2854 | 2854 | 0 | 2854 | 0 |

Se verificaron los bytes, tamaño y SHA-256 de las 4 fotografías existentes. Las respuestas sin Bearer, con filtros inesperados y con inventario de otra institución conservaron los rechazos esperados. Se cerraron todas las sesiones propias y los Bearer revocados devolvieron 401. Los conteos y SHA-256 de 26 tablas de negocio y permisos fueron idénticos antes y después. No se invocaron previews, no se inicializaron metodologías y no se enviaron POST/PATCH de negocio; las únicas escrituras esperadas fueron las sesiones y eventos normales de autenticación.

Evidencia de esta fase: `.full-sync-work/production-http-verification.json`; evidencia de activación y respaldo: `.full-sync-work/production-deployment-result.json`.

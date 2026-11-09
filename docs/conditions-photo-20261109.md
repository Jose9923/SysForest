# Condiciones, fotografía principal y paridad móvil · 09/11/2026

## Comportamiento

Diagramas y gráficas y Reportes muestran primero una sección abierta de condiciones físico-mecánicas y fitosanitarias, también en pantallas pequeñas. Conservan el selector compartido por órganos/grupos, varias condiciones por órgano y las referencias históricas. Elegir un órgano vacío no filtra; quitarlo limpia solo sus condiciones. Un único modo Alguna/Todas se aplica a la unión de ambas evaluaciones sobre la misma última inspección determinista. No cambian los DTO, las consultas ni los criterios de exportación existentes. Sin inspections.view no se presentan estas condiciones privadas.

La ficha privada muestra al inicio una foto marcada con el código estable EVD_WHOLE_TREE («Individuo completo»). Se elige entre las fotos vigentes del árbol y de sus inspecciones autorizadas, por COALESCE(captured_at, created_at), created_at e ID descendentes. No se sustituye por otra categoría, una foto de mantenimiento ni una evidencia anulada. Los tipos históricos inactivos siguen siendo elegibles. La foto se muestra completa, sin recorte, y conserva su procedencia y fecha. Las galerías continúan disponibles.

El mapa privado solicita la foto solo al abrir un árbol, no al descargar todos los puntos. GET `/api/v1/map/trees/{tree}/whole-tree-photo` devuelve metadatos mínimos y GET `.../{evidence}/content` reautoriza el alcance y reselecciona la portada vigente antes de leer almacenamiento privado. Ambos requieren acceso actual al árbol; la foto requiere evidences.view y sus inspecciones añaden inspections.view. Los alcances nacional y departamental no cambian la institución de la sesión. No se incorporan fotos al mapa público. Cerrar el popup cancela las solicitudes y revoca la URL temporal.

Los GET móviles de fotos agregan evidence_type_code, evidence_type_name y source_kind, incluso para tipos históricos; el listado por inventario precarga la relación de tipo. No se reescriben los recibos de carga anteriores. La lista móvil de inventarios añade can_view_reports, derivado de reports.view y trees.view. No se amplían roles.

Android 0.3.9+14 reutiliza estos datos: filtros de condiciones en Gráficas y nuevo Reporte local de árboles, foto completa en ficha y mapa, y los siete colores de TreeStatus usados en web. La consulta móvil no genera exportaciones CSV/Excel/PDF; esas descargas siguen en web. Todo usa la copia local. Sincronizar ahora actualiza todos los inventarios autorizados. Abrir una pantalla no inicia red.

## Verificación

- Formato y sintaxis PHP/JavaScript: revisión estática completa, sin suites automatizadas.
- Render aislado de filtros en gráficas y reportes privados/públicos y sin permiso de inspecciones: sin IDs duplicados, recuperación de órganos/condiciones históricas y un único modo de coincidencia.
- Veinte observaciones funcionales locales de repositorio/servicio de foto, con rollback y hashes iguales de siete tablas: prioridad de portada, categorías excluidas, tipo inactivo, ausencia, revocaciones y aislamiento institucional/nacional/departamental. El archivo físico ausente respondió 404. No se escribieron archivos de fotografías.
- Revisión visual manual de filtros a 360 px y de fotografía/ficha/popup a 390 px con datos e ilustración ficticios. Limpieza de un órgano conservó la otra evaluación; cerrar una foto lenta canceló la solicitud; sin permiso se ocultó la foto.
- La vista previa Flutter usa tema real, Android/español y adaptadores locales ficticios. No acredita SQLite nativo, almacenamiento de imágenes Android ni teléfono físico. Durante QA se corrigió el encabezado de selección múltiple para texto ampliado. La evidencia final de compilación y publicación se registra al cerrar la entrega.

No se necesitan migraciones ni seeders para este cambio. No se sustituyen inventarios, árboles ni inspecciones existentes.


## Entrega productiva completada

Activada el 09/11/2026, fuente `cde5585`, imagen `sha256:2c71a9533bccfeae23e81b6b60667b36fc9a611b71e54a710e2dba458094d723`. 846 archivos de runtime verificados y 195 rutas; los siete servicios saludables. Sin migraciones ni seeders. Entorno privado y 42 archivos de vendor/descargas conservados durante el despliegue. La web HTTPS respondió 200 y la metadata de foto sin sesión respondió 401.

Respaldo previo: `/opt/sysforest/backups/conditions-photo-20261109t181417z`, con datos online, fuentes, imagen y etiqueta de reversión. La revisión productiva de solo lectura confirmó 3 árboles/3 inspecciones/3 evidencias, 2 portadas coincidentes entre ficha/mapa y 2 streams de imagen con tamaño/SHA256 correctos; la API móvil entrega código/tipo/fuente y capacidad de reportes. No se modificaron esos registros.

[APK 0.3.9+14 publicada](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.9.apk), misma firma y permisos, 169119449 bytes, SHA256 `e9eb433f06972f5632024d703d03b9824083a0273874ec2a5781cca275e07bc5`. Tras instalar sobre la anterior, usar Sincronizar ahora para actualizar permisos, datos y fotos. Revisión visual y compilación aprobadas; teléfono físico pendiente. Durante la publicación de APK se conservaron las 11 versiones anteriores y no se reinició ni cambió el backend.

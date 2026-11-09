# Tipos de cambio y hora de Bogotá · 9 de noviembre de 2026

Publicado y verificado en https://sysforest.varcor.tech. En Catálogo forestal → Tipos de cambio de ubicación, el administrador nacional puede crear tipos, editar nombre y descripción, ordenar y activar/desactivar. Incluye Corrección, Reubicación, Trasplante y Remoción. Los demás roles conservan consulta según sus permisos.

El formulario del árbol usa los tipos activos. El historial conserva los códigos originales y permite leer tipos desactivados. Un tipo y un motivo pueden registrar el evento aunque no cambien las coordenadas. Remoción no cambia automáticamente el estado del árbol, por decisión expresa del usuario. Los códigos son inmutables y el catálogo no admite borrar referencias utilizadas.

## Base de datos y web

Se aplicaron las migraciones incrementales 000033 y 000034. La primera crea el catálogo y reemplaza la lista cerrada del historial por una referencia a su código, sin reescribir historias. La segunda convierte cinco columnas heredadas sin zona a timestamp con zona, conservando instantes, precisión, nulos y defaults.

PostgreSQL tiene America/Bogota como zona predeterminada. La verificación directa devolvió `2026-11-09T17:44:16.158218-05:00`; la web representó la misma hora local. La conexión ORM permanece explícitamente en UTC para evitar reinterpretar sus enlaces de fechas. La conversión de las cinco columnas conservó sus huellas de instantes; el cambio de zona no reescribió árboles, inspecciones, estados ni eventos.

La presentación, calendarios de fotografías, filtros diarios y reportes usan Bogotá. Los snapshots canónicos, recibos, API y fechas calendario conservan sus contratos. Detalle: [hora de Bogotá](bogota-time-20261109.md).

## Verificación y respaldo

Revisión estática de todo el backend aprobada. QA temporal aislada: 32 observaciones del catálogo y 10 de migración/fechas/vistas; incluyó roles, referencia inactiva, conservación de historia, FK, append-only, cambio sin mover coordenadas, reversión horaria y bindings ORM. Las vistas se renderizaron y comprobaron por contenido, sin inspección visual en navegador. No se recreó una suite permanente y el contenedor temporal se retiró.

Tras activar la imagen `sha256:0fb9e43f1413c874649da649cb38b6a65b7e4b911c1b6cc7ba86a0368cb12ae7`, los siete servicios quedaron saludables. HTTPS autenticado comprobó dashboard, lista, alta y edición del catálogo, perfil, auditoría, cuatro fichas de árboles y el catálogo móvil con sus cuatro códigos activos. Ambas sesiones de comprobación se cerraron. Se conservaron configuración privada, dependencias y descargas previas.

Respaldo anterior: `/opt/sysforest/backups/change-catalog-bogota-20261109t223859z`. Evidencias locales: `.change-catalog-work/production-deployment-result.json`, `database-migrate-result.json`, `database-timezone-result.json`, `public-web-verification.json`, `manual-catalog-review.json` y `legacy-time-review.json`. No hubo seeders ni reinicio de datos.

## Android

[APK 0.3.12+17](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.12.apk): 169105005 bytes, SHA-256 `719acaed3090723a7697897e426ee51570e714c130142b2b99090599571f0431`. Mismos permisos y firma que la versión anterior. Instalar sobre la anterior sin desinstalar y ejecutar Sincronizar ahora para recibir los tipos actualizados. La app conserva pendientes y admite seleccionar un tipo activo cuando el anterior fue desactivado, usando el flujo existente de resolución.

Formato, análisis, compilación limpia (52,4 segundos), integridad, firma y descarga HTTPS verificados. No se modificaron SQLite, UUID, recibos ni núcleo de sincronización. Comprobación física en teléfono pendiente.

# Captura inicial y módulos móviles · 4 de noviembre de 2026

El registro web del árbol contiene su primera inspección completa, con 23 condiciones físico-mecánicas, 42 fitosanitarias, seis categorías de fauna, mediciones, fenología y observaciones. Ambos se confirman juntos. La edición de identidad no inserta una visita; el módulo Inspecciones inicia las posteriores.

La app 0.3.0 aplica el mismo recorrido y usa el item de sincronización existente con árbol e inspección inline. Añade creación offline de inventarios con recibo idempotente y consulta de mantenimientos por campaña. La sincronización continúa siendo exclusivamente manual, y los permisos vigentes deciden quién puede usar cada función.

## Comprobaciones de fuente

- Web: sintaxis y PSR-12 de nueve PHP; render del alta y visita posterior sin formularios anidados ni IDs repetidos, con grupos de condiciones abiertos y recursos cargados una vez.
- Alta web comprobada dentro de transacción temporal: árbol, visita, campaña, medidas, fenología, condiciones, fauna y decisión profesional. Errores de fecha, condición, campaña o falta de confirmación de riesgo revierten también auditoría, historiales, derivados y consecutivo. La edición de identidad no añadió visitas.
- Revisión visual local: casillas agrupadas visibles y selección con actualización del contador. El snapshot no ejecuta escrituras ni la consulta real de riesgo.
- API: sintaxis y PSR-12 de 15 archivos; 28 comprobaciones temporales de alta/replay, UUID, conflictos, validación, permisos revocados, paginación, filtros y aislamiento institucional. Las transacciones de comprobación se revirtieron y no se creó una suite permanente.
- La migración aditiva 000032 fue aplicada al entorno local con rol migrador; disponibilidad local HTTP satisfactoria. No ejecutó seeders.
- Móvil: revisión independiente de idempotencia, dependencias, migración, selección persistente y cancelación. La evidencia de compilación y publicación se registra al concluir la entrega.

## Límites

No hay teléfono conectado para validar estos recorridos en un dispositivo físico. La APK de validación conserva la firma de las versiones anteriores; deberá instalarse encima sin borrar datos. No se declara certificación de iOS, publicación en tiendas, ensayo concurrente de carga ni prueba completa de restauración. Los escenarios físicos están en la documentación móvil.

## Entrega Android 0.3.0+5

[APK de actualización](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.0.apk) publicada y comprobada por HTTPS. Conserva la firma anterior y `pubspec.lock`. Formato, análisis estático, compilación, paquete y las tres arquitecturas Android aprobados; descarga por rangos y manifiesto de hash verificados. SHA-256 del APK: `30fd0dbbab491587f6aaee15e991ffe73c715a2afdde2e373b6c58f554f591da`. La migración SQLite se comprobó en memoria sin conservar una suite permanente. La validación física continúa pendiente.

## Captura completa y aplicación 0.3.0 · 4 de noviembre de 2026

Se publicó el formulario web que registra árbol y primera inspección juntos y la ampliación móvil de inventarios y mantenimientos. El delta de 32 archivos fue verificado por SHA-256; se aplicó únicamente la migración aditiva 000032. Se reconstruyó la imagen y se recrearon app, queue y scheduler. Los siete servicios terminaron saludables. La configuración privada y SMTP permanecieron idénticos, y las bibliotecas y descargas anteriores conservaron sus huellas.

Imagen activa: `sha256:ee2b1ce84aed944ace6a463a8cbae4a4188375576ff874a31f9d548246e608c7`. El respaldo previo conserva datos, fuente e imagen bajo `/opt/sysforest/backups/field-capture-20261105T011715Z`; la copia de datos es `/opt/sysforest/backups/field-capture-20261105T011715Z/data/backup-20261105T011733Z-mgULmu`. Fue un respaldo online: no representa una instantánea coordinada de todos los almacenes ni un ensayo de restauración. Se conserva la imagen anterior etiquetada como `sysforest-app:before-field-capture-20261105t011715z`. Las rutas del respaldo usan UTC; la publicación corresponde al 4 de noviembre en America/Bogota.

HTTP autenticado comprobó el alta con 23 condiciones físicas, 42 fitosanitarias y seis categorías de fauna, la sugerencia de riesgo sin crear árbol y los formularios de visitas posteriores. En ambas instituciones se comprobaron capacidades, inventarios, árboles y mantenimientos. Una creación de inventario incompleta respondió 422 sin registros. Se cerraron exclusivamente las sesiones de verificación; no se insertaron datos productivos de prueba.

[Descargar APK 0.3.0](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.0.apk): paquete Android `co.sysforest.mobile`, versión 0.3.0+5, variante debug universal y misma firma anterior. Compilación, análisis estático, firma, tamaño, descarga HTTPS por rangos y manifiesto de hash aprobados. Instalar encima y después actualizar referencias desde Sincronizar. La validación física permanece pendiente. No se realizó commit ni push de esta ampliación.

El detalle de la implementación y sus límites está en [captura inicial y módulos móviles](field-capture-20261104.md).

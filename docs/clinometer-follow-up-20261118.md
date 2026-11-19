# Inclinómetro y seguimiento de árboles — 18/11/2026

## Estado de la entrega

**Antecedente V1; fórmula sustituida por decisión posterior del usuario.** Las comprobaciones descritas abajo corresponden a esa implementación. El commit `982128f` se publicó y su imagen llegó a activarse con la migración aditiva `000036`; antes de la verificación HTTPS final, el usuario pidió las fórmulas de ángulos firmados. Se recuperaron las fuentes y la imagen anteriores, con siete servicios saludables, conservando `000036` aplicada y sus seis campos `NULL` en las dos visitas existentes. No se hizo reset, rollback de datos ni recálculo histórico.

La comparación inmediatamente posterior a la migración, con los tres servicios PHP pausados, conservó las 71 tablas verificadas al excluir únicamente las columnas nuevas. Tras reabrir el servicio y recuperar la versión anterior, 69 tablas de negocio, recibos e historiales seguían idénticas; únicamente cambiaron `auth_sessions` y `refresh_tokens` durante esa ventana, sin atribuir aquí su origen. Se mantuvieron dos árboles, un inventario, dos visitas y siete fotografías, además de entorno, almacenamiento, visores y respaldo verificado. La imagen activa recuperada fue `sha256:7d6fa07aa5aaaa9c927251ac9e868dc3b61ab0a3d667efebb5e25e93605a56a5`. El checkout fuente productivo quedó en `982128f`, separado del release recuperado.

La APK V1 0.3.14+19 quedó cargada en privado y **no se publicó**; su gate y la comprobación HTTPS V1 se cancelaron. El contrato vigente y el cierre posterior de **V2 activada, con la APK corregida publicada**, están en [Ángulos firmados](clinometer-signed-angles-20261118.md). La publicación V2 conserva el número 0.3.14+19 porque la candidata V1 nunca estuvo disponible públicamente. Evidencia del corte y recuperación: `.clinometer-work/release/production-deployment-result.json`. No se acredita prueba de Android físico.

## Medición de alturas

La captura de árbol con primera inspección y las visitas posteriores ofrecen la casilla **INCLINÓMETRO**. Al activarla se registran cuatro observaciones: distancia horizontal al árbol, ángulo de elevación, ángulo de depresión y altura de la persona. Los ángulos son grados y las distancias son metros. Se usa **el mismo ángulo de elevación en ambas fórmulas**, según lo solicitado:

```text
altura total = distancia × tan(elevación) + distancia × tan(depresión)
altura comercial = distancia × tan(elevación) + altura de la persona
```

La vista previa no reemplaza la validación del servidor. `ClinometerInput` normaliza operandos y alturas a seis decimales y aplica las reglas de dendrometría existentes. Distancia y altura de la persona deben ser positivas; ambos ángulos deben ser mayores o iguales a 0 y menores de 90. Se rechazan entradas incompletas, no finitas, notación científica y valores que excedan la precisión permitida. La altura total debe ser positiva y la comercial no puede superar la total **después de normalizar a seis decimales**. No se intercambian ángulos, recortan alturas ni altera la fórmula para conseguir un resultado válido.

Con la casilla desactivada se conserva la captura manual de las dos alturas. Las demás mediciones y reglas forestales siguen su flujo anterior. La implementación reproduce las fórmulas solicitadas; no acredita calibración del instrumento ni validación de campo.

## Persistencia y compatibilidad

La migración aditiva `2026_11_18_000036_add_inspection_clinometer_measurements.php` añade a `tree_inspections`:

| Campo | Contrato |
|---|---|
| `uses_clinometer` | Booleano nullable; `NULL` conserva el antecedente sin método registrado. |
| `clinometer_distance_m`, `clinometer_observer_height_m` | Decimales `18,6`, nullable. |
| `clinometer_elevation_degrees`, `clinometer_depression_degrees` | Decimales `8,6`, nullable. |
| `clinometer_formula_version` | Identificador técnico generado por el servidor; `INCLINOMETER_V1` al usar el método. |

La restricción SQL exige la coherencia entre método, operandos y versión. No rellena mediciones anteriores ni recalcula inspecciones históricas. Las nuevas revisiones de auditoría conservan método y operandos; no se reescriben snapshots ni documentos previos. La ficha de inspección y el resumen de la última visita muestran los operandos cuando existen, sin exigir al usuario interpretar una versión técnica.

El modo omitido por clientes anteriores sigue siendo válido. En una corrección de una visita ya medida con inclinómetro, omitir el modo y reenviar alturas numéricamente iguales conserva el método. Cambiar explícitamente esas alturas sin enviar el modo se interpreta como corrección manual y limpia los operandos del método; no se atribuye al instrumento una altura manual. La normalización ocurre en la escritura de inspección, después de la comprobación del recibo/hash móvil. Los `operation_id` y `client_id`, los cuerpos originales de reintento y los recibos confirmados permanecen intactos.

El manifiesto y las capacidades móviles anuncian `supports_clinometer`. Los GET privados de inspecciones incluyen los campos del método; la consulta sigue exigiendo sus permisos actuales. El APK conserva el modo y los cuatro textos en el borrador. Ante un servidor anterior sin esa capacidad, no permite guardar una nueva captura con inclinómetro; conserva las entradas y permite elegir de forma explícita el modo manual, sin enviar las claves nuevas al contrato anterior. No se reescriben pendientes congelados para incorporar el método.

## Seguimiento visible en web

El menú institucional y la bandeja de Árboles enlazan **Seguimiento de árboles**, `GET /trees/follow-up` (`trees.follow-up`). La nueva bandeja utiliza `TreeFollowUpController → TreeFollowUpReadService → TreeFollowUpRepository`, el motor común de filtros y paginación de 20 elementos. La proyección breve carga identidad, especie/nombres, ubicación y estado; evita cargar visitas, fotografías, auditoría o resultados completos por fila.

Con `trees.view` se accede a los árboles del contexto institucional autorizado. Con `inspections.view` se agregan el número de visitas y la última fecha, además del enlace a todas las visitas. El conteo representa el historial del árbol entre campañas; los filtros de condiciones, fecha y mediciones conservan la selección por última visita determinista. `risk.view`, `maintenances.view` y `trees.restore` siguen siendo necesarios cuando el filtro correspondiente lo exige. La acción **Nueva revisión** requiere `inspections.create` y un árbol vigente. No se crean permisos ni consultas desde Blade.

Los accesos **Historial** y **Historial del árbol** llevan a `#tree-history` de la ficha existente. Se conservan las visitas, mantenimientos, cambios de estado y cambios de ubicación, con sus autorizaciones. Las anclas organizan esa información; no duplican registros ni sustituyen el historial por la última visita.

## Android y alcance de los filtros

Android hace visible **Seguimiento** y enlaza desde Inventarios/Árboles a seguimiento y gráficas. La selección de árbol acota las visitas descargadas y permite consultar su ficha, fotografías y mantenimientos autorizados. La lista y el mapa reutilizan el motor de filtros locales de Gráficas: taxonomía, condiciones físico-mecánicas y fitosanitarias, estado y demás criterios disponibles, manteniendo ANY/ALL sobre la misma última visita, el inventario seleccionado y las capacidades vigentes. Abrir o cambiar filtros no sincroniza automáticamente.

El contenido sin conexión representa lo descargado para esa cuenta e institución; **Sincronizar ahora** actualiza datos y capacidades. Esta entrega no añade un módulo Reportes a Android ni una API móvil de auditoría completa de cambios de estado/ubicación. El historial completo de esos cambios permanece en la ficha web. La evidencia visual y de publicación móvil se registra por separado.

## Comprobaciones realizadas y límites

- 34/34 observaciones temporales de `ClinometerInput`: fórmulas, redondeo, límites, modo manual y compatibilidad. Sin conexión a base de datos.
- 18/18 observaciones de integración en PostgreSQL desechable: escritura de inspección, proyección, auditoría, compatibilidad y reintentos. La migración `000036` conservó las huellas de siete tablas preexistentes y dejó `NULL` los nuevos campos de la visita histórica de la fixture. No se migró ni sembró producción para estas comprobaciones.
- 22/22 observaciones de Seguimiento en una transacción local `READ ONLY` con rollback: ámbito, conteo, última visita, proyección, filtros y permisos. El repositorio utilizó cinco consultas con relaciones precargadas. La organización local observada tenía un árbol visible; no se presenta como prueba de carga.
- Revisión independiente de las tres clases PHP de Seguimiento y revisión visual CUA de su HTML Blade real en escritorio y a 390 px: sin desbordamiento horizontal, acciones completas y tarjeta legible. Los enlaces de negocio no se ejecutaron desde el snapshot.
- Formato PSR-12 y sintaxis aprobados para los 14 PHP del método y los cuatro PHP de Seguimiento; `git diff --check` de sus cambios aprobado. No se añadió una suite permanente.

Evidencias auxiliares de esta entrega: `.clinometer-work/backend/{pure-observations,integration-observations,migration-observations,static-verification}.json` y `.tree-followup-work/{readonly-observations,source-verification}.json`. Son artefactos de revisión fuera del repositorio, no comandos de operación productiva. El cierre de imagen/APK/despliegue debe añadirse únicamente después de obtener su resultado real.

## Cierre de revisión visual e imagen candidata

CUA verificó los formularios web reales de alta de árbol, nueva visita y corrección; la corrección de una fixture se guardó mediante HTTP y mostró las alturas, operandos y revisión nueva. Con distancia 10 m, elevación 45°, depresión 10° y persona 1,7 m, la vista previa y la ficha conservaron altura total 11,763270 m y comercial 11,700000 m. Depresión 0° mostró la validación comercial≤total. Alternar método conservó los controles/valores, y escribir mantuvo foco/posición. Escritorio y 390 px sin superposición ni desbordamiento. El enlace real Seguimiento→Historial abrió visitas, mantenimientos y cambios de estado/ubicación. La sesión ficticia quedó cerrada.

La fixture Flutter con tema Android y widgets reales verificó el mismo cálculo, selección de visitas por árbol, filtros de lista 2→1→2 y conservación de scroll. Se corrigieron el indicador de filtros siempre activo por inventario fijo, el foco al cambiar método, etiquetas truncadas al 200% y botones contiguos. La comprobación final mostró completas las cuatro etiquetas de entrada y las dos alturas a 390 px/200%. La fixture no prueba SQLite nativo, GPS, sincronización en teléfono ni mapa; este último comparte el motor de filtros revisado.

La imagen candidata se construyó desde 949 archivos runtime sin cambios durante la compilación: `sha256:3d243ac8484ad0decd41f7649f2efabe4dcbaf43d4b03c7f8d2b038d948b3bef`. Los ocho servicios locales conservaron identidad y salud; la compilación no activó código ni ejecutó migraciones. Se compilaron las vistas Blade y 196 rutas en QA. Evidencia adicional: `.clinometer-work/{web,mobile}/visual-review.json` y `.clinometer-work/release/backend-image-result.json`. Las comprobaciones temporales y los secretos ficticios se retiran al cerrar QA.

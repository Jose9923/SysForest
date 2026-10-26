# Revisión de riesgo y carbono del 26 de octubre de 2026

Esta actualización corrige inconsistencias detectadas tras el commit `43d83e8` e incorpora controles de calidad, recálculo institucional en segundo plano y consulta móvil de resultados. La fuente del backend es `/home/josev/SYSFOREST`; Flutter se mantiene en el proyecto `mobile` separado.

## Publicación e historia

Publicar o despublicar una inspección conserva el control de concurrencia y agrega instantáneas equivalentes de riesgo y carbono para la nueva versión. Las instantáneas referencian su antecedente con `carried_forward`, conservan entradas, huella, metodología, confirmador y fecha original. El evento de publicación sigue atribuido al publicador en su auditoría propia; no se inventa una decisión profesional nueva. Solo se conserva carbono compatible con la especie y el hábito actuales.

La publicación, las nuevas instantáneas y la auditoría se confirman en una única transacción. Repetir una publicación sin cambios no crea otra versión. La relación de riesgo exige revisión vigente; la proyección móvil descarta resultados de una revisión diferente ante lecturas concurrentes. No se reescribe el historial anterior.

Una consulta de solo lectura en el piloto local, después de la corrección, encontró cero visitas con resultados anteriores sin una instantánea de su revisión vigente para ambos módulos. No hizo falta reconstruir resultados anteriores.

## Calidad de riesgo

Sin condiciones que participen en la metodología, la sugerencia automática queda vacía y se muestra una advertencia: falta de información no equivale a árbol sano. La web permite una decisión profesional explícita con observaciones justificadas. Los históricos conservan decisiones y snapshots; su presentación distingue sugerencias antiguas sin datos de una evaluación vigente fundamentada.

`coverage_status` describe únicamente la relación entre condiciones registradas y metodología: `complete`, `partial` o `missing`. No acredita que todos los órganos hayan sido inspeccionados. Las condiciones excluidas se explican por nombre y órgano cuando sus etiquetas están disponibles. Una nueva metodología no puede reducir la prioridad al aumentar severidad o extensión. Las metodologías históricas permanecen inmutables.

## Recálculo de carbono y reportes

La migración aditiva `2026_10_26_000031_create_carbon_refresh_runs.php` incorpora estado durable, cursor y progreso por institución. Cada visita confirma cálculo y avance juntos; un lote procesa hasta 25 visitas o 15 segundos, con bloqueos breves. Redis ejecuta el trabajo y el scheduler recupera despachos pendientes. Un cambio de referencias o la pérdida de autorización del solicitante cancela la ejecución con explicación.

Se conserva una sola ejecución activa por institución. Repetir solicitudes o reintentar trabajos no duplica estimaciones equivalentes. Las visitas nuevas se calculan al guardarlas. La pantalla muestra progreso y resultados agregados; los reportes separan cobertura, referencias bibliográficas provisionales y referencias revisadas. La exportación PNG muestra «Sin estimación» para nulos y mantiene cero cuando el valor realmente es cero.

El módulo estima carbono aéreo almacenado. No calcula huella institucional de emisiones, captura anual, raíces, suelo ni créditos de carbono. La aplicación no inventa densidades, incertidumbres o calibraciones locales. La aplicabilidad y las referencias requieren revisión profesional para el contexto de uso.

## API y app móvil

Los GET de visitas agregan `risk` y `carbon` mediante proyección explícita. Riesgo exige además `risk.view`; las consultas conservan el aislamiento institucional y los permisos de inspecciones. Los recibos de escritura permanecen congelados. Después de confirmar una visita, Flutter intenta un GET independiente para obtener resultados actuales; un fallo de lectura no invalida el envío confirmado.

La ficha móvil muestra prioridad, confirmación pendiente, versión de metodología, avisos y cantidades con procedencia. Conserva la copia y fecha de consulta en el JSON local. Sin red presenta esa copia y no calcula ni confirma decisiones científicas. Descargar detalle actualiza decisiones posteriores. El contrato completo está en `docs/mobile-api.md` y en el Word de integración.

## Verificación realizada

- 29 comprobaciones de servicios en PostgreSQL/PostGIS temporal: conservación al publicar/despublicar, historial, actor/fecha, rollback, reportes, versiones concurrentes, GET/paginación, permisos, aislamiento institucional, creación/replay y nulos. Fixtures sintéticos y transacción revertida; base temporal retirada.
- Riesgo: 48 comprobaciones aisladas, distribuidas en 35 PHP, 9 de renderizado Blade y 4 JavaScript. No sustituyen una prueba integral HTTP.
- Carbono: 26 comprobaciones en base aislada; dos procesadores concurrentes recorrieron 30 visitas sin duplicados; 9 comprobaciones JavaScript para PNG/CSV. Un worker Redis real de QA procesó dos trabajos de 25 y 5 visitas, con 30 estimaciones y cola vacía. Base y claves temporales retiradas.
- `bash bin/check all`: PSR-12 correcto en 592 archivos, sintaxis PHP correcta en 593 y JavaScript en 30; código de salida 0. Registro en `storage/app/checks/20261026T131725Z-A6dQog/`.
- Flutter: formato y `flutter analyze --no-pub` sin incidencias; APK debug universal compilado y firma v2 verificada. Paquete `co.sysforest.mobile`, versión `0.1.0+1`, Android mínimo 24 y objetivo 36.
- Navegador local: inicio de sesión de desarrollo, acceso institucional, pantallas de riesgo/carbono, botón de recálculo habilitado y reporte de carbono con valores ausentes diferenciados. No se publicaron metodologías ni se alteraron inspecciones para probar la interfaz.

La migración 31 quedó aplicada en el piloto local, lote 20. Se emitió `queue:restart` y se reinició el scheduler. `/health/ready` respondió `{"status":"ok"}`; los servicios continuaron saludables. Para otras instalaciones deben aplicarse las migraciones y reiniciarse los trabajadores siguiendo `docs/operations.md`.

Los manuales técnico y de usuario y el Word de integración móvil se actualizaron y se verificaron visualmente. No se recreó una suite permanente ni se hizo publicación externa. Quedan pendientes la aceptación completa en teléfonos Android/iPhone y la compilación iOS en macOS/Xcode. Las comprobaciones realizadas no acreditan validación científica local de cada especie o inspección.

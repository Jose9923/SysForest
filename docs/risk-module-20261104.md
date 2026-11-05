# Decidir el riesgo desde Gestión del riesgo · 04/11/2026

## Estado de esta entrega

Publicado el 4 de noviembre de 2026 (America/Bogota) en https://sysforest.varcor.tech. Web y API activadas; [Android 0.3.3+8](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.3.apk) disponible con la misma firma de las versiones anteriores. La prueba en teléfono físico sigue pendiente.

## Flujo web

La bandeja `/risk` ofrece **Cambiar riesgo** en cada fila con inspección cuando la cuenta tiene permiso vigente para corregirla. La pantalla `GET /risk/trees/{tree}/decision` permanece dentro de Gestión del riesgo: muestra el árbol, última visita y campaña, prioridad profesional registrada, sugerencia automática actual y metodología. Permite cambiar únicamente la prioridad y las observaciones, y exige confirmación expresa. Guardar vuelve a la bandeja; cancelar no escribe. La metodología institucional permanece en su pestaña separada.

La prioridad existente puede mostrarse seleccionada como antecedente, pero la casilla de confirmación siempre empieza vacía, también después de un error de validación. Sin sugerencia se exige justificación; una contradicción se muestra y bloquea la confirmación. Un árbol sin inspección presenta el aviso para registrar una visita, sin inventar evaluación ni crear una visita implícita.

PATCH a la misma ruta recibe exclusivamente `inspection_id`, `lock_version`, `risk_priority`, `risk_confirmed`, `risk_methodology_id` y `general_observations`, además de los controles HTTP/CSRF. Cualquier campo de mediciones, fechas, condiciones, identidad o tenant enviado a esta pantalla se rechaza. El formulario recupera prioridad y texto tras validación; no reconfirma automáticamente. Si cambió la visita o su versión, responde 409 con enlaces para actualizar la revisión o volver a Gestión del riesgo.

## Escritura y permisos

El servicio exige `risk.view`, `trees.view`, `inspections.view` e `inspections.update`; `risk.manage` corresponde a parametrización y no es requisito para decidir. Reautoriza después de recargar y bloquear actor, organización y árbol. Usa `TreeInspectionRepository.latestForTree`, cuyo orden es fecha de inspección, creación e ID descendentes, y comprueba el identificador abierto antes de llamar a `TreeInspectionService.update` con su versión.

La actualización reutiliza validación, metodología vigente, conservación de observaciones originales, historial y transacción de inspecciones. Mantiene identidad, fecha, mediciones, condiciones, fauna y fenología omitidas por esta pantalla; incrementa la revisión de la misma visita y conserva el riesgo anterior. El servicio común recalcula derivados de esa revisión y registra carbono y auditoría con su comportamiento vigente. No se modifican las reglas científicas ni se asigna el riesgo a `Tree.current_status`.

El bloqueo compartido con altas y correcciones impide seleccionar una visita y escribir sobre otra durante la misma transacción. Si apareció una última visita más reciente o cambió la versión, se exige revisar nuevamente. Una cuenta de otra institución no accede al árbol; la consulta departamental no obtiene capacidad de edición.

## Módulo móvil y API

`GET /api/mobile/v1/risk/trees` entrega una fila por árbol del inventario autorizado, con búsqueda, prioridad y paginación. Reutiliza la selección de la bandeja web y la última visita global por árbol. La proyección móvil es explícita y no expone evidencia, carbono, auditoría ni nombre del confirmador. Las capacidades `supports_risk_inbox` y `can_view_risk_inbox` anuncian disponibilidad y permisos.

El módulo prepara una decisión sobre la última inspección existente. El preview y PATCH incluyen `require_latest_inspection: true` junto con la versión; la guardia bloquea nuevas escrituras obsoletas. El contrato mantiene las correcciones históricas anteriores cuando el campo se omite. Un replay confirmado devuelve el resultado original aunque luego exista una visita más reciente. Mantiene UUID durable, permisos actuales, confirmación explícita y sincronización manual. El contrato completo se documenta en [API móvil](mobile-api.md).

## Comprobaciones locales

- 41 comprobaciones web con PostgreSQL y rollback total: guardado sobre la misma visita, medidas/fecha/campaña/condiciones conservadas, autor/origen, revisión y riesgo históricos, conflictos de versión y nueva última visita, rechazo de campos ajenos, confirmación, metodología y justificación, aislamiento y permisos en GET/PATCH, rol departamental, formulario sin anidación, tokens de control, ausencia de confirmación automática, recuperación de texto y error único.
- 60 comprobaciones API con rollback y huellas/conteos idénticos de 16 tablas: alcance institucional e inventario, filtros admitidos/rechazados, última visita global, estados de riesgo, proyección, paginación sin duplicados, permisos por dominio, consulta departamental, nueva última visita en preview/PATCH, corrección histórica compatible y replay original.
- Incluyen HTTP real del framework con JWT temporal: consulta 200, ausencia de Bearer 401, filtro de scope rechazado 422, inventario ajeno 404, PATCH con UUID en mayúsculas y replay con UUID en minúsculas. El token permaneció en memoria y la sesión se revirtió.
- PSR-12 y sintaxis aprobados en cuatro archivos PHP web y nueve de API. Vistas Blade renderizadas y verificación de espacios de diff aprobada. Paquete runtime de 18 archivos, con manifiesto, huellas y cuatro rutas requeridas verificadas.
- Revisión independiente de aislamiento, permisos, bloqueo, identificadores/versiones y confirmación. Se corrigió la comparación del UUID de ruta con el identificador canónico antes de finalizar las pruebas.

Evidencia fuera del repositorio en `.risk-module-work/`: `web-verification.json`, `api-module-verification.json`, `web-review-verification.json`, `api-format-verification.json`, manifiestos/hashes runtime y snapshots HTML en `preview/`. No se añadieron suites permanentes ni se alteraron datos forestales productivos para verificar esta entrega.

## Límites

Las guardias se verificaron mediante intercalado secuencial y revisión de los bloqueos; no hubo estrés concurrente multiproceso. La revisión visual interactiva del formulario web y las capturas de widgets Flutter se realizaron; no sustituyen una prueba física de Android. La publicación y las comprobaciones HTTPS se detallan a continuación.

## Publicación y evidencia final

El módulo Android tiene entrada propia en el menú, bandeja por inventario, búsqueda y filtro locales y botón Cambiar riesgo. Guarda una propuesta de la misma visita y vuelve conservando filtros y desplazamiento. Solo Sincronizar ahora envía los cambios. La copia offline y los pendientes permanecen aislados por cuenta/inventario; actualizar la bandeja no inicia envíos.

Nueve escenarios de widgets Flutter reales y nueve grupos de AppController/LocalStore con SQLite real aprobaron navegación, permisos, retorno, paginación completa y atómica, fallos y aislamiento, carga de detalle, indicador durable de última visita, rechazo de cambios mezclados, archivo explícito de un conflicto definitivo y replay PATCH 503→200 con ruta/cuerpo/UUID idénticos. No hubo peticiones reales en el probe de almacenamiento. Los paquetes de comprobación fueron temporales, sin cambios de dependencias ni suites permanentes. Formato de 26 archivos Dart, análisis sin incidencias, diff y compilación Android aprobados; 42 archivos de ejecución congelados e íntegros.

La revisión CUA de Blade con los estilos reales verificó bandeja→Cambiar riesgo→volver, selección de prioridad, justificación, confirmación explícita y botón Guardar riesgo legible. No envió cambios de negocio. En producción, HTTPS autenticado comprobó la bandeja web y su formulario dedicado, capacidades, paginación API completa, rechazos de alcance/filtros/inventario y sugerencia sobre la visita vigente. Las huellas de 14 tablas de negocio/permisos y la metodología existente quedaron idénticas. Se cerraron las dos sesiones propias y se rechazó su reutilización.

Backend: 18 archivos publicados e imagen `sha256:e1654affc1c48cf700614f14716ed6a5724cb074c3738a211a757415e97e0007`. Siete servicios saludables, fuente verificada, configuración privada/SMTP y 36 archivos previos de vendor/descargas conservados antes de añadir la APK. Sin migraciones, seeders, cambios de roles ni escrituras forestales de prueba en producción. Respaldo `/opt/sysforest/backups/risk-module-20261105T041104Z`, datos `/opt/sysforest/backups/risk-module-20261105T041104Z/data/backup-20261105T041116Z-8xysun`, fuente e imagen anterior con tag `sysforest-app:before-risk-module-20261105t041104z`. El respaldo online no es una instantánea global coordinada ni acredita un ensayo de restauración.

APK [0.3.3+8](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.3.apk), paquete `co.sysforest.mobile`, variante debug universal, 168,910,257 bytes, SHA-256 `fdec923621363fbaeeaba6c5adb3cab24cb9795f3cf83486930fff51c6fd7148`, certificado `a85ea5df1632cdf7a03dc5fafa4b08a2dfbcf6ee3fb10d4b32a8fad84d37c272`. Configuración HTTPS productiva, tres arquitecturas, firma compatible, tamaño, MIME, rangos inicial/final y manifiesto de hash comprobados. Versiones anteriores preservadas. Instalar encima, obtener capacidades con Sincronizar ahora y después Actualizar bandeja. No hubo prueba física ni compilación iOS/publicación en tiendas.

Evidencia adicional: `controller-verification.json`, `flutter-risk-module.json`, `web-visual-verification.json`, `mobile-static-verification.json`, `source-freeze.json`, `production-deployment-result.json`, `production-http-verification.json`, `apk-publication-result.json` y artefactos en `outputs/SysForest-Mobile-0.3.3-20261104`.

## Aclaración de ausencia de sugerencia · 04/11/2026

El texto genérico «Sin sugerencia automática» confundía dos estados diferentes del preview: `valid` con prioridad nula por información insuficiente e `inconsistent` con condiciones incompatibles. El primero ya permitía seleccionar y confirmar una prioridad con justificación; el segundo bloquea la decisión según la metodología vigente. Una versión obsoleta y un árbol sin visita conservan sus mensajes y restricciones separados.

El diagnóstico productivo de solo lectura encontró en INEM-000001, visita del 24/09/2026 versión 2, las selecciones Follaje / Sano y Follaje / Entorchamiento. No había evaluación persistida para esa revisión; el preview vigente devuelve inconsistencia por ese par. INEM-000003 no tenía inspección. El diagnóstico no inicializó metodología ni escribió registros. Son observaciones del momento de la comprobación, no una clasificación nueva de los árboles.

Se cambió únicamente `resources/views/risk/decision.blade.php`: título y explicación específicos para cada estado, y enlace Revisar condiciones de la inspección sobre la misma visita, fuera del fieldset bloqueado. La persona debe corregir las selecciones según lo observado; no se retiraron condiciones ni se relajaron reglas. Android conserva 0.3.3: su controlador ya distingue preview válido sin prioridad de inconsistencia.

Veinte comprobaciones temporales de render en transacción PostgreSQL READ ONLY, revertida, cubrieron ausencia de datos, incompatibilidad, sugerencia válida, versión obsoleta y ausencia de visita. CUA comprobó presentación legible, error específico, enlace y controles habilitados/bloqueados en vistas locales. HTTPS autenticado verificó INEM-000001 bloqueado con su par y enlace exacto, e INEM-000002 con controles habilitados. Catorce tablas de negocio/permisos conservaron sus huellas y la sesión propia se cerró y rechazó después. Probes temporales retirados; evidencia en `.risk-label-work/` y diagnóstico en `.risk-no-suggestion-work/production-diagnosis.json`.

Publicado con imagen `sha256:aaf4627f98cc4a8cad87ebb7f9b8163484c48bef3776bfd39edb30768f3842fe`, siete servicios saludables, diecisiete archivos de ejecución restantes y treinta y siete recursos/descargas intactos, configuración privada idéntica. Respaldo puntual `/opt/sysforest/backups/risk-label-20261105T043135Z` y tag `sysforest-app:before-risk-label-20261105t043135z`; se conserva el respaldo de datos anterior `/opt/sysforest/backups/risk-module-20261105T041104Z/data/backup-20261105T041116Z-8xysun`. Este ajuste visual no ejecutó migraciones, seeders ni escrituras forestales y no generó otra APK.

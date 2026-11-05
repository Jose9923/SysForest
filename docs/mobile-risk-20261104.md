# Continuidad Android y confirmación móvil de riesgo · 04/11/2026

La app 0.3.2+7 corrige el retorno al foco de una medición anterior al cerrar un selector y permite registrar la decisión profesional después de consultar la sugerencia. El cálculo sigue en el servidor; la app no contiene fórmulas forestales. La revisión de una visita existente usa su identidad y versión, conservando historial.

## Contrato y compatibilidad

`POST /api/mobile/v1/risk/preview` autenticado recibe phytosanitary_condition_ids y, según contexto, tree_id o inspection_id+lock_version. Sin IDs autoriza alta inicial; árbol existente autoriza nueva visita; inspección existente autoriza corrección y versión. Rechaza institución ajena, referencias no permitidas y propiedades desconocidas. Devuelve `{data: resultado}` con metodología institucional. Prepara la versión inicial únicamente si no existe, igual que el formulario web, sin reemplazar configuraciones vigentes.

Las escrituras móviles admiten opcionalmente los tres campos risk_priority, risk_confirmed y risk_methodology_id. Si aparece cualquiera, exigen decisión completa, metodología vigente, confirmación explícita y observación cuando difiere de la sugerencia o falta esta. Exigen risk.view además del permiso correspondiente, también al reautorizar un replay. Las capturas anteriores sin esos campos conservan su contrato y evaluación pendiente. El origen sigue siendo mobile y el autor/fecha proceden del servidor. No cambia el estado del árbol ni las reglas científicas.

La consulta es explícita; guardar no sincroniza. La app conserva datos y peticiones inciertas. Las revisiones existentes encolan PATCH con operation_id y lock_version; no crean una segunda visita. Las capacidades y proyección GET incorporan soporte móvil conservando claves anteriores. No hay migraciones, cambios de roles ni alteración de recibos históricos.

## Evidencia local

50 comprobaciones backend temporales aprobadas con rollback verificado: ruta HTTP JWT real, falta de autenticación, campos desconocidos, tenant, permisos y revocación en replay, creación inline/nueva visita/PATCH/replays, compatibilidad legacy, referencias históricas, contradicciones, observación, metodología y versión obsoletas. PSR-12, sintaxis de 11 archivos PHP y git diff --check aprobados.

Cuatro escenarios Flutter Android de continuidad reprodujeron el fallo y comprobaron desplazamiento estable, teclado y selecciones conservadas. Diez escenarios del widget/modelo y revisión de riesgo comprobaron decisión explícita, invalidación, offline, justificación y respuestas obsoletas. Revisión visual de PNG real aprobada. No hubo ejecución en teléfono físico.

La compilación, publicación y comprobación HTTPS finalizaron; la evidencia adicional se registra a continuación.

## Cola, publicación y comprobación real

Siete grupos de escenarios ejecutaron AppController/LocalStore reales con SQLite temporal y transporte simulado en memoria. Se comprobó operación nueva, edición no intentada con UUID idéntico, rechazo de duplicado/árbol ajeno, inmutabilidad de retry congelado, reemplazo trazable de 422 y conflicto de versión 409, y PATCH 503 seguido de replay 200 con cuerpo, ruta y UUID idénticos. Quedó una sola visita local y el recibo confirmado. No se contactó producción en estas pruebas ni se modificaron dependencias del proyecto. La revisión independiente corrigió preservación de justificación local y reautorización de inspections.create en replay inline. Sin hallazgos pendientes.

Backend publicado con delta de 11 archivos e imagen `sha256:fb6b23fb131aa22cd0738662d6c127c478f7fbec10c18347731e12f73b8ff258`. Siete servicios saludables, fuentes verificadas y configuración privada idéntica. Los 35 archivos previos de vendor/descargas se conservaron antes de publicar la APK adicional. Las comprobaciones HTTPS autenticadas de capacidades y sugerencias reutilizaron datos existentes; no registraron decisiones, árboles ni visitas productivas. La sesión propia se cerró.

Respaldo previo `/opt/sysforest/backups/mobile-risk-20261105T034027Z`, con datos online en `/opt/sysforest/backups/mobile-risk-20261105T034027Z/data/backup-20261105T034038Z-IUCtvG`, fuente e imagen anterior verificados y tag `sysforest-app:before-mobile-risk-20261105t034027z`. El respaldo de componentes online no es una instantánea global coordinada. No hubo migraciones, seeders ni cambios de roles.

Android [0.3.2+7](https://sysforest.varcor.tech/descargas/SysForest-Mobile-0.3.2.apk) publicado y verificado por HTTPS. Paquete `co.sysforest.mobile`, misma firma, variante debug universal, 168,881,729 bytes y SHA-256 `af52e40bf478ac5822c002a609aeb966f8f1b9d7f6dc07c34924a1ce3fc305ce`. Formato de 25 archivos, análisis sin incidencias, compilación y fuente/config/lock congelados aprobados. La instalación es sobre la versión anterior; conserva datos de la cuenta. Después se actualizan capacidades mediante Sincronizar ahora. Prueba física de teléfono e iOS pendientes; no publicación en tiendas ni suites permanentes añadidas.

# Fase 7 — Inspecciones forestales

Entrega para revisión del usuario. La Fase 6 se cerró en `89911d8` con el mensaje exacto `feat: complete phase 6 tree and geospatial domain`. Los cambios de Fase 7 permanecen **sin commit final** en `codex/phase-7`. No se inició Fase 8.

Proyecto definitivo: `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Petición aprobada SHA-256: 131ed1f52accc5faf5c0ffd79e4682bae87fa0d2fbc2a6648b7425cfe64a69fc.

El checkpoint verificó que `.phase6-work/` no existía dentro del proyecto; `/.phase*-work/` ya estaba ignorado. No se borraron archivos. `docker compose up -d` terminó con salida 0, las 546 pruebas aprobaron 2.484 aserciones y PSR-12 aprobó 297 archivos antes del commit. Después se creó la rama nueva.

## A. Tablas nuevas

`tree_inspections`, `inspection_conditions`, `inspection_fauna`, `inspection_revisions`. Ninguna medición, condición, fenología, fauna ni observación de visita se añadió a trees. Los catálogos nacionales se reutilizan.

## B. Migraciones

- `2026_09_12_000012_create_tree_inspection_tables.php`: cuatro tablas, precisiones, índices, FK compuestas, identidad inmutable, revisiones append-only y prohibición de hard delete.
- `2026_09_12_000013_guard_inspection_catalog_selections.php`: disponibilidad de condiciones/categorías/fauna al insertar relaciones, bloqueo compartido y prohibición de reescribir los pivotes.

Cada migración se aplicó, revirtió con `--step=1` y reaplicó exclusivamente en `sysforest_testing`. Después se aplicaron ambas a la base operativa mediante `docker compose run --rm --no-deps init`; la salida terminó en 0. No se ejecutó rollback operativo ni migrate:fresh.

## C. TreeInspection

UUID, organización, árbol, participación opcional de inventario, fecha observada, autores, lock_version, medidas y resultados, versión de reglas, fenología, fauna textual y observaciones generales. Timestamps con microsegundos. Identidad, Tree, organización, created_by/created_at y versiones son inmutables por trigger. Guarded rechaza asignación masiva abierta.

## D. Relaciones

Organization → TreeInspection; Tree → inspecciones; InventoryTree → inspecciones; inspección ↔ Conditions y FaunaCategories; inspección → MaturityState, ClassificationRuleVersion, creator/updater y revisiones. La FK `(organization_id,tree_id,inventory_tree_id)` exige una participación del mismo Tree y tenant. Los hijos apuntan mediante `(organization_id,inspection_id)`. Se añadió un UNIQUE triple a inventory_trees.

## E. Campos dasométricos

Entradas: cap_cm, number_of_stems, form_factor, total_height_m, commercial_height_m, crown_x_m, crown_y_m. Derivados: dap_m, radius_m, basal_area_m2, commercial_volume_m3, total_volume_m3, diameter_class_number, height_class_number, maturity_state_id. Se conserva la unidad del instrumento. La antigua propuesta `stem_count` de la matriz M se concretó como `number_of_stems` por la petición F7 §12, sin duplicar campos.

## F. NUMERIC y precisiones

| Campos | PostgreSQL |
| --- | --- |
| CAP, alturas, copa X/Y | NUMERIC(18,6) |
| Factor de forma | NUMERIC(7,6), rango [0.45,0.70] |
| DAP y radio | NUMERIC(24,12) |
| Área basal | NUMERIC(36,12) |
| Volúmenes comercial y total | NUMERIC(48,12) |
| Fustes y números de clase | BIGINT positivo cuando se informa/calcula |

BCMath utiliza PI con 50 decimales y escala interna 40. La normalización de derivados a 12 decimales ocurre al final, con redondeo documentado; las clases no usan el DAP redondeado. Los límites de representación son técnicos, no máximos biológicos inventados. Datos desconocidos permanecen NULL; no se exige llenar mediciones opcionales.

## G. Fórmulas implementadas

`DAP_m = CAP_cm / 100 / PI`; `radius_m = DAP_m / 2`; `basal_area_m2 = PI × radius_m²`; volumen comercial = área × altura comercial × factor; volumen total = área × altura total × factor. Nunca se multiplican por number_of_stems.

Control CAP=154 cm, factor=0.5, total=12 m, comercial=0.8 m: DAP **0.490197224723**, área **0.188725931518**, volumen comercial **0.075490372607**, total **1.132355589110**. Coinciden con los aproximados solicitados dentro de la tolerancia explícita. La captura comercial 0 produce 0 solo si se conocen las demás dependencias.

## H. Servicio de cálculo

DendrometryCalculationService valida entradas, resuelve versión, usa DendrometryArithmetic y ClassificationService, y retorna diez atributos derivados/versionados. DendrometryInput concentra el contrato numérico. TreeInspectionService siempre recalcula; DendrometryPreviewService utiliza el mismo cálculo para ayuda visual. No hay fórmulas en Controller, Blade o JavaScript.

## I. Clase diamétrica

`ceil(DAP_cm/5)`, intervalo superior incluido. Para CAP se clasifica el cociente CAP/PI sin redondear DAP. Pruebas de 5 exacto→1, apenas mayor→2, 10→2 y apenas mayor→3, además de CAP cerca de las fronteras. La API de clasificación decimal F5 permanece compatible.

## J. Clase de altura

`ceil(total_height_m/10)` para medidas positivas: 10→1, apenas mayor→2 y 20→2. No se utilizan las bandas del mockup.

## K. Madurez

Versión EXCEL_V1 aprobada: MAT_YOUNG clases 1–3, MAT_MEDIUM 4–6, MAT_MATURE 7–9, MAT_OVERMATURE desde 10. No se inventaron equivalencias con Juvenil/Adulto/Senescente. Se comprobaron los siete límites solicitados.

## L. Versiones de reglas

classification_rule_version_id identifica la regla sellada; calculation_rule_version conserva DENDROMETRY_V1. Una corrección usa la versión original incluso inactiva; una captura nueva requiere EXCEL_V1 activa y sellada. El trigger impide sustituir versiones. Si un registro requiere un algoritmo histórico no soportado, se rechaza la corrección explícitamente en vez de reinterpretarlo.

## M. Condiciones físico-mecánicas

Las **23** existentes, agrupadas por sistema radicular (4), fuste/estípite (11), ramas (5) y copa (3). Selección mediante UUID estable y checkboxes agrupados.

## N. Condiciones fitosanitarias

Las **42** existentes: sistema radicular (3), fuste (14), ramas (4), follaje (9), flores/frutos (6), general (6). CE/CF no se convirtieron en condiciones. Sano no borra automáticamente otras observaciones.

## O. InspectionConditions

Pivote normalizado con organization_id, inspection_id, condition_id y created_at. Clave compuesta impide duplicados; FK impide referencias inexistentes/cruzadas. Las referencias retenidas conservan su fecha. Nuevas selecciones exigen condición y categoría activas; una histórica inactiva continúa visible y preservable. Los repositorios y triggers usan FOR SHARE para evitar que la disponibilidad cambie durante el guardado. Los filtros históricos sí ofrecen condiciones desactivadas.

## P. Fenología

Floración y fructificación separadas: flowering_date/flowering_development y fruiting_date/fruiting_development. Cuatro nullable en la inspección representan las dos variables compuestas CE/CF; no se fuerza una tabla adicional sin evidencia de múltiples episodios. Se conservan texto, espacios, saltos y literal «0». No hay catálogo inventado de estados ni porcentajes ni restricciones temporales sin respaldo.

## Q. Fauna

Múltiples categorías mediante inspection_fauna: Aves, Mamíferos, Reptiles, Insectos, Quirópteros y Otros. fauna_observations describe la fauna de la visita, incluso sin categoría. Sin taxonomía inventada ni fotografía de fauna en esta fase.

## R. Observaciones

general_observations corresponde a CP y queda separada de ubicación permanente, notas administrativas y mantenimientos. La captura mantiene el texto original y Blade lo escapa.

## S. latestInspection

Orden: inspection_date DESC, created_at DESC, id DESC. UUID es solo desempate final; updated_at no altera el orden. TreeInspectionRepository resuelve latestForTree/latestForTrees y prepara latestInspection por lotes. Tree muestra la última visita y la línea de tiempo sin copiar resultados a trees.

## T. Controllers

TreeInspectionController y DendrometryPreviewController: coordinan Requests, Services, Blade/JSON y redirects. Sin consultas ni reglas forestales.

## U. Services

TreeInspectionService, TreeInspectionReadService, DendrometryCalculationService, DendrometryPreviewService, InspectionConditionService, InspectionFaunaService, PhenologyService e InspectionAuditService. Se reutilizan y ajustan ForestryAccessService, ClassificationService y TreeReadService, manteniendo responsabilidades separadas.

## V. Repositories

TreeInspectionRepository, InspectionConditionRepository, InspectionFaunaRepository, InspectionRevisionRepository e InspectionReferenceRepository. Reutilización de repositorios de Tree, Inventory, User, reglas y eventos de seguridad.

## W. Requests

StoreTreeInspectionRequest, UpdateTreeInspectionRequest, TreeInspectionFilterRequest y DendrometryPreviewRequest; DendrometryInput y TreeInspectionInput comparten validación con Services. Referencias de tenant se resuelven/autorizan antes de validar contenido. Los derivados enviados por cliente se ignoran y se recalculan. Desmarcar todos los checkboxes limpia sus relaciones sin JavaScript.

## X. Policies

TreeInspectionPolicy, registrada explícitamente. Permisos inspections.view/create/update: **31 permisos totales**. Official y OrganizationAdmin escriben en su organización; DepartmentAdmin solo lee dentro de su departamento; SuperAdmin requiere tenant seleccionado sin membresía falsa. Tree anulado no admite nuevas inspecciones/correcciones; su historia sigue la autorización de lectura del Tree.

## Y. Blade

Index, form, show y parciales de siete secciones, condiciones, resultados y resumen del árbol. Select2 en relaciones/filtros; outputs «Calculado automáticamente»; textos escapados; control de sesión y descarte de previsualizaciones antiguas. Pruebas de vistas con carga perezosa prohibida y XSS, 65 casillas, catálogos vacíos, historia inactiva y conflicto 409.

Revisión visual con fixtures HTTP sanitizados, sin cookies/CSRF exportados ni acciones ejecutables: **390×844 y 1440×1000**, formulario, ficha y bandeja. Se verificó selección «Anclaje bueno» y contador «1 registrada». Se corrigieron números partidos en móvil mediante una columna y fecha partida en escritorio mediante nowrap. La revisión visual se distingue de las pruebas funcionales de guardado y previsualización mediante HTTP real.

## Z. Flujo inicial

Registrar Tree → guardar identidad/georreferenciación → «Nueva inspección» desde la ficha. No hay acoplamiento circular ni duplicación de datos. inventory_tree_id puede ser NULL; se aceptan participaciones existentes incluso en campañas cerradas para visitas retrospectivas, sin abrirlas ni crear nuevas participaciones implícitas.

## AA. Filtros

Tree, Inventory, fecha desde/hasta, especie, madurez, clases, condiciones físicas/fitosanitarias y rangos CAP/DAP/altura. Se permiten intervalos con un solo extremo; se rechazan intervalos invertidos. ANY usa EXISTS y ALL cuenta las condiciones dentro del mismo inspection_id, sin duplicar árboles.

La bandeja es histórica. El backend latestTrees selecciona primero la última visita y después filtra; no combina condiciones de visitas diferentes. En esa consulta inventory_id mantiene participación del Tree; en inspecciones identifica la campaña propia de la visita. Todo está centralizado para reutilización; no se construyó un portal público o interfaz avanzada adicional.

## AB. Auditoría

inspection_revisions guarda old/new JSONB por contexto, mediciones, cálculos, condiciones, fenología, fauna y observaciones; actor, fecha, evento y versión. Una revisión por versión, append-only. security_events registra además inspection.created/updated con UUID de recurso, actor y tenant. Un fallo en auditoría revierte la visita completa o su corrección.

## AC. Optimistic locking

lock_version se valida antes del cast y se incrementa una vez por operación. Versión obsoleta: HTTP409 y «Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.» Se probaron versiones inválidas, conservación de primera escritura y ausencia de nuevas revisiones al rechazar un conflicto.

## AD. Aislamiento tenant

Pruebas HTTP demuestran que Official B no crea inspección para Tree A ni consulta/corrige su inspección aunque conozca UUID. PostgreSQL impide cruce de organizaciones y participación de otro Tree de la misma organización. Se probaron los cuatro roles, falta de contexto, alcance departamental ajeno, revocación de membresía, autor original inmutable y árboles anulados.

## AE. Tests nuevos

**206 nuevos casos y 723 nuevas aserciones**, en diez archivos de pruebas y un fixture base, manteniendo intactos los archivos anteriores.

| Archivo | Casos | Aserciones en suite completa |
| --- | ---: | ---: |
| Feature/DendrometryCalculationTest.php | 4 | 21 |
| Feature/InspectionAuthorizationTest.php | 11 | 62 |
| Feature/InspectionPresentationTest.php | 10 | 117 |
| Feature/TreeInspectionDomainTest.php | 25 | 104 |
| Feature/TreeInspectionHttpSecurityTest.php | 25 | 87 |
| Feature/TreeInspectionRepositoryTest.php | 45 | 156 |
| Unit/DecimalRatioClassificationTest.php | 20 | 30 |
| Unit/DendrometryArithmeticTest.php | 8 | 34 |
| Unit/DendrometryCalculationServiceTest.php | 17 | 66 |
| Unit/DendrometryInputTest.php | 41 | 46 |
| Total nuevo | 206 | 723 |

La revisión visual optativa añadió seis aserciones en su corrida focal (10/123), que no se suman artificialmente al cierre global. Las pruebas se ejecutaron serialmente en sysforest_testing; las concurrencias específicas existentes se coordinan dentro de sus propios tests.

## AF. Total de pruebas y aserciones

**752 PASS, 3.207 aserciones, 0 fallos, 0 errores, 0 omitidas. Salida 0.** JUnit: **246,391908 segundos**. El desglose de los archivos existentes confirma **546 pruebas/2.484 aserciones**; los 206/723 nuevos completan el total. `git diff --exit-code 89911d8 -- tests` confirmó que los archivos previos siguen intactos.

~~~bash
docker compose exec -T app php artisan test --compact --log-junit /tmp/sysforest-phase7-final.xml
~~~

## AG. PSR-12

`composer format` y `docker compose exec -T app composer lint`: **338 archivos PHP PASS**, salida 0. Comentarios útiles y PHPDoc en español; identificadores técnicos en inglés. No se añadieron dependencias ni se modificó Composer/JWT.

## AH. Matriz de 94 campos

**92/94 implementados**, dos pendientes. La matriz mantiene exactamente 94 variables y las siete marcas de fórmula del libro; W continúa no derivada en el ejemplo fuente aunque ahora se automatice su regla aprobada. Tree cubre A:H; inspecciones cubren I:CM y CP, incluidos los dos fenómenos compuestos CE/CF.

## AI. Campos pendientes

**CN: FOTO FAUNA y CO: FOTO INDIVIDUO**, exclusivamente evidencias de Fase 8. No se guardaron rutas ficticias ni fotografías. La incertidumbre sobre estados fenológicos discretos no oculta una captura implementada: fecha/texto disponibles se conservan sin inventar su significado.

## AJ. Docker Compose y entorno

`docker compose up -d` estándar terminó con salida 0. Estructura y puertos Docker sin cambios: Nginx publica **127.0.0.1:8080** y solo public; Mailpit **127.0.0.1:8025**. FPM9000, PostgreSQL5432 y Redis6379 permanecen internos.

~~~text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED        STATUS                     PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         3 hours ago    Up 3 hours (healthy)       9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     33 hours ago   Exited (0) 9 minutes ago   
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        3 hours ago    Exited (0) 9 minutes ago   
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     4 hours ago    Up 4 hours (healthy)       127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       33 hours ago   Up 8 hours (healthy)       127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    33 hours ago   Up 8 hours (healthy)       5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       3 hours ago    Up 44 minutes (healthy)    9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       33 hours ago   Up 8 hours (healthy)       6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   3 hours ago    Up 3 hours (healthy)       9000/tcp
~~~

Siete servicios saludables, dos inicializadores Exited(0). PHP **8.4.25**, Laravel **13.31.0**, PostgreSQL **17.5**, PostGIS **3.5.2**. Redis **PONG** y conexión desde Laravel aprobada; `/health/ready` devolvió `{"status":"ok"}`. Worker procesó InfrastructureProbeJob a **21:37:29 UTC** (DONE); scheduler ejecutó su tarea minutely a **21:37:00 UTC** (DONE), confirmada por schedule:list.

Verificación HTTP operativa con las cuatro cuentas locales: login/selección/logout PASS, /inspections y filtro solo fecha-hasta 200 para todos; preview200 para SuperAdmin/OrganizationAdmin/Official y 403 para DepartmentAdmin. Se mantuvieron **0 árboles y 0 inspecciones operativas**. Las credenciales y cookies permanecieron en memoria; la revisión de capturas usó únicamente fixtures saneados de testing.

## AK. Git status

Rama **codex/phase-7**, HEAD **89911d8**. Sin commit final de Fase 7, sin merge ni publicación. **75 archivos**: **56 nuevos y 19 modificados**. El estado por archivo se conserva en [phase-7-files.txt](phase-7-files.txt). Temporales de generación/pruebas están fuera del proyecto definitivo y no se versionaron.

## AL. Archivos creados/modificados

[Inventario completo](phase-7-files.txt). Incluye dos migraciones, cuatro modelos nuevos y dos relaciones de modelos previos; dos controllers, cuatro Requests, Policy, validadores, aritmética decimal, servicios, repositorios, vistas/estilos/JS, diez archivos de pruebas y fixture. Documentación nueva: phase-7, inspections, dendrometry. Actualizaciones: matriz94, tree-domain, forestry-catalogs, README, AGENTS/CLAUDE; permisos, provider, rutas, middleware de texto y navegación.

## AM. Deuda técnica e incidencias resueltas

- Se conserva exactamente `php-open-source-saver/jwt-auth:2.9.3` y la advertencia conocida del pin estricto. Fuera del alcance solicitado; composer.json/lock permanecen intactos.
- Multifuste: se guarda el número de fustes, pero no se inventa un CAP por fuste ni un multiplicador de volúmenes. Requiere decisión forestal futura.
- Se corrigieron fixtures de activación por UUID y creación con campaña, un callback Eloquent tipado demasiado estrechamente y una comparación de orden JSONB en pruebas nuevas; la suite global posterior pasó.
- Se corrigieron la lectura de catálogos históricos en filtros, extremos vacíos de filtros directos, textos «0», saltos de números/fechas en UI y la carrera entre validación de disponibilidad y guardado mediante FOR SHARE.
- El contenedor mínimo no incorpora `ps`; la salud y ejecución de worker/scheduler se verificó con sus healthchecks y logs existentes. No se instaló software adicional.
- Se mantienen las deudas de Fase 6 sobre datums desconocidos, cartografía externa y alcance taxonómico de ejemplos; no se alteraron en esta fase.

## AN. Decisiones pendientes y límite de fase

Definición de medición individual multifuste; estados discretos y multiplicidad de episodios fenológicos; significado de blancos/no evaluado y exclusiones/umbrales no respaldados; eventual anulación completa de una inspección. Se conserva la incertidumbre de origen biogeográfico/establecimiento documentada previamente. No se atribuyeron significados nuevos a los archivos fuente.

**La Fase 7 queda lista para revisión. No se continuará a Fase 8 sin aprobación.**

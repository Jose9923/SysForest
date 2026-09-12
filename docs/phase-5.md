# SYSFOREST · Cierre de Fase 5

Fecha: 12 de septiembre de 2026. Proyecto definitivo: `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Entrega: catálogos forestales nacionales. **La Fase 6 no se inició.**

El checkpoint previo verificó las **203 pruebas / 922 aserciones** y PSR-12 sobre **166 archivos**. `.phase4-work/` no existía dentro del proyecto definitivo; no se encontró dependencia en app/config/routes/resources/composer/Dockerfile y los patrones temporales ya estaban ignorados. No se eliminó contenido sin inspección. Se ejecutó `docker compose up -d` correctamente y se creó el commit autorizado **`652070e`**, `feat: complete phase 4 authentication and authorization`. Después se abrió **`codex/phase-5`**. No hay commit de Fase 5.

## A. Tablas nuevas

**16 tablas**, todas sin `organization_id`:

| Tabla | Filas iniciales | Responsabilidad |
| --- | ---: | --- |
| placements | 6 | Emplazamientos ampliables nacionalmente. |
| establishment_origins | 2 | Espontánea / Plantada. |
| biogeographic_origins | 4 | Nativa, exótica, naturalizada e invasora, conservando etiquetas Excel. |
| growth_habits | 11 | Diez valores Excel y Herbácea literal del HTML. |
| maturity_states | 4 | Los cuatro estados Fustal del Excel. |
| families | 8 | Familias taxonómicas de ejemplo respaldadas. |
| species | 10 | Especies con familia obligatoria. |
| species_common_names | 10 | Admite varios nombres por especie, máximo uno principal. |
| condition_categories | 10 | Tipo de evaluación y órgano. |
| conditions | 65 | Condiciones normalizadas, sin columna booleana por condición. |
| evidence_types | 12 | Catálogo de las futuras fotografías. |
| maintenance_types | 11 | Catálogo de intervención. |
| deletion_reasons | 7 | Motivos para registros creados por error. |
| fauna_categories | 6 | Grupos expresamente aprobados. |
| classification_rule_versions | 1 | EXCEL_V1, parámetros decimales sellados. |
| maturity_rule_bands | 4 | Bandas 1–3, 4–6, 7–9 y 10 en adelante. |

Las filas iniciales suman 171; no representan un inventario de árboles.

## B. Migraciones y rollback

En `database/migrations/`:

- `2026_09_12_000006_create_national_forestry_catalogs.php`.
- `2026_09_12_000007_create_taxonomy_tables.php`.
- `2026_09_12_000008_create_condition_catalog_tables.php`.
- `2026_09_12_000009_create_classification_rule_tables.php`.

UUID, códigos únicos, CHECK de forma/campos/orden, índices por estado/orden, FK con borrado restringido, unicidad taxonómica sin diferencias de mayúsculas, un principal por especie y FK compuesta `(condition_category_id, assessment_type)`. Triggers conservan códigos; reglas selladas impiden cambiar parámetros o bandas, añadir bandas, retirar sellado o borrar la versión. El sellado comprueba cobertura desde 1 sin huecos ni superposiciones y última banda abierta.

Se ejecutó primero, exclusivamente en **sysforest_testing**:

~~~bash
docker compose run --rm --no-deps --env DB_DATABASE=sysforest_testing init php artisan migrate --force --no-interaction
docker compose run --rm --no-deps --env DB_DATABASE=sysforest_testing init php artisan migrate:rollback --step=4 --force --no-interaction
~~~

Las cuatro migraciones y sus cuatro rollback terminaron **DONE / salida 0**. Se verificó antes que eran las cuatro recién aplicadas. La base operativa no se revirtió. Se restauró test y aplicó el esquema operativo con:

~~~bash
docker compose run --rm --no-deps init
docker compose exec -T app php artisan db:seed --force --no-interaction
~~~

Ambas bases migraron y todos los seeders finalizaron con salida 0. No reutilizar `--step=4` sin comprobar el estado de migraciones en otra ejecución.

## C. Models

16 modelos concretos: Placement, EstablishmentOrigin, BiogeographicOrigin, GrowthHabit, MaturityState, EvidenceType, MaintenanceType, DeletionReason, FaunaCategory, Family, Species, SpeciesCommonName, ConditionCategory, Condition, ClassificationRuleVersion y MaturityRuleBand. CatalogEntry es la base abstracta de los nueve catálogos simples. No se implementaron Tree, TreeInspection ni entidades operativas posteriores.

## D. Catálogos implementados

Emplazamientos, establecimiento, biogeografía, hábitos, taxonomía, madurez, condiciones por órgano/tipo, evidencia, mantenimiento, motivos, fauna y reglas de clases diamétricas/altura. Los códigos están en [la matriz de catálogos](forestry-catalogs.md). Los valores de taxonomía y emplazamiento son iniciales y ampliables; no se presentan como listas nacionales exhaustivas o una validación taxonómica externa.

## E. Condiciones físico-mecánicas

**23**, correspondientes a X:AT: sistema radicular 4, fuste/estípite 11, ramas 5 y copa 3.

## F. Condiciones fitosanitarias

**42**, correspondientes a las condiciones discretas de AU:CL: sistema radicular 3, fuste/estípite 14, ramas 4, follaje 9, flores/frutos 6 y estado general 6. El bloque completo tiene **44 variables**, pero **CE y CF** son campos compuestos de fenología, no dos condiciones adicionales.

## G. Categorías

Cuatro físico-mecánicas: Sistema radicular, Fuste o estípite, Ramas, Copa. Seis fitosanitarias: Sistema radicular, Fuste o estípite, Ramas, Follaje, Flores y frutos, Estado fitosanitario general del árbol. El nombre repetido en otro órgano mantiene otro código/UUID.

## H. Taxonomía inicial

Ocho familias, diez especies y diez nombres comunes de ejemplo. El Oití conserva **Licania tomentosa (Benth.) Fritsch.**, texto del Excel; no se duplica por la forma abreviada del HTML ni se afirma una sinonimia externa. Family → Species → SpeciesCommonName utiliza FK reales. La administración puede añadir nombres comunes; solo uno por especie puede ser principal. Una referencia inactiva se conserva al editar registros históricos, pero no se ofrece como una nueva selección normal.

## I. Clases diamétricas

`ceil(DAP_cm / 5)` para positivos, centralizada en ClassificationService/DecimalClassification. No hay un número máximo arbitrario de filas sembradas. Un DTO ClassResult devuelve `class_number`, `min_value` exclusivo, `max_value` inclusivo, unidad, etiqueta y `rule_version_id`. Ejemplos comprobados: 5 → 1; 5.000001 → 2; 10 → 2; 10.000001 → 3.

## J. Clases de altura y decimales

`ceil(height_m / 10)` para positivos: 10 → 1; 10.000001 → 2. PostgreSQL usa **DECIMAL(18,6)** y el cálculo opera con cadenas/BCMath. Se admiten hasta 12 enteros y 6 decimales; el límite es de representación técnica, no biológico. Se rechazan cero, negativos, notación científica, entradas inválidas y exceso de precisión en vez de redondearlas silenciosamente. `ext-bcmath` quedó declarada en Composer, sin cambiar versiones de paquetes.

## K. Madurez y versión

Fustal joven (1–3), Fustal medio (4–6), Fustal maduro (7–9), Fustal sobremaduro (10 en adelante). Los parámetros/bandas de EXCEL_V1 son inmutables; nombre, descripción, disponibilidad y orden son administrables. Una nueva fórmula requiere otra versión creada explícitamente por backend/migración de datos: insertar versión sin sellar, insertar bandas y sellar en una misma transacción. Esta fase no publica un editor arbitrario de fórmulas. Las inspecciones futuras conservarán versión y resultados; una versión inactiva puede reproducirse por ID. No se automatizaron inspecciones ni se inventaron equivalencias con Juvenil/Adulto/Senescente.

## L. Evidence types

12: Individuo completo, Sistema radicular, Fuste, Ramas, Copa, Follaje, Flores/frutos, Afectación fitosanitaria, Fauna, Mantenimiento antes, Mantenimiento después, Otro. La futura fotografía tendrá exactamente uno; no se implementó carga de archivos.

## M. Maintenance types

11: Poda, Tala, Tratamiento fitosanitario, Aplicación de herbicida, Fertilización, Riego, Control de plagas, Manejo de raíces, Traslado, Inspección técnica, Otro. No hay órdenes operativas.

## N. Deletion reasons

7: Registro duplicado, Árbol inexistente, Error de ubicación, Error de identificación, Registro de prueba, Creación accidental, Otro. No hay borrado de árboles en esta fase.

## O. Fauna categories

6: Aves, Mamíferos, Reptiles, Insectos, Quirópteros, Otros. No se añadieron taxones ni subcategorías no respaldadas.

## P. Controllers

CatalogController, TaxonomyController, ConditionCatalogController y ClassificationCatalogController. Coordinan Requests, Services, Blade y redirects. Hay 20 patrones de rutas bajo `/admin/catalogs`; ninguna ruta DELETE.

## Q. Services

CatalogService, CatalogAccessService, TaxonomyService, ConditionCatalogService, ClassificationService y ClassificationCatalogService. Gestionan autorización, relaciones, reglas, transacciones y auditoría. PermissionService se amplió para lectura del catálogo desde cualquier asignación vigente. DecimalClassification concentra la aritmética exacta, sin consultas. Los filtros de estado normalizan representaciones HTTP y valores tipados equivalentes.

## R. Repositories

CatalogRepository comparte consultas de nueve modelos del registro cerrado CatalogRegistry; no acepta nombres de tablas HTTP ni contiene un switch general de tablas. TaxonomyRepository, ConditionCatalogRepository y ClassificationRuleRepository son específicos de sus dominios. RoleAssignmentRepository y SecurityEventRepository integran lectura/autorización/auditoría. Blade no contiene consultas.

## S. Requests y validación

CatalogEntryRequest, CatalogFilterRequest, TaxonomyStoreRequest, TaxonomyUpdateRequest, ConditionCatalogStoreRequest, ConditionCatalogUpdateRequest, ClassificationMetadataRequest y ClassificationPreviewRequest. Se validan códigos, nombres, relaciones, UUID, unicidad, valores numéricos, rangos, orden y estado. Los Services revalidan entradas y reglas antes de escribir, también en llamadas directas.

## T. Policies y permisos

Se integraron Gates `catalogs.view` y `catalogs.manage` con los permisos persistidos de Fase 4: **20 permisos totales**. SUPER_ADMIN administra; los otros tres roles consultan mediante asignaciones vigentes. JWT, sesión y correo verificado siguen siendo obligatorios; no se requiere seleccionar tenant. No hay Gate::before universal.

Las mutaciones bloquean/revalidan al actor dentro de transacción y registran `catalog.created`, `catalog.updated`, `catalog.activated`, `catalog.deactivated` y `catalog.reordered` según la operación. La auditoría conserva actor y metadatos permitidos, con `organization_id = null`, incluso con organización seleccionada. No se almacenan formularios completos ni credenciales.

## U. Blade y verificación HTTP/visual

Panel de catálogos; lista y formulario genéricos; listas/formularios de taxonomía y condiciones; lista, detalle, cálculo de consulta y edición de metadatos de reglas. Componentes/partials, logo, verdes, filtros, paginación y diseño responsive. Select2 local busca familias, especies y categorías. JavaScript solo mejora la presentación; los límites, permisos y relaciones se validan en PHP.

Comprobación real posterior a la reconstrucción, mediante login/logout HTTP normal y credenciales locales solo en memoria:

| Rol | Panel, listas, reglas y consulta decimal | Formularios de creación | Intento de escritura |
| --- | --- | --- | --- |
| SUPER_ADMIN | 200 | 200 | Cubierto en sysforest_testing con CRUD real |
| DEPARTMENT_ADMIN | 200 | 403 | 403 |
| ORGANIZATION_ADMIN | 200 | 403 | 403 |
| OFFICIAL | 200 | 403 | 403 |

La revisión visual usó HTML obtenido de esas sesiones y sanitizado, sin cookies ni tokens exportados y con envío de formularios deshabilitado. Se revisaron panel de escritorio y formulario/listado/detalle a **390 × 844**: sin desbordamiento horizontal del documento. Select2 filtró FABACEAE al buscar FAB. Esta inspección de presentación no sustituye las pruebas HTTP reales anteriores. El servidor local temporal de revisión se cerró y no forma parte del proyecto.

## V. Seeders

ForestryCatalogSeeder, TaxonomySeeder, ConditionCatalogSeeder, EvidenceTypeSeeder, MaintenanceCatalogSeeder y ClassificationRuleSeeder; todos se ejecutan desde DatabaseSeeder después del catálogo de autorización. Los datos normalizados de taxonomía y condiciones están en `database/seeders/data/`. La aplicación no depende del Excel, del HTML ni de archivos de staging para arrancar.

La repetición de `db:seed` conserva UUID, códigos y ajustes administrativos. No se reactivan entradas ni se sobrescriben nombres/orden. EXCEL_V1 verifica que la definición existente coincida y rechaza sobrescribir otra fórmula histórica. No se añadió caché de catálogos: consultas pequeñas sin complejidad de invalidación prematura.

## W. Matriz Excel de 94 campos

[excel-field-mapping.md](excel-field-mapping.md) contiene exactamente A:CP, las 94 variables reales, encabezado/grupo/tipo/unidad/destino futuro/entidad/derivación/catálogo/código/observaciones/estado. Están trazadas las siete fórmulas y los 65 campos de condiciones. CE/CF documentan fecha y estimación del estado de desarrollo de floración/fructificación, sin estados discretos inventados ni TreePhenology. [forestry-catalogs.md](forestry-catalogs.md) conserva la matriz de códigos y procedencia. El Excel original no se modificó.

## X. Discrepancias resueltas y documentadas

- Excel tiene prioridad forestal; HTML aporta visual y ejemplos explícitos.
- 23 condiciones físicas/42 fitosanitarias frente a 22/24 checkboxes simplificados del HTML; no se mezclan órganos ni etiquetas repetidas.
- Diámetro cada 5 cm y altura cada 10 m frente a bandas manuales distintas del HTML.
- Cuatro estados Fustal frente a Juvenil/Adulto/Senescente; sin equivalencias automáticas.
- Origen mezcla establecimiento y biogeografía: se separaron dimensiones, sin asignar Introducido arbitrariamente.
- Emplazamientos: Anden del ejemplo se normaliza ortográficamente a Andén; seis valores respaldados, no lista exhaustiva.
- Hábitos: diez del Excel; Herbácea se conserva como aporte literal adicional del HTML, sin equipararla a Caña o pasto.
- Taxonomía Oití: se conserva autoría literal Excel, no dos especies por abreviación del mockup.
- Validaciones Excel rotas documentadas: #REF!, rangos sin valores/nombres inexistentes, hábito aplicado a Familia y madurez aplicada a clase de altura. No se reprodujeron.

## Y. Tests añadidos

**149 casos nuevos**, sin modificar ni retirar las 203 pruebas de Fase 4:

| Archivo | Casos | Aserciones |
| --- | ---: | ---: |
| Unit/DecimalClassificationTest.php | 30 | 69 |
| Feature/ClassificationRuleTest.php | 32 | 62 |
| Feature/ClassificationCatalogHttpTest.php | 14 | 68 |
| Feature/NationalCatalogTest.php | 49 | 199 |
| Feature/TaxonomyCatalogTest.php | 12 | 61 |
| Feature/ConditionCatalogTest.php | 12 | 57 |

Incluyen listas exactas, seed general repetido, relaciones/FK/CHECK, cuatro roles HTTP y Service, CSRF real, códigos estables, estado/orden, historia, auditoría transaccional, filtros, XSS, límites decimales, reglas inmutables y nueva versión sin modificar la anterior. La suite utiliza PostgreSQL/PostGIS real, transacciones y comprobación explícita de sysforest_testing; se ejecutó serialmente.

## Z. Total de pruebas y aserciones

**352 PASS, 1.438 aserciones, 0 fallos y 0 errores. Duración 35,31 segundos; salida 0.**

~~~bash
docker compose exec -T app php artisan test --compact --log-junit=/tmp/sysforest-phase5-tests.xml
~~~

Se conservaron los resultados JUnit antes de recrear el contenedor. Los resultados de comandos focalizados no se suman otra vez al total.

## AA. PSR-12 y Composer

`composer format` y `docker compose exec -T app composer lint`: **232 archivos PHP aprobados**, salida 0. Comentarios/PHPDoc útiles en español; identificadores técnicos en inglés.

`composer update --lock --no-install --no-scripts --no-interaction` registró ext-bcmath sin actualizar paquetes y no encontró avisos de vulnerabilidad. `composer validate --strict` confirmó JSON/lock válidos, pero devolvió **1 por una advertencia**: recomienda evitar la versión exacta `php-open-source-saver/jwt-auth:2.9.3`. Se conservó la versión fijada y aprobada en Fase 4; no es un error de instalación ni de pruebas. La reconstrucción desde lock terminó correctamente.

## AB. Docker Compose, versiones y procesos

La imagen **sysforest-app:local** se reconstruyó correctamente. Se reutilizó una configuración Docker temporal destinada solo a imágenes públicas para evitar el problema ya conocido del helper Windows en WSL; no se cambió la configuración global. **`docker compose up -d` estándar terminó con salida 0.**

Versiones comprobadas: **PHP 8.4.25, Laravel 13.31.0, PostgreSQL 17.5, PostGIS 3.5.2**. Redis respondió **PONG**. No se cambió la estructura Docker aprobada.

Resultado de `docker compose ps -a` después del arranque final:

~~~text
NAME                    SERVICE     STATUS                          PORTS
sysforest-app-1          app         Up About a minute (healthy)     9000/tcp
sysforest-db-init-1      db-init     Exited (0) About a minute ago
sysforest-init-1         init        Exited (0) About a minute ago
sysforest-mailpit-1      mailpit     Up About an hour (healthy)      127.0.0.1:8025->8025/tcp
sysforest-nginx-1        nginx       Up 5 hours (healthy)            127.0.0.1:8080->80/tcp
sysforest-postgres-1     postgres    Up 5 hours (healthy)            5432/tcp
sysforest-queue-1        queue       Up About a minute (healthy)     9000/tcp
sysforest-redis-1        redis       Up 5 hours (healthy)            6379/tcp
sysforest-scheduler-1    scheduler   Up About a minute (healthy)     9000/tcp
~~~

Siete servicios saludables y dos inicializadores completados. Solo aplicación **127.0.0.1:8080** y Mailpit **127.0.0.1:8025** se publican. PostgreSQL, Redis, PHP-FPM y SMTP permanecen internos; Nginx sirve exclusivamente public.

El worker `queue:work redis` procesó **InfrastructureProbeJob a las 19:01:56 UTC**, DONE. Scheduler `schedule:work` ejecutó `sysforest:probe-scheduler` a las **19:02:00 UTC**, DONE, con frecuencia cada minuto confirmada mediante `schedule:list`.

## AC. Git status

Rama **codex/phase-5**, HEAD **652070e**. Cambios de Fase 5 sin commit para revisión; no hubo merge ni publicación. El [inventario completo](phase-5-files.txt) reproduce el estado por archivo. `.env` y staging permanecen fuera del control de versiones. El directorio definitivo no contiene `.phase4-work/` ni `.phase5-work/`.

## AD. Archivos creados y modificados

[phase-5-files.txt](phase-5-files.txt) enumera todos los archivos nuevos/modificados: cuatro migraciones, modelos, servicios, repositorios, validación, controllers, Requests, rutas, vistas, Select2, seeders/datos, seis archivos de pruebas y documentación. README, AGENTS/CLAUDE y los contratos de arquitectura/base/autorización reflejan la fase actual. Los reportes de Fases 2–4 conservan su carácter histórico.

## AE. Incidencias y decisiones pendientes

- Corregidos durante validación: una directiva Blade adyacente a texto sin separación, un fixture que intentaba asignar un atributo User protegido, UUID malformados en servicios, anclaje estricto de códigos y normalización de filtros tipados. La suite final pasó sin regresiones.
- Advertencia de Composer estricto por el pin JWT aprobado, descrita en AA. No se relajó ni actualizó silenciosamente.
- El Excel no define captura simultánea/exclusividad de las dos dimensiones de origen. Introducido del HTML no se sembró en una dimensión arbitraria.
- No hay estados discretos de fenología verificables; se documentaron CE/CF para diseño posterior. Tampoco se infiere un catálogo exhaustivo de emplazamientos ni una revisión taxonómica externa de los ejemplos.
- Nuevas versiones de reglas requieren un cambio explícito de backend/datos. No existe editor libre de parámetros ni recálculo de registros operativos. La persistencia de versión/resultados se incorporará con inspecciones.
- La tabla de nombres comunes admite máximo uno principal, no obliga a que siempre exista uno; la UI exige desmarcar el anterior antes de elegir otro. Conserva referencias e IDs al desactivar.
- No se incorporó caché de catálogos ni se abrió alcance de Tree, TreeInspection, mantenimientos/evidencias operativas, mapas, importaciones, portal público o reportes finales.

**La Fase 5 queda lista para revisión. No continuar a Fase 6 sin aprobación del usuario.**

# Carbono aéreo almacenado

Implementación local del 25/10/2026. Se estima el stock de carbono en biomasa aérea seca y su masa correspondiente de CO₂. No se calcula una huella institucional de emisiones, captura anual, raíces, suelo, créditos ni compensaciones.

## Método y unidades

Chave et al. (2014), ecuación 4:

`AGB_kg = 0.0673 × (rho_g_cm3 × D_cm² × H_m)^0.976`

Se utiliza el DAP calculado por el motor dasométrico existente (`dap_m × 100`); equivale a CAP/π en centímetros. H es altura total, no comercial. rho es densidad básica: masa seca/volumen verde. No usar densidades aparentes medidas a otra humedad.

`carbon_kg = AGB_kg × 0.47`

`co2_kg = carbon_kg × 44 / 12`

La fracción 0,47 procede de IPCC 2006, volumen 4, capítulo 4, tabla 4.3. Las referencias están fijadas en `TreeCarbon::method()` y se copian a cada resultado. El modelo de biomasa procede de árboles tropicales con diámetro mínimo de 5 cm. Su uso urbano requiere validación local; no se declara universal ni una precisión individual certificada.

Ejemplo exclusivamente ilustrativo: DAP 30 cm, altura 10 m y densidad básica 0,6 g/cm³ producen aproximadamente 295,69 kg de biomasa aérea, 138,97 kg C y 509,57 kg CO₂ correspondiente. Esa densidad no se sembró para ninguna especie.

Fuentes primarias:

- [Chave et al., 2014, DOI](https://doi.org/10.1111/gcb.12629), [publicación del Smithsonian](https://stri-apps.si.edu/docs/publications/pdfs/Chave-et-al_2014_GlobChangeBio-new_biomass_equations.pdf).
- [IPCC 2006, capítulo 4, tabla 4.3](https://www.ipcc-nggip.iges.or.jp/public/2006gl/pdf/4_Volume4/V4_04_Ch4_Forest_Land.pdf#page=48).

## Configuración institucional

En **Carbono almacenado** (`/carbon`), SUPER_ADMIN u ORGANIZATION_ADMIN publican una referencia por especie dentro de la entidad seleccionada. OFFICIAL y DEPARTMENT_ADMIN consultan con `inspections.view`, conservando sus alcances; no publican ni ejecutan actualizaciones. `carbon.manage` es un permiso explícito y no permite modificar la taxonomía nacional.

La publicación manual exige especie activa, aplicabilidad, fuente bibliográfica de densidad, justificación profesional y aceptación de la revisión. La precarga bibliográfica autorizada posteriormente se distingue con su propia procedencia, sin firma humana. Para especies aplicables se requieren densidad y límites superiores validados de DAP y altura. El mínimo de DAP está fijado en 5 cm. Los límites de validación informática no son recomendaciones biológicas: los límites profesionales deben basarse en el método y contexto de la referencia.

Solo se estima con hábito oficial Árbol (`HAB_TREE`), un fuste y medidas completas dentro de los límites documentados. Palmas, arbustos, otros hábitos, varios fustes, diámetro inferior al mínimo y datos faltantes generan motivos explícitos con valores numéricos nulos. No se aplica densidad predeterminada ni se inventa equivalencia entre hábitos.

Cada publicación agrega una versión institucional inmutable. La configuración de otra entidad nunca se reutiliza. Publicar no altera resultados anteriores. **Actualizar estimaciones de esta entidad** aplica las referencias actuales a las visitas existentes agregando instantáneas; los resultados anteriores se conservan. Una segunda ejecución sin cambios no duplica registros. Retirar aplicabilidad también requiere actualizar visitas existentes para que el reporte use la nueva referencia; las nuevas visitas la usan inmediatamente.

## Escritura e historia

La identidad Tree no contiene medidas. Al registrar la primera inspección se calcula automáticamente, dentro de la misma transacción que mediciones, condiciones, riesgo y auditoría. Crear una identidad sin visita no permite estimar su biomasa: las vistas lo explican.

Corregir una inspección genera una estimación de su nueva `lock_version`; corregir especie o hábito recalcula las visitas de ese árbol. Una referencia ausente deja pendiente el cálculo sin bloquear el inventario. Los resultados numéricos enviados por clientes no se usan. Todos los orígenes que reutilizan TreeInspectionService comparten esta regla.

`carbon_profiles` y `carbon_estimates` tienen protección SQL contra UPDATE/DELETE. La instantánea conserva organización, inspección y versión, identificación, mediciones, densidad, autor y justificación de referencia, versión de algoritmo, fuentes y resultados. La clave de entradas es un SHA-256 del contenido, referencia y algoritmo; la unicidad en SQL permite repetición sin duplicados. Las claves foráneas compuestas impiden enlazar referencias o visitas de otra institución.

Las revisiones nuevas de inspecciones incluyen la referencia a su cálculo. Publicación y actualización explícita generan eventos auditables. No se modifican instantáneas ni hashes históricos de auditoría existentes.

## Lecturas, gráficas y exportaciones

La ficha de árbol, la inspección y el popup privado del mapa muestran el resultado de la visita y sus pendientes. `/analytics` incorpora stock por especie y cobertura. Los reportes `carbon` y `carbon_coverage` comparten el motor de alcance y filtros de los demás reportes.

Se elige primero una única última inspección por fecha, creación e ID; después, su estimación más reciente compatible con versión, especie y hábito. No se suman visitas históricas. Los totales de stock vivo incluyen únicamente estados actuales Sano, En observación y Trasplantado; los demás se muestran como excluidos. Esto no estima descomposición, emisiones por muerte ni flujo anual.

El reporte por especie incluye individuos totales, estimados incluidos, pendientes/excluidos y sumas en kg. Una especie sin estimaciones conserva sumas nulas, no ceros. El gráfico de cobertura hace visibles los motivos pendientes. Pueden coexistir referencias históricas; el inventario detallado identifica la versión y fuente de cada estimación.

CSV, Excel, PDF y GeoJSON privados incluyen las nuevas columnas del inventario; los agregados usan CSV, Excel y PDF. La ficha PDF conserva el perfil completo. Las exportaciones mantienen instantánea y reautorización existentes. Los esquemas y endpoints públicos no incorporan carbono ni sus parámetros. La plantilla oficial V1/V2, sus 110 campos y sus huellas no cambian.

## Activación y verificación real

Migración aditiva: `2026_10_25_000029_create_tree_carbon_estimates.php`. `AuthorizationSeeder` conserva el nuevo permiso; `DatabaseSeeder` agrega `CarbonEstimationSeeder` al final. El seeder no crea densidades ni aprueba metodología por las entidades.

Comandos en PHP 8.4 Docker:

```sh
docker compose run --rm --no-deps init php artisan migrate --force --no-interaction
docker compose exec -T app php artisan db:seed --class=CarbonEstimationSeeder --force --no-interaction
docker compose exec -T app php artisan sysforest:carbon-backfill --organization=UUID
```

La actualización CLI se reserva a operación autorizada; sin `--organization` recorre todas las entidades, incluidas archivadas, conservando su aislamiento. Cada visita se procesa en su propia transacción. La actualización web bloquea y autoriza la entidad durante la operación completa.

Se ejecutaron 69 comprobaciones temporales en `sysforest_carbon_check`, separada del piloto: fórmula con resultado independiente, estados pendientes, versiones, repetición, historial, cambio de especie/hábito, exclusión de muertos, alcance, rechazo de funcionario, inmutabilidad SQL, rollback al fallar confirmación de riesgo, lecturas Blade, gráficas y generación real de CSV/XLSX/PDF/GeoJSON. No son una suite permanente ni validación científica de campo o aceptación en navegador.

Activación inicial, anterior a la precarga bibliográfica: 2.854 estimaciones sin factores supuestos: 2.129 pendientes de referencia, 51 sin especie y 674 con hábito no compatible. Cero referencias publicadas y cero estimaciones numéricas. Se comprobaron las 2.854 filas privadas y los dos gráficos de carbono en alcance nacional. Los motivos del stock actual pueden diferir de los motivos de cálculo porque sus estados actuales se excluyen antes de agregar.

Los conteos y huellas de árboles, inspecciones, condiciones, revisiones, lotes, filas importadas e historiales de estado coincidieron antes/después. No se insertaron árboles ficticios en el piloto ni se reimportó Restrepo. Los factores y límites profesionales siguen pendientes: esta implementación no acredita validez del modelo para todas las especies o ubicaciones.

Verificación técnica final: sintaxis correcta en 37 archivos PHP, PSR-12 aplicado a todos los archivos intervenidos, compilación Blade y sintaxis del popup JavaScript aprobadas; `git diff --check` sin errores y `/health/ready` en estado ok. La repetición del backfill local agregó cero registros. Se retiraron los scripts de comprobación y ambas bases temporales después de pasar las verificaciones; no se recrearon suites automatizadas. Se solicitó recarga gradual de los workers al terminar su trabajo actual.

Mejora de presentación: formulario dividido en identificación/aplicabilidad, parámetros y respaldo profesional; selector de especie con búsqueda local existente y ayudas por campo. Cuatro tarjetas explican las fórmulas con fracciones, exponentes, unidades y etiquetas accesibles. Estilos adaptables aislados en `public/css/carbon.css`; se mantienen nombres, valores recuperados, restricciones, acciones y autorización. Blade compiló y el renderizado real comprobó nueve campos, las dos acciones, cuatro fórmulas accesibles y ocultación de controles de escritura en consulta. No hubo escrituras en datos ni nuevas suites. La adaptación CSS se revisó por código; queda pendiente la comprobación visual en navegador.

## Precarga bibliográfica autorizada de las especies actuales

Por solicitud posterior del mismo 25/10/2026, la migración aditiva `000030` distingue la procedencia de las referencias. `CarbonSpeciesReferenceSeeder` y `CarbonReferenceSeedService` cargan el paquete fijo `database/seeders/data/carbon-species-references.json` por entidad activa. El seeder verifica su SHA-256 y exige código, nombre completo y familia del catálogo, sin emparejamiento difuso ni cambios de taxonomía.

La fuente es Fischer et al. (2026), **GWDD v2.2**, DOI [10.5281/zenodo.18262736](https://doi.org/10.5281/zenodo.18262736), columna `wsg_est_trunk`. El extracto, atribución CC-BY-4.0, huellas y tres correspondencias respaldadas por Kew se conservan en [el manifiesto y sus referencias](../database/seeders/data/carbon-references-README.md).

Por entidad se agregaron 143 perfiles: 92 bibliográficos provisionales aplicables para estimación preliminar, 24 que requieren otra metodología, 16 sin densidad específica localizada y 11 pendientes de identificación. En las dos entidades actuales son 286 perfiles independientes. Los pendientes no reciben densidades de género, especies parecidas o números arbitrarios. Las referencias provisionales permiten calcular y aparecen identificadas en fichas, mapa, reportes y exportaciones; no representan validación profesional del contexto local.

Los perfiles provisionales tienen límites globales documentados: DAP 5–212 cm, altura 1,2–70,7 m, según los 4.004 individuos del suplemento de Chave. No representan máximos biológicos ni calibración por especie. Palmas, cícadas, bambúes, coníferas y Carica papaya quedan excluidos conservadoramente de la precarga aplicable. La condición por individuo de hábito Árbol, un fuste y mediciones completas se mantiene.

`validation_status` distingue `bibliographic`, `professional`, `pending_density`, `pending_identification` y `unsupported`; `source_package` identifica la precarga. La publicación manual siempre fija estado profesional, autor real y paquete nulo. La carga automática no asigna un autor humano. La función SQL append-only permanece activa. El evento institucional `carbon.references.seeded` identifica el paquete y su huella. El seeder omite perfiles ya existentes y no reemplaza decisiones posteriores, incluso si son retiradas del cálculo. `DatabaseSeeder` ejecuta la precarga antes de actualizar estimaciones.

El algoritmo conserva la ecuación y usa versión de contrato `CHAVE_2014_AGB_IPCC_2006_CF_2` para distinguir los nuevos motivos y el control del límite inferior de altura de las referencias bibliográficas. Las estimaciones anteriores permanecen intactas.

```sh
docker compose run --rm --no-deps init php artisan migrate --force --no-interaction
docker compose exec -T app php artisan db:seed --class=CarbonSpeciesReferenceSeeder --force --no-interaction
docker compose exec -T app php artisan db:seed --class=CarbonEstimationSeeder --force --no-interaction
```

Resultado local: **701 estimaciones numéricas** y 2.153 registros pendientes o excluidos. Los motivos de las últimas instantáneas son: estimado 701, densidad pendiente 446, especie ausente 51, número de fustes ausente 346, varios fustes 516, método no aplicable 52, diámetro menor de 5 cm 19, identificación pendiente 49 y hábito no compatible 674. Las 701 estimaciones actuales también pertenecen al stock vivo incluido en el reporte nacional, y todas usan referencias bibliográficas provisionales. No se rellenaron los fustes ausentes ni se convirtió un individuo multifuste en uno de un solo fuste.

La comprobación aislada aprobó **135 verificaciones temporales**: 286 perfiles, densidades reproducibles frente al extracto, correspondencias, límites, estados, procedencia congelada, separación institucional, cambios manuales conservados, idempotencia, inmutabilidad, gráficas, mapa, exportación y generación real de CSV/Excel/PDF. PSR-12 y sintaxis en 15 archivos PHP, Blade y sintaxis JavaScript aprobados. Las huellas de diez tablas preexistentes (incluidas taxonomía y riesgo) coincidieron antes/después de la precarga y recálculo. No se reimportaron datos. El archivo de investigación contiene referencias para consulta, sin acreditar revisión científica de campo ni aceptación visual en navegador.

Después de verificar la ampliación se eliminaron ambas bases temporales y los scripts de comprobación/investigación. El paquete bibliográfico y su extracto atribuible permanecen versionables. La repetición local agregó cero perfiles y cero estimaciones; se solicitó recarga gradual de workers.

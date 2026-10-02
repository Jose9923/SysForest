# Preparación de Restrepo para el Plan de Ornato 2025

`RestrepoOrnatoSeeder` prepara el municipio, la organización, la campaña y los catálogos necesarios para una carga revisada. No crea árboles ni inspecciones por sí solo. Por solicitud del 02/10/2026, `DatabaseSeeder` lo incluye en `APP_ENV=local`, después de las cuentas de desarrollo y antes de `RestrepoOrnatoImportSeeder`, que carga los registros autorizados.

La fuente es **Planilla Plan Ornato Restrepo 2025 Total para riesgos.xlsx**, SHA-256 `54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63`. El manifiesto está en `database/seeders/data/restrepo-ornato-catalogs.json`.

## Destino y territorio

- Colombia, código 170; Meta, código 50, ya existentes.
- Restrepo, código DANE 50606, verificado en la [ficha municipal DANE](https://sitios.dane.gov.co/cnpv/app/views/informacion/fichas/50606.pdf). No se agregan límites geográficos inferidos.
- Organización: **Plan de Ornato de Restrepo**, código `RESTREPO-META`, prefijo de individuos `COL-MET-RES-ORNATO`.
- Campaña: **Plan de Ornato Restrepo 2025**, código `ORNATO-2025`, período 2025-09-01 a 2025-11-30, activa para la carga y privada por defecto.

El período procede de la explicación del usuario. La fecha 2025-11-30 usada por la carga posterior es una referencia de entrega asignada por instrucción del usuario; no acredita que cada individuo se observara ese día.

## Conservación de significado y de registros existentes

El manifiesto conserva 143 etiquetas científicas de la fuente en 142 identidades al normalizar únicamente mayúsculas y espacios. Las dos grafías de `Myrsine guianensis (Aubl.) Kuntze` difieren solo en mayúsculas. Una especie coincide literalmente y ocho se vinculan a un binomio existente con la misma familia y autoridad omitida en el catálogo. Esas correspondencias están explícitas en `match_reason` y `source_labels`; no se infieren otros sinónimos.

Las denominaciones `sp.` se conservan como identificación incompleta, sin asignarles una especie concreta. Las demás etiquetas y familias se transcriben tal como vienen en la fuente, incluso cuando su nomenclatura requiera una revisión científica posterior. `Muerto en pie` se conserva en la carga como estado y observación, no como nombre común taxonómico.

Se reutilizan nueve especies, ocho familias y tres emplazamientos existentes. Se prevén **133 especies, 37 familias, 135 nombres comunes y 10 emplazamientos nuevos**. Los cuatro hábitos y cuatro orígenes biogeográficos utilizados ya existen; no se infiere el origen de establecimiento ausente.

Los códigos nuevos usan `ORNATO_`, categoría y 16 caracteres hexadecimales de SHA-256 sobre la etiqueta normalizada. Un código se valida contra su significado y su padre antes de reutilizarse. No se actualizan, reactivan ni borran registros anteriores. Una referencia inactiva o modificada detiene la siembra y revierte todas las altas de la transacción.

## Arquitectura y ejecución

El flujo es `RestrepoOrnatoSeeder → RestrepoOrnatoSeedService → Services de dominio / Repositories → Models`. Las altas de organización, campaña, taxonomía y catálogos reutilizan sus servicios habituales con validación, autorización y auditoría. El municipio también queda auditado, con su código y fuente DANE. La campaña se crea dentro de un contexto institucional verificado por `TenantContextService`, que se limpia al terminar.

El actor explícito es la cuenta local `superadmin@sysforest.test`; debe existir y conservar autorización nacional vigente. El seeder no crea usuarios, membresías artificiales ni privilegios.

~~~bash
docker compose exec -T app php artisan db:seed --class=RestrepoOrnatoSeeder --no-interaction
~~~

La segunda ejecución conserva los registros existentes y presenta cero altas si su configuración sigue concordando. La organización y la campaña se mantienen privadas; publicar requiere el flujo explícito de publicación de la aplicación.

## Resultado de la preparación autorizada

Las cifras y UUID de esta sección describen la preparación histórica del 28/09/2026, no la base recuperada el 02/10/2026.

La ejecución creó un municipio, una organización, una campaña, 37 familias, 133 especies, 135 nombres comunes y 10 emplazamientos. Las cantidades coinciden con el manifiesto revisado. El actor auditado fue el superadministrador existente, identificador interno 2.

| Recurso | Código | UUID |
| --- | --- | --- |
| Organización privada | RESTREPO-META | 01a0eafe-f60b-73d0-9e7f-2ea8b673b27c |
| Campaña privada y activa | ORNATO-2025 | 01a0eaff-01b6-705e-a9a4-34a67e978142 |

Una segunda ejecución terminó correctamente con **cero altas y cero cambios** en todas las tablas preparadas y sin eventos de auditoría adicionales. La comparación de cada registro previo confirmó que las ocho familias, diez especies, diez nombres comunes, seis emplazamientos, once hábitos, dos orígenes de establecimiento, cuatro orígenes biogeográficos y los registros territoriales e institucionales anteriores conservaron íntegramente sus campos.

Al concluir este seeder permanecían un árbol y una inspección, los mismos que antes de ejecutarlo; la importación forestal se realiza mediante un proceso posterior. Sintaxis PHP y PSR-12 de los tres archivos nuevos: correctos. No se crean ni ejecutan pruebas automatizadas por instrucción del usuario.

## Integración local con `--seed`

El 02/10/2026 se encontró el libro original en `/home/josev/Descargas/Planilla Plan Ornato Restrepo 2025 Total para riesgos.xlsx`; su SHA-256 coincide con la fuente autorizada. Esta instancia tenía cero árboles. La copia normalizada y el manifiesto de estados históricos no estaban disponibles: se reconstruyeron desde el libro y los criterios documentados. No se presentan como copias byte a byte de los JSON históricos ni reutilizan UUID de la base anterior.

El paquete local está en `database/seeders/data/private-restrepo/`, excluido de Git y del contexto de construcción Docker. Compose monta `database` en modo de solo lectura; se debe conservar o trasladar este paquete privado al preparar otro equipo. Los archivos requeridos y sus huellas quedan fijados en `RestrepoOrnatoImportService`:

| Archivo | SHA-256 |
| --- | --- |
| `source.xlsx` | `54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63` |
| `prepared.json` | `18a7660baa3dd04457d6408a5e8dc7829e948b515ddfdb86090f573818b2583a` |
| `statuses.json` | `5147336caa9d11dba46f77579238e5ba0ea066456529e38fcc538b7a3ddbf62c` |

También se conservan allí `decisions.json`, el esquema utilizado y el script de preparación como evidencia privada. El JSON incluye las 110 columnas del contrato y todos los valores originales no vacíos de cada fila en las observaciones. La recuperación reproduce 1.328 normalizaciones de coma decimal, 131 residuos OOXML, 11 normalizaciones de coordenadas, 12 medidas omitidas con motivo, 34 X con aclaración y 50 marcas ambiguas sin seleccionar. Las 51 identificaciones ausentes no se inventan; 2.851 individuos tienen coordenadas. Se conserva la referencia de entrega 30/11/2025 y la distinción frente a una fecha de visita conocida.

`DatabaseSeeder` comprueba la presencia y huellas del paquete antes de sembrar en local. `RestrepoOrnatoImportSeeder` delega en `RestrepoOrnatoImportService`, que utiliza el importador preparado existente: valida todo el documento y confirma por bloques de 100 filas (29 lotes). Un bloqueo Redis evita dos ejecuciones simultáneas de esta carga. La copia operativa se guarda en `storage/app/private/restrepo-seed/prepared.json`.

Los estados iniciales de la importación siguen siendo Activo. La corrección posterior utiliza `TreeLifecycleService`, historial y auditoría dentro de una transacción; queda limitada a las filas de este libro y sus inspecciones originales sin editar. Produce 65 Muertos, 1.897 En observación y 892 Activos. Al repetirse, los historiales acreditan la corrección ya aplicada: no se sobrescriben decisiones posteriores. No es una regla global para nuevas inspecciones. Un árbol modificado antes de aplicar su corrección requiere revisión independiente.

En otra base que conserve los lotes de la carga histórica, las protecciones del importador rechazarán una representación normalizada distinta. Se debe recuperar su JSON exacto y revisar la integración, sin cambiar la huella original ni la partición para duplicar individuos. En entornos distintos de `local`, el seeder general conserva su alcance de catálogos y piloto y no ejecuta las cuentas ni la carga privada.

## Verificación de la recuperación del 02/10/2026

La validación previa del documento completo terminó sin errores. `docker compose exec -T app php artisan migrate --seed --no-interaction` finalizó con código 0: 2.854 filas confirmadas en 29 lotes y 1.962 correcciones de estado. La primera carga tardó aproximadamente 8 minutos y medio; una repetición del importador tardó aproximadamente 4 segundos.

| Comprobación actual | Resultado |
| --- | ---: |
| Árboles / inspecciones / participaciones | 2.854 cada uno |
| Lotes completados / filas importadas | 29 / 2.854 |
| Activos / En observación / Muertos | 892 / 1.897 / 65 |
| Diferencias con el manifiesto de estados por individuo | 0 |
| Coordenadas PostGIS | 2.851 |
| Inspecciones con referencia 30/11/2025 | 2.854 |
| Historiales de estado (iniciales + correcciones) | 4.816 |
| Árboles públicos | 0 |

La organización y campaña siguen privadas. Una segunda ejecución completa de `migrate --seed` terminó con código 0, reconoció las 2.854 filas previas y reportó cero importaciones y cero correcciones nuevas. Las huellas de todos los registros de árboles, inspecciones, participaciones, lotes, filas e historiales de Restrepo fueron idénticas antes y después de esa repetición.

La comprobación HTTP manual inició sesión con el superadministrador, seleccionó Restrepo y recibió HTTP 200 en `/imports`, `/trees` y `/inventories`. La primera página de importaciones mostró 20 enlaces de lotes; los 29 lotes totales se conciliaron en base de datos. La sesión de revisión se cerró. No se realizó inspección visual en navegador ni se afirma una aceptación integral de la interfaz.

Pint PSR-12 y `php -l` pasaron en los cuatro archivos PHP añadidos o modificados; `git diff --check` pasó para los archivos de esta entrega. No se crearon ni ejecutaron suites automatizadas. Se cotejaron los 65 encabezados de condiciones del libro con el contrato: la única ampliación textual de la fuente es «arañas» en insectos benignos; el texto original se conserva y no activa observación por sí mismo.

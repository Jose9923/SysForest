# Preparación de Restrepo para el Plan de Ornato 2025

`RestrepoOrnatoSeeder` es explícito y no forma parte de `DatabaseSeeder`. Prepara el municipio, la organización, la campaña y los catálogos necesarios para una carga revisada. No crea árboles ni inspecciones.

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

La ejecución creó un municipio, una organización, una campaña, 37 familias, 133 especies, 135 nombres comunes y 10 emplazamientos. Las cantidades coinciden con el manifiesto revisado. El actor auditado fue el superadministrador existente, identificador interno 2.

| Recurso | Código | UUID |
| --- | --- | --- |
| Organización privada | RESTREPO-META | 01a0eafe-f60b-73d0-9e7f-2ea8b673b27c |
| Campaña privada y activa | ORNATO-2025 | 01a0eaff-01b6-705e-a9a4-34a67e978142 |

Una segunda ejecución terminó correctamente con **cero altas y cero cambios** en todas las tablas preparadas y sin eventos de auditoría adicionales. La comparación de cada registro previo confirmó que las ocho familias, diez especies, diez nombres comunes, seis emplazamientos, once hábitos, dos orígenes de establecimiento, cuatro orígenes biogeográficos y los registros territoriales e institucionales anteriores conservaron íntegramente sus campos.

Al concluir este seeder permanecían un árbol y una inspección, los mismos que antes de ejecutarlo; la importación forestal se realiza mediante un proceso posterior. Sintaxis PHP y PSR-12 de los tres archivos nuevos: correctos. No se crean ni ejecutan pruebas automatizadas por instrucción del usuario.

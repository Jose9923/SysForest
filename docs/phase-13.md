# SYSFOREST — Fase 13: plantilla oficial e importación Excel

Implementación de la Fase 13 autorizada por el usuario el 28 de septiembre de 2026. La fuente definitiva permanece en `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL, rama `codex/phase-13`. Se conservaron los cambios aprobados de Fase 12, aún sin commit final, y se guardó un checkpoint de sus 763 archivos fuente sin secretos ni almacenamiento. No se inició la Fase 14.

**Estado: implementación y activación verificadas.** Tras la aprobación explícita del usuario se ejecutó `AuthorizationSeeder` sobre la base operativa. Los dos permisos de importación están asignados a SUPER_ADMIN, ORGANIZATION_ADMIN y OFFICIAL; DEPARTMENT_ADMIN conserva su acceso previo sin esos permisos.

## Resultado funcional

- Módulo institucional en `/imports`: historial, plantilla, carga, vista previa, errores por fila, confirmación explícita y cancelación.
- Plantilla `SYSFOREST_IMPORT_V1`, con cuatro hojas: `Datos`, `Diccionario`, `Catalogos` e `Instrucciones`. Sus 110 columnas conservan la trazabilidad de las 94 variables originales y de las 65 condiciones físico-mecánicas y fitosanitarias.
- Cada fila confirmada crea un Tree privado, su participación en la campaña mediante `inventory_trees` y una primera TreeInspection privada. La carga para revisión no crea árboles ni consume consecutivos.
- Los códigos de catálogo se resuelven contra referencias vigentes. Se validan fechas, tipos, precisión, coordenadas y medidas mediante las reglas existentes. DAP, radio, áreas, volúmenes, clases y madurez se recalculan en backend.
- `import_batches` e `import_rows` mantienen origen, huella, valores leídos, errores de lectura, normalización, autores, estado y referencias creadas. El archivo original no se conserva; no se publica ninguna información de los lotes.
- Controller → Service → Repository → Model/datos → Blade. Los controladores coordinan, los servicios aplican reglas y preparan los datos, sin consultas directas en Blade. Select2 facilita seleccionar la campaña, sin cálculos de negocio en JavaScript.

La guía de uso y contrato completo está en [importación y exportación](import-export.md); la correspondencia de cada columna está en [trazabilidad de importación](import-field-mapping.md). Los reportes de Fase 12 conservan su contrato propio: una exportación no sustituye la plantilla oficial de importación.

## Integridad, concurrencia y seguridad

La confirmación revalida usuario, permisos actuales, organización, campaña, versión, caducidad y todas las filas originales. La reserva de consecutivos, árboles, participaciones, inspecciones, resultados y auditoría se confirma en una transacción completa. Un fallo revierte el conjunto. El procesamiento es síncrono y no crea trabajos de importación en cola.

Los códigos explícitos deben ser canónicos para el prefijo de la organización. Las colisiones inequívocas, incluso con árboles anulados, bloquean la importación. Se reservan primero todos los códigos explícitos para evitar que una fila automática consuma uno posterior; esta reserva puede adelantar la secuencia institucional. Los formularios manuales siguen sin permitir asignar códigos arbitrarios.

Un reintento de un lote completado devuelve su resultado anterior después de reautorizar, sin duplicar registros. La versión de revisión impide confirmar una pantalla obsoleta. Si la revalidación cambia datos normalizados, se presenta la nueva vista previa y se exige otra confirmación; si encuentra errores, no se crea ninguna fila. El SHA-256 impide repetir un archivo binariamente idéntico ya completado en esa institución.

Las claves foráneas compuestas mantienen la frontera `organization_id`, y los triggers protegen los originales, los resultados finalizados, los contadores y la correspondencia árbol/inspección. No se permite borrar físicamente lotes o filas desde este flujo. Cancelar conserva la trazabilidad.

La lectura exige `imports.view` y los permisos de consulta forestal; las mutaciones añaden `imports.create` y los permisos de creación de árboles e inspecciones. SUPER_ADMIN, ORGANIZATION_ADMIN y OFFICIAL operan únicamente sobre la institución activa autorizada. El alcance de lectura departamental y los usuarios anónimos quedan fuera del módulo; una cuenta con otros roles institucionales debe operar por el alcance autorizado correspondiente. Otro usuario autorizado de la misma institución puede confirmar un lote; se conservan creador y confirmador por separado.

El lector acepta exclusivamente la estructura oficial XLSX. Rechaza fórmulas, macros, enlaces externos, imágenes, objetos, componentes no permitidos, XML ambiguo y cargas fuera de límites. Nunca evalúa fórmulas ni descarga URLs. Conserva los valores decimales como cadenas para evitar pérdida de precisión. Los diagnósticos HTTP no muestran SQL, rutas ni trazas, incluso con depuración activada.

## Límites y medición

| Parámetro | Valor predeterminado |
| --- | --- |
| Filas con datos | 100 por archivo; rechazo completo al superarlo. |
| Tamaño comprimido | 5120 KiB (5 MiB). |
| Contenido descomprimido | 32 MiB. |
| Componentes ZIP | 200. |
| Vigencia de vista previa | 24 horas. |
| Mutaciones | 6 por minuto y usuario, compartidas entre carga, confirmación y cancelación. |

Prueba en la base exclusiva `sysforest_testing`, con 100 filas y las 65 condiciones seleccionadas en cada una:

| Medición | Resultado |
| --- | ---: |
| Generación del archivo de prueba | 0,25 s |
| Carga y validación | 15,79 s |
| Confirmación completa | 34,27 s |
| Memoria máxima PHP | 64,5 MiB |
| Consultas observadas en carga y confirmación | 67.444 |

Estos tiempos son mediciones locales de servicios por CLI, no una garantía de concurrencia o de producción. El límite inicial se redujo de 500 a 100 para mantener margen respecto del timeout HTTP de 65 segundos. Antes de aumentarlo se debe volver a medir y considerar el costo de consultas heredado de la autorización y los servicios forestales por fila. No se declara verificado un lote de 500 filas.

## Verificación ejecutada

| Comprobación | Resultado |
| --- | --- |
| Regresión PHP completa | 1668 pruebas / 9898 aserciones, PASS; 1139.70 s. |
| Batería específica de importación previa al último ajuste visual | 118 pruebas / 1088 aserciones, PASS; 143,30 s. |
| Ajuste final HTTP y presentación | 16 pruebas / 122 aserciones, PASS; 9,58 s. |
| Metadatos finales del diccionario y lector | 22 pruebas unitarias / 116 aserciones, PASS. Madurez se describe como texto y clases como enteros; las medidas siguen siendo decimales. |
| JavaScript existente | 59 pruebas, 59 PASS, sin omisiones. Copias verificadas por SHA-256 contra el código definitivo. |
| PSR-12 | PASS en 552 archivos. |
| Diferencias Git | `git diff --check`, PASS. |
| Plantilla generada por la aplicación | 110 columnas, cuatro hojas, límite 100, paquete OOXML de 12 componentes y lectura válida del libro vacío. |
| Inspección visual XLSX | Cuatro hojas renderizadas y revisadas; sin errores de celda detectados en el libro. |
| Blade y responsive | Carga, revisión válida, errores, resultado e historial; comprobaciones de escritorio y móvil, sin desbordamiento horizontal en las pantallas verificadas. |

Las suites que usan PostgreSQL se ejecutaron serialmente en `sysforest_testing`. Cubren permisos vigentes, acceso entre instituciones, catálogos desactivados, fórmulas y archivos manipulados, cadenas decimales exactas, ceros/nulos, límites, reserva de códigos, colisiones, rollback, cambio de referencias, caducidad, confirmación repetida, huella duplicada, autores, auditoría y restricciones de base. El ajuste final de metadatos del diccionario se comprobó adicionalmente con la suite unitaria indicada; la corrección CSS del historial se verificó en navegador.

La prueba HTTP de vista previa usa un lote real de la base de pruebas y comprueba etiquetas, madurez, precisión y ausencia de UUID/versiones técnicas en la presentación de cálculos. Las pantallas del navegador se generaron con fixtures identificados como datos de prueba y sin acciones operativas ni tokens. No se insertaron individuos ficticios en la base activa para la revisión visual.

No se realizó una ronda de abrir, editar y guardar con la aplicación de escritorio Microsoft Excel o LibreOffice. Se verificó la plantilla generada, sus componentes OOXML, su lectura por la aplicación y su importación/renderizado mediante la herramienta de QA. Esta distinción limita lo que puede afirmarse sobre editores externos.

## Entorno observado

Comprobación realizada: `2026-09-28T17:48:58+00:00`.

| Componente | Versión/estado |
| --- | --- |
| PHP | 8.4.26 |
| Laravel | 13.31.0 |
| PostgreSQL | 17.5 (Debian 17.5-1.pgdg110+1) |
| PostGIS | 3.5.2 |
| OpenSpout | v5.12.0 |
| Redis | PING correcto |
| Permisos operativos registrados | 48 |
| Lotes/filas operativos de importación | 0 / 0 |
| Última prueba procesada por worker | 2026-09-28T15:46:05+00:00 |
| Última ejecución del scheduler | 2026-09-28T17:48:00+00:00 |
| Temporales .part al comprobar | 0 |

Las dos migraciones de importación están aplicadas en la base operativa y la de pruebas:

- `2026_09_28_000018_create_import_batches_and_rows`.
- `2026_09_28_000019_guard_completed_import_batches`.

No se modificó la estructura Docker aprobada ni se añadieron dependencias Composer en esta fase. Aplicación disponible en `127.0.0.1:8080` y Mailpit en `127.0.0.1:8025`; PHP-FPM, PostgreSQL y Redis conservan puertos internos. Queue y scheduler siguen saludables; el módulo de importación no los utiliza para confirmar lotes.

Resultado de `docker compose ps --all` al cierre:

```text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED       STATUS                    PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         2 hours ago   Up 2 hours (healthy)      9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     2 weeks ago   Exited (0) 2 hours ago    
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        2 hours ago   Exited (0) 2 hours ago    
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     2 weeks ago   Up 3 hours (healthy)      127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       2 weeks ago   Up 3 hours (healthy)      127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    2 weeks ago   Up 3 hours (healthy)      5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       2 hours ago   Up 17 minutes (healthy)   9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       2 weeks ago   Up 3 hours (healthy)      6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   2 hours ago   Up 2 hours (healthy)      9000/tcp
```

## Comandos reproducibles

Desde `/home/josev/SYSFOREST`, dentro de Ubuntu-24.04, ejecutar cada comando por separado:

```bash
docker compose up -d
docker compose run --rm --no-deps init
docker compose exec -T app php artisan route:list --path=imports
docker compose exec -T -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 -e SYSFOREST_PUBLIC_EXPLAIN=1 -e SYSFOREST_PUBLIC_VISUAL=1 app php artisan test --fail-on-warning --compact
docker compose exec -T app php artisan test --filter=Import --fail-on-warning --compact
docker compose exec -T -e SYSFOREST_IMPORT_VISUAL_SNAPSHOTS=1 app php artisan test tests/Feature/ImportHttpTest.php tests/Feature/ImportPresentationTest.php --fail-on-warning
docker run --rm --user 1000:1000 --mount type=bind,src=/home/josev/SYSFOREST,dst=/var/www/html --workdir /var/www/html --entrypoint vendor/bin/pint sysforest-app:local --preset psr12 --test
docker compose ps --all
git diff --check
```

`init` aplica migraciones pendientes con el rol migrador a ambas bases; no reinicia ni borra datos. Los fixtures visuales se habilitan solo con la variable de pruebas indicada. Las pruebas JavaScript se ejecutaron mediante `node --test tests/Frontend/*.test.mjs` en copias idénticas verificadas.

La activación de permisos es un paso distinto a las migraciones:

```bash
docker compose exec -T app php artisan db:seed --class=AuthorizationSeeder
```

Este comando se ejecutó después de la aprobación explícita del usuario. La comparación de lectura previa mostró únicamente seis incorporaciones (dos por cada uno de los tres roles autorizados), sin retirar permisos existentes. La consulta posterior confirmó el catálogo de 48 permisos y la matriz indicada.

## Problemas y límites restantes

1. OpenSpout incorporaba relaciones de comentarios y VML vacías en la plantilla aun sin comentarios. El generador retira únicamente esos componentes propios comprobados como vacíos; la lista estricta de componentes admitidos del lector se conserva.
2. La revisión del lector detectó ambigüedades de namespace y valores XML duplicados/mezclados. Se corrigieron y se añadieron pruebas específicas; no se aceptan valores escondidos fuera de las celdas ni en propiedades de formato.
3. La revisión visual detectó la presentación de identificadores internos dentro de los cálculos. `ImportReadService` prepara ahora siete medidas/clases y la etiqueta de madurez; UUID y versiones se conservan en los datos persistidos y se excluyen del bloque de cálculos de la vista previa. También se contuvo la etiqueta accesible de la tabla para corregir un desbordamiento del historial móvil; la tabla conserva su desplazamiento interno.
4. El costo medido de confirmación motivó el límite de 100 filas. No se implementó procesamiento masivo por cola ni optimización transversal de servicios anteriores.
5. La revisión automática de aprobación rechazó activar el seeder de permisos sobre la base operativa. Se completó el código y la verificación, se explicó el cambio concreto al usuario y se ejecutó únicamente tras recibir su aprobación explícita. La matriz se volvió a verificar por lectura.

No se implementan actualización o fusión masiva de árboles, conciliación avanzada por especie/ubicación/número legado, asociación masiva de individuos existentes a otra campaña ni descarga de fotografías por URL. El mismo archivo vuelto a guardar puede tener otra huella; los números legados no son identificadores únicos. Los códigos explícitos extremadamente altos pueden acercar la secuencia al límite entero admitido, por lo que deben revisarse antes de confirmar. Los archivos fuera del contrato se corrigen usando la plantilla oficial.

## Archivos creados y modificados

48 archivos nuevos y 16 modificados.

### Nuevos

- `app/Enums/ImportBatchStatus.php`
- `app/Http/Controllers/ImportController.php`
- `app/Http/ImportExceptionRenderer.php`
- `app/Http/Requests/CancelImportRequest.php`
- `app/Http/Requests/ConfirmImportRequest.php`
- `app/Http/Requests/StoreImportRequest.php`
- `app/Imports/ImportTemplateSchema.php`
- `app/Imports/ImportWorkbookReader.php`
- `app/Imports/XlsxPackageGuard.php`
- `app/Models/ImportBatch.php`
- `app/Models/ImportRow.php`
- `app/Repositories/ImportBatchRepository.php`
- `app/Repositories/ImportCatalogRepository.php`
- `app/Repositories/ImportRowRepository.php`
- `app/Services/ImportAccessService.php`
- `app/Services/ImportConfirmationService.php`
- `app/Services/ImportReadService.php`
- `app/Services/ImportRowValidationService.php`
- `app/Services/ImportRowWriteService.php`
- `app/Services/ImportService.php`
- `app/Services/ImportTemplateService.php`
- `app/Services/ImportUploadService.php`
- `app/Services/ImportValidationService.php`
- `config/imports.php`
- `database/migrations/2026_09_28_000018_create_import_batches_and_rows.php`
- `database/migrations/2026_09_28_000019_guard_completed_import_batches.php`
- `docs/import-export.md`
- `docs/import-field-mapping.md`
- `docs/phase-13.md`
- `public/css/imports.css`
- `resources/views/imports/assets.blade.php`
- `resources/views/imports/create.blade.php`
- `resources/views/imports/error.blade.php`
- `resources/views/imports/index.blade.php`
- `resources/views/imports/rows.blade.php`
- `resources/views/imports/show.blade.php`
- `routes/imports.php`
- `tests/Feature/ImportHttpTest.php`
- `tests/Feature/ImportPerformanceTest.php`
- `tests/Feature/ImportPresentationTest.php`
- `tests/Feature/ImportRowValidationTest.php`
- `tests/Feature/ImportRowWriteTest.php`
- `tests/Feature/ImportSecurityTest.php`
- `tests/Feature/ImportTemplateTest.php`
- `tests/Feature/ImportWorkflowTest.php`
- `tests/ImportTestCase.php`
- `tests/Support/ImportWorkbookFactory.php`
- `tests/Unit/ImportWorkbookTest.php`

### Modificados

- `.env.example`
- `AGENTS.md`
- `CLAUDE.md`
- `README.md`
- `app/Providers/AppServiceProvider.php`
- `app/Repositories/OrganizationSequenceRepository.php`
- `app/Repositories/TreeRepository.php`
- `app/Services/TreeCodeService.php`
- `app/Services/TreeLocationService.php`
- `app/Services/TreeService.php`
- `bootstrap/app.php`
- `database/seeders/AuthorizationSeeder.php`
- `docs/architecture.md`
- `docs/database.md`
- `resources/views/partials/institutional-navigation.blade.php`
- `routes/web.php`

El listado compara contra el checkpoint recibido de Fase 12, no contra el último commit de Fase 11. No se eliminaron archivos fuente preexistentes. Los temporales de QA de Windows no son dependencias de la aplicación ni sustituyen `/home/josev/SYSFOREST`.

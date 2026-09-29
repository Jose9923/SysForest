# Fase 12 — Reportes y exportaciones

Entrega del 28 de septiembre de 2026, lista para revisión. Proyecto definitivo: `/home/josev/SYSFOREST` en Ubuntu-24.04 WSL. Rama `codex/phase-12`; checkpoint aprobado de Fase 11: `06b69b8`. No se crea commit final ni se inicia Fase 13.

## Resultado funcional

- Reportes institucionales en `http://localhost:8080/reports` y públicos en `http://localhost:8080/portal/reports`, accesibles desde la navegación, mapas, bandejas y fichas.
- Inventario completo en CSV, Excel y GeoJSON; tabla resumida y ficha individual completa en PDF generado mediante Blade. Los agregados admiten CSV, Excel y PDF.
- Reportes por especie, familia, ambos orígenes, hábito, estado, clases diamétrica/altura, condiciones físicas/fitosanitarias, mantenimiento, municipio, organización, última inspección, fauna y protección/patrimonio.
- Flujo Controller → Service → Repository → Model/datos → Blade o escritor. Sin consultas en Blade, cálculos forestales en JavaScript ni duplicación del motor de filtros.
- Una fila por individuo y última inspección pertinente; selección pública antes de elegir la última visita. El radio explícito limita el conjunto; el viewport, la página y el máximo de marcadores no recortan la descarga.
- Esquema institucional de 126 columnas y público de 111, con trazabilidad de las 94 variables conceptuales y 65 condiciones originales. Códigos y etiquetas se conservan por separado; las medidas no se recalculan ni redondean.

El reporte por **zonas permanece no disponible (422)**: el proyecto no tiene definición ni catálogo de zonas. No se inventa una equivalencia con emplazamiento o municipio. Esta limitación se presenta en la aplicación y en la documentación.

## Seguridad y coherencia

La consulta privada comprueba `reports.view`; la descarga añade `reports.export` y los permisos del contenido. Se revalida el mismo alcance antes de entregar el archivo. La consulta pública utiliza únicamente el universo publicado y proyecciones explícitas sin funcionarios, UUID, auditoría, observaciones internas ni archivos originales de imágenes.

Los lotes y sus conteos se consumen dentro de una instantánea PostgreSQL `REPEATABLE READ READ ONLY`. Se comprobó ese aislamiento en ejecución real. Las fuentes públicas se revalidan al terminar; los agregados comparan filas y resumen, incluso cuando no tienen grupos. Una revocación cancela la descarga y elimina su temporal.

CSV neutraliza fórmulas y utiliza UTF-8/BOM con separador `;`. Excel usa celdas de texto explícitas y tres hojas (Datos, Diccionario y Criterios); una celda mayor de 32.767 unidades UTF-16 devuelve 422, sin recorte. PDF desactiva PHP, JavaScript y recursos remotos. GeoJSON conserva Point WGS84 `[longitud, latitud]` o `geometry:null` y propiedades permitidas.

Límites predeterminados: 10.000 filas públicas, 50.000 privadas, 200 para PDF y 10.000 grupos; frecuencia de 6 descargas/minuto/IP pública y 12/minuto/usuario privado. Los límites rechazan el conjunto entero. Las seis variables REPORTS están documentadas en `.env.example`.

Los temporales UUID.part y espacios XLSX UUID.part.work están en almacenamiento privado. Se eliminan al enviar/cerrar o al fallar; el scheduler recupera huérfanos de más de una hora. La limpieza valida rutas propias y no sigue enlaces simbólicos. La auditoría registra preparación de descargas privadas, sin afirmar que el cliente completó su recepción.

## Verificación ejecutada

| Comprobación | Resultado |
| --- | --- |
| Baseline F11 previo a la implementación | 1423 pruebas PHP / 7572 aserciones, PASS. |
| Regresión completa | 1542 pruebas PHP / 8698 aserciones, PASS; 1076.37 s. |
| Focal final de F12 tras ajustes de revisión | 126 pruebas PHP / 1212 aserciones, PASS; 114.34 s. |
| JavaScript | 59 pruebas, 59 PASS; sin fallos ni omisiones. |
| PSR-12 | PASS en 514 archivos con preset PSR-12. |
| Diferencias Git | `git diff --check`, PASS. |
| PDF | Revisión de todas las páginas renderizadas; registros completos, sin texto fuera de página, encabezados y pies consistentes. |
| Interfaz | Navegador real a 390 y 1.440 px; filtros, limpieza, selector de reporte y descargas comprobados. |
| Composer | Manifiesto válido; `validate --strict` devuelve la advertencia previa sobre el pin JWT 2.9.3. |

Las suites DB se ejecutaron serialmente en `sysforest_testing`. Las pruebas cubren permisos, los cuatro roles, IDOR, parámetros públicos, proyecciones, precisión, selección latest/ANY-ALL, radio, más de 2.000 individuos, número acotado de consultas, formatos reales, CSV injection, XLSX sin fórmulas, ceros/nulos, límites, revocaciones y limpieza. Los ajustes posteriores a la corrida global se volvieron a comprobar mediante la batería focal final.

### Descargas por Nginx/PHP-FPM reales

| Formato | HTTP | MIME | Filas | Bytes |
| --- | ---: | --- | ---: | ---: |
| csv | 200 | `text/csv; charset=UTF-8` | 1 | 5117 |
| xlsx | 200 | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` | 1 | 14658 |
| geojson | 200 | `application/geo+json` | 1 | 4712 |
| pdf | 200 | `application/pdf` | 1 | 73531 |

Los archivos públicos se verificaron localmente, sin insertar registros operativos. Las respuestas usaron `no-store`; CSV/GeoJSON contienen 111 columnas permitidas, XLSX tiene tres hojas sin fórmulas y PDF posee firma válida. El número de filas refleja los datos publicados existentes al comprobarlo.

### Revisión PDF con datos sintéticos

| Documento | Páginas | Registros/campos completos |
| --- | ---: | ---: |
| inventory-table | 7 | 50 |
| grouped-table | 9 | 50 |
| individual-tree | 13 | 125 |

Estos fixtures sólo existen para QA y no representan inventarios reales. Se corrigieron etiquetas de ficha que podían quedar aisladas al final de una página.

## Entorno y servicios

| Componente | Versión/estado |
| --- | --- |
| PHP | 8.4.26 |
| Laravel | 13.31.0 |
| PostgreSQL | 17.5 (Debian 17.5-1.pgdg110+1) |
| PostGIS | 3.5.2 |
| Dompdf | v3.1.6 |
| OpenSpout | v5.12.0 |
| Redis | PING correcto. |
| Permisos registrados | 46. |
| Última prueba procesada por worker | 2026-09-28T15:46:05+00:00. |
| Última ejecución del scheduler | 2026-09-28T16:02:00+00:00. |
| Temporales .part al cierre | 0. |

`docker compose up -d` levantó correctamente el entorno. App, Nginx, PostgreSQL/PostGIS, Redis, queue, scheduler y Mailpit están saludables; init y db-init terminaron con código 0. Puertos expuestos únicamente en loopback: 8080 (aplicación) y 8025 (Mailpit). FPM, PostgreSQL y Redis permanecen en la red interna. La estructura Docker aprobada se conserva; se reconstruyó la imagen para incorporar las dependencias.

```text
NAME                    IMAGE                     COMMAND                  SERVICE     CREATED          STATUS                       PORTS
sysforest-app-1         sysforest-app:local       "sysforest-entrypoin…"   app         36 minutes ago   Up 36 minutes (healthy)      9000/tcp
sysforest-db-init-1     postgis/postgis:17-3.5    "/bin/bash /opt/sysf…"   db-init     2 weeks ago      Exited (0) 36 minutes ago    
sysforest-init-1        sysforest-app:local       "sysforest-entrypoin…"   init        36 minutes ago   Exited (0) 36 minutes ago    
sysforest-mailpit-1     axllent/mailpit:v1.31.1   "/mailpit"               mailpit     2 weeks ago      Up About an hour (healthy)   127.0.0.1:8025->8025/tcp
sysforest-nginx-1       nginx:1.28-alpine         "/docker-entrypoint.…"   nginx       2 weeks ago      Up About an hour (healthy)   127.0.0.1:8080->80/tcp
sysforest-postgres-1    postgis/postgis:17-3.5    "docker-entrypoint.s…"   postgres    2 weeks ago      Up About an hour (healthy)   5432/tcp
sysforest-queue-1       sysforest-app:local       "sysforest-entrypoin…"   queue       36 minutes ago   Up 36 minutes (healthy)      9000/tcp
sysforest-redis-1       redis:7.4-alpine          "docker-entrypoint.s…"   redis       2 weeks ago      Up About an hour (healthy)   6379/tcp
sysforest-scheduler-1   sysforest-app:local       "sysforest-entrypoin…"   scheduler   36 minutes ago   Up 36 minutes (healthy)      9000/tcp
```

## Comandos reproducibles

Ejecutar desde `/home/josev/SYSFOREST` en Ubuntu-24.04, en pasos separados:

```bash
docker compose build
docker compose up -d
docker compose exec app php artisan db:seed --class=AuthorizationSeeder
docker compose exec -T -e SYSFOREST_MAP_EXPLAIN=1 -e SYSFOREST_MAP_VISUAL=1 -e SYSFOREST_PUBLIC_EXPLAIN=1 -e SYSFOREST_PUBLIC_VISUAL=1 app php artisan test --fail-on-warning --compact
docker compose exec -T app php artisan test --filter='ReportScopeTest|ReportAggregateTest|ExportProjectionTest|ExportQueryTest|ReportPresentationTest|ExportDownloadTest|ExportWriterTest|ExportFileRepositoryTest' --compact
docker compose exec -T -e SYSFOREST_REPORT_VISUAL=1 app php artisan test tests/Feature/ReportPresentationTest.php --filter=test_report_visual_fixtures_can_save_real_pages_without_live_actions_or_tokens --compact
docker run --rm --user 1000:1000 --mount type=bind,src=/home/josev/SYSFOREST,dst=/var/www/html --workdir /var/www/html --entrypoint vendor/bin/pint sysforest-app:local --preset psr12 --test
docker compose exec app php artisan schedule:list
docker compose exec app php artisan sysforest:cleanup-report-downloads
docker compose exec app php artisan sysforest:probe-queue
docker compose ps --all
git diff --check
```

JavaScript se probó con Node del runtime local sobre copias con SHA-256 idéntico al código definitivo: `node --test tests/Frontend/*.test.mjs`. No se ejecutan dos suites simultáneas contra la base de pruebas.

## Problemas encontrados y resolución

1. Docker Desktop no arrancaba por un socket obsoleto en `AppData\Local\Docker\run`. Se detuvo mediante su CLI, se verificó el contenido y se renombró ese directorio a `run.sysforest-backup-20260928`, conservando la copia. Se creó un directorio vacío y Desktop arrancó. No se reiniciaron ni borraron bases, volúmenes o imágenes.
2. Se corrigieron un helper de prueba que colisionaba con TestCase, códigos sintéticos demasiado largos y directivas Blade contiguas en la vista de error. Los casos fallidos se volvieron a ejecutar satisfactoriamente.
3. La revisión independiente detectó temporales internos XLSX que podían sobrevivir a una interrupción y totales de agregados vacíos que requerían revalidación. Ambos tienen corrección y pruebas de regresión.
4. La revisión de navegador detectó que elegir otro reporte exigía aplicar filtros. Se añadió navegación del selector y un botón visible de alternativa.
5. La advertencia de Composer por `php-open-source-saver/jwt-auth:2.9.3` es anterior a esta fase y corresponde a la versión exacta ya acordada. No se cambió la estrategia JWT.

Siguen pendientes las definiciones ya registradas de fases previas (multifuste, estados de fenología, anulación formal de inspecciones y alertas por correo de mantenimiento), además de zonas. Fase 13/importación, nuevos dashboards y la distribución de exportaciones por cola/correo no se implementan en esta entrega.

## Archivos creados y modificados

58 archivos nuevos y 24 modificados respecto del checkpoint. No se añadió ninguna migración de tablas de exportación.

### Nuevos

- `app/Console/Commands/CleanupReportDownloads.php`
- `app/DTOs/ExportArtifact.php`
- `app/DTOs/ReportSelectionDTO.php`
- `app/Http/Controllers/ExportController.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/ReportExceptionRenderer.php`
- `app/Http/Requests/ReportRequest.php`
- `app/Reports/ExportCell.php`
- `app/Reports/ExportFormat.php`
- `app/Reports/InventoryExportSchema.php`
- `app/Reports/InventoryPrivateRowProjector.php`
- `app/Reports/InventoryPublicRowProjector.php`
- `app/Reports/InventoryRowValues.php`
- `app/Reports/ReportCatalog.php`
- `app/Reports/Writers/CsvExportWriter.php`
- `app/Reports/Writers/GeoJsonExportWriter.php`
- `app/Reports/Writers/XlsxExportWriter.php`
- `app/Repositories/ExportFileRepository.php`
- `app/Repositories/ExportReadRepository.php`
- `app/Repositories/ExportSourceRepository.php`
- `app/Repositories/ReportRepository.php`
- `app/Repositories/ReportSnapshotRepository.php`
- `app/Services/ExportCleanupService.php`
- `app/Services/ExportDatasetService.php`
- `app/Services/ExportService.php`
- `app/Services/ReportAccessService.php`
- `app/Services/ReportFilterService.php`
- `app/Services/ReportPdfService.php`
- `app/Services/ReportReadService.php`
- `config/reports.php`
- `docs/exports-field-mapping.md`
- `docs/phase-12.md`
- `docs/reports-and-exports.md`
- `public/css/reports.css`
- `public/js/reports/filters.js`
- `public/js/reports/links.js`
- `resources/views/reports/assets.blade.php`
- `resources/views/reports/downloads.blade.php`
- `resources/views/reports/error.blade.php`
- `resources/views/reports/filters.blade.php`
- `resources/views/reports/index.blade.php`
- `resources/views/reports/pdf/layout.blade.php`
- `resources/views/reports/pdf/table.blade.php`
- `resources/views/reports/pdf/tree.blade.php`
- `resources/views/reports/table.blade.php`
- `resources/views/reports/value.blade.php`
- `routes/public-reports.php`
- `routes/reports.php`
- `tests/Feature/ExportDownloadTest.php`
- `tests/Feature/ExportProjectionTest.php`
- `tests/Feature/ExportQueryTest.php`
- `tests/Feature/ReportAggregateTest.php`
- `tests/Feature/ReportPresentationTest.php`
- `tests/Feature/ReportScopeTest.php`
- `tests/Frontend/report-links.test.mjs`
- `tests/Frontend/report-selection.test.mjs`
- `tests/Unit/ExportFileRepositoryTest.php`
- `tests/Unit/ExportWriterTest.php`

### Modificados

- `.env.example`
- `AGENTS.md`
- `CLAUDE.md`
- `README.md`
- `app/Http/PublicExceptionRenderer.php`
- `app/Providers/AppServiceProvider.php`
- `app/Repositories/TreeQueryRepository.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `database/seeders/AuthorizationSeeder.php`
- `docs/architecture.md`
- `public/js/map/engine.js`
- `resources/views/maps/index.blade.php`
- `resources/views/partials/institutional-navigation.blade.php`
- `resources/views/public/error.blade.php`
- `resources/views/public/layout.blade.php`
- `resources/views/public/map.blade.php`
- `resources/views/public/trees/index.blade.php`
- `resources/views/public/trees/show.blade.php`
- `resources/views/trees/index.blade.php`
- `resources/views/trees/show.blade.php`
- `routes/console.php`
- `routes/web.php`

## Referencias internas

- [Guía de reportes y exportaciones](reports-and-exports.md).
- [Correspondencia de las 94 variables](exports-field-mapping.md).
- [Política pública](public-data-policy.md).
- [Filtros compartidos](map-filters.md).

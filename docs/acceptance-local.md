# Revisión de aceptación del piloto local

Fecha: 28 de septiembre de 2026. Solicitud del usuario: «Revisión de aceptación del piloto local». Esta revisión es posterior al cierre de Fase 15; no constituye una nueva fase ni un despliegue externo.

## Dictamen y alcance

Los recorridos realizados verificaron captura manual, cálculos, evidencias, mantenimiento, perfiles y permisos, conflicto de edición, auditoría, mapa, exportación y publicación/retirada. Se corrigieron tres defectos demostrados: formato de entrada de la plantilla XLSX, aceptación de blancos regrabados y duplicación del registro de acceso de Nginx.

La aceptación conserva pendientes explícitos: no se acreditó abrir/editar/guardar en Excel de escritorio ni completar una importación mediante el navegador integrado. No se presenta el resultado automatizado del backend como una prueba de esos recorridos de escritorio.

La matriz inicial contiene 15 escenarios: **8 completos, 6 parciales y 1 no ejecutado** según sus pasos previstos. «Parcial» identifica subcasos sin ejecutar; no significa que se haya observado un error del dominio forestal. Los tres defectos reproducidos y corregidos se describen por separado.

## Entorno de prueba

- Fuente: `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL, rama recibida `codex/phase-15`. Se conservaron los cambios anteriores y no se creó un commit.
- Compose temporal: `sysforest-acceptance-20260928`, usando imágenes existentes y montajes de código de solo lectura.
- Aplicación: `http://acceptance.localhost:8097`; Mailpit: `http://acceptance.localhost:8098`, ambos ligados a loopback. Host y cookies distintos de `localhost:8080`.
- PostgreSQL, Redis, almacenamiento y correo propios; base funcional `sysforest_acceptance` y base de pruebas `sysforest_acceptance_testing`. Secretos nuevos y temporales, sin leer ni sustituir el `.env` operativo.
- Cuatro cuentas sintéticas de desarrollo, una organización INEM y una campaña nueva `ACEP-20260928`. No se utilizaron cuentas ni fotografías operativas.
- No hubo envío de correo externo, publicación en Internet ni intervención forestal real. La fotografía técnica era una copia del logo, declarada como tal.

## Matriz ejecutada

| Caso | Estado | Resultado y límite |
| --- | --- | --- |
| A01 · Acceso y roles | Completo | «Iniciar sesión» del portal abre login. Acceso con SUPER_ADMIN, ORGANIZATION_ADMIN, OFFICIAL y DEPARTMENT_ADMIN, selección INEM y perfiles. Cada nuevo login solicita seleccionar organización. SUPER_ADMIN tiene ámbito nacional sin membresía artificial; DEPARTMENT_ADMIN muestra Meta; administrador y funcionario tienen sus membresías respectivas. |
| A02 · Campaña | Completo | ORGANIZATION_ADMIN creó `ACEP-20260928`, activa y disponible. OFFICIAL la consulta; no recibe creación y el acceso directo a `/inventories/create` se deniega. |
| A03 · Árbol y ubicación | Completo | OFFICIAL creó `COL-MET-VVC-INEM-000001`, número legado `MANUAL-ACEP-20260928`, especie Oití. Se conservaron DMS originales y WGS84; no se inventaron equipo, precisión o fecha de captura. Vista previa y ficha muestran 4.25533333 / −73.57197222. |
| A04 · Inspección | Completo | Visita del 2026-09-28 separada de Tree: CAP 154 cm, 3 fustes, factor 0.5, alturas 12 y 0.8 m. Vista previa, ficha persistida y exportaciones coinciden en los derivados exactos. Condiciones Anclaje bueno/Sano y fauna Aves conservadas; copa desconocida queda vacía. |
| A05 · Evidencia | Completo | JPEG técnico 1024×559 asociado a la inspección, tipo Individuo completo y descripción privada. Galería, ficha e imagen se consultan; descarga original con SHA-256 idéntico al fixture. Una renovación real de sesión conservó la fotografía seleccionada y su descripción antes de reintentar la carga. |
| A06 · Mantenimiento | Completo | `MNT-000001`, Riego, prioridad Normal: Pendiente → Asignado → En ejecución → Ejecutado por OFFICIAL; Verificado por ORGANIZATION_ADMIN. Historial versiones 1–5 y autores correctos. Asignar responsable al crear no adelantó el estado. El funcionario no recibió la acción de verificar. |
| A07 · Plantilla y vista previa | Parcial | Descarga oficial desde UI y preparación de una fila desde ese libro real, preservando catálogos y cuatro hojas. Reader: una fila válida y cero SQL. Dos pestañas del navegador se cerraron al adjuntar el XLSX, antes del envío; Nginx registró cero POST de importación y la base quedó con cero lotes. Vista previa UI no ejecutada. |
| A08 · Confirmación y duplicados | No ejecutado | Depende de A07. No hubo confirmación, lote completado, árbol importado, recarga de resultado ni reenvío de confirmación mediante navegador. Las 122 pruebas automatizadas de Import son evidencia separada del backend. |
| A09 · Concurrencia y auditoría | Parcial | Dos pestañas cargaron la misma versión del árbol. La primera edición guardó; la segunda mostró «El registro cambió» y no sobrescribió el valor vigente. Auditoría mostró antes/después, versión 1→2 y autor administrador. Las pestañas compartían sesión; no se acreditó el subcaso con dos identidades independientes. |
| A10 · Mapa y responsive | Parcial | Filtro ACEP en mapa interno: total 1, en encuadre 1 y sin ubicación 0; marcador correcto y teselas visibles. Escritorio 1280×720: ancho documental 1265; móvil 390×844: ancho 375, sin desbordamiento horizontal de página. No hubo segundo árbol sin coordenadas ni comparación completa de filtros con listado. |
| A11 · Reportes privados | Parcial | OFFICIAL descargó CSV, XLSX, GeoJSON y PDF filtrados por ACEP, más ficha PDF. Una fila/126 columnas en CSV y XLSX, idénticas; GeoJSON corresponde al mismo árbol y medidas. PDF tabular de 1 página y ficha de 10 páginas legibles. No se ejecutó el subcaso de geometría nula del segundo individuo. |
| A12 · Consulta departamental | Completo | DEPARTMENT_ADMIN abre árbol, inspección, mapa y reporte con ámbito departamental; no recibe edición. Accesos directos a `/imports`, `/publication` y `/audit` denegados. OFFICIAL también tiene denegación directa de publicación, auditoría y `/memberships`. Esto verifica esas lecturas, no todas las posibles escrituras ni aislamiento entre dos organizaciones. |
| A13 · Publicación | Parcial | Se autorizó organización, campaña y árbol. La ficha pública inicialmente no mostraba inspección ni foto; cada autorización propia las incorporó. Tras logout, consulta anónima conservó solo datos estructurados, descripción pública independiente y resumen mínimo del mantenimiento. No mostró personal, número legado, notas ni UUID internos examinados. No se ejecutó exclusión de un segundo árbol privado. |
| A14 · Exportación y retirada públicas | Parcial | Descarga anónima CSV/GeoJSON filtrada por código público de campaña, más ficha PDF pública de 8 páginas. Datos y privacidad correctos. Al ocultar fotografía, su URL dejó de entregarla; al ocultar árbol, ficha pública no disponible y mapa/reportes quedaron en cero, conservando ficha privada. No hubo segundo árbol privado para contrastar el subconjunto. |
| A15 · Perfil y logout | Completo | Perfil identifica sesión propia y ámbitos. Logout vuelve a login; solicitar después la ficha privada exige autenticación. Nuevos inicios funcionan. Se observó además renovación por caducidad, pero no se atribuye a este recorrido la prueba de reutilización concurrente del refresh. |

Los mensajes de denegación y conflicto se observaron en la interfaz. No se afirma haber medido un código HTTP concreto de esas páginas cuando solo se leyó su contenido.

## Precisión y archivos revisados

| Campo | Valor persistido/exportado |
| --- | --- |
| CAP (cm) | `154.000000` |
| DAP (m) | `0.490197224723` |
| Radio (m) | `0.245098612362` |
| Área basal (m²) | `0.188725931518` |
| Volumen comercial (m³) | `0.075490372607` |
| Volumen total (m³) | `1.132355589110` |
| Clases / madurez | 10 / 2 / Fustal sobremaduro |

Los GeoJSON privados y públicos contienen Point `[-73.57197222222221, 4.255333333333334]`, longitud antes de latitud. La diferencia respecto del decimal DMS ideal corresponde a representación numérica; no se intercambiaron ejes.

La exportación privada tiene 126 columnas; la pública, 111. CSV y las celdas OOXML guardadas del XLSX privado coinciden exactamente, incluidas cadenas decimales. El XLSX usa `inlineStr` y no fórmulas. En los archivos públicos se comprobó ausencia de los marcadores internos `ACEP-NOTA`, `ACEP-INTERNO`, `ACEP-FOTO`, `ACEP-MANTENIMIENTO`, `ACEP-RESULTADO`, `MANUAL-ACEP`, nombres de las cuentas de desarrollo y UUID internos. La imagen pública utiliza `/portal/media/` con identificador independiente.

Los PDF se renderizaron con Poppler y se inspeccionaron todas sus páginas, sin recortes ni solapamientos. No se certifica accesibilidad PDF. La previsualización de Artifact Tool infiere algunos textos numéricos y abrevia decimales; se contrastó el OOXML original para evitar atribuir esa representación al archivo exportado.

## Defectos corregidos

### Celdas de entrada con formato General

OpenSpout exporta el estilo cero como General incluso cuando su estilo predeterminado declara Texto. La plantilla asignaba ese estilo a entradas, contradiciendo sus instrucciones y poniendo en riesgo ceros iniciales y precisión al editar.

`ImportTemplateService` ahora registra Texto como estilo independiente. La prueba resuelve el índice `s` de cada celda contra `cellXfs`, en lugar de comprobar únicamente que existe algún estilo Texto. Se verificaron las 11.000 celdas preparadas del libro real (110×100), todas con formato `@`.

### Blancos regrabados rechazados

openpyxl 3.1.5 guarda determinados blancos con estilo como `<c t="inlineStr"/>`, sin bloque `is`. La plantilla regrabada, incluso vacía, era rechazada por las hojas auxiliares.

`ImportWorkbookReader` admite únicamente la variante sin ningún nodo hijo. Conserva controles de fórmulas, texto suelto, payload mixto, elementos inesperados, ZIP, XML, namespaces y límites. Pruebas positivas cubren cuatro hojas y filas mixtas; casos adversos incluyen texto oculto, elemento desconocido, `<f/>` y `<v/>`.

El intercambio corregido preservó `000123`, `123.450000`, fecha, fustes y tildes, con cero consultas SQL. El archivo de prueba UI deriva de la descarga real, tiene siete valores textuales y SHA-256 `37d18957929ae3437d0be68a0651546330c3818e5faf5e15086f78665bfdbead`.

### Registro duplicado de Nginx

El `access_log main` de la imagen y `access_log sysforest_safe` estaban declarados en el mismo nivel `http`; ambos registraban solicitudes. Una petición de salud con parámetros y Referer sintéticos demostró que esos valores aparecían en el registro combinado.

La directiva del formato seguro se trasladó al bloque `server`, sustituyendo los registros heredados. No se retiraron registros históricos ni se inspeccionaron credenciales reales. El resultado operativo de la corrección se consigna en el cierre de este informe.

## Pruebas del cambio

- Focal `ImportTemplateTest` y `ImportWorkbookTest`: **27 pruebas / 732 aserciones**, sin fallos.
- Regresión del módulo Import: **122 pruebas / 1697 aserciones**, sin fallos, advertencias ni omisiones; 223,304 s según el runner.
- Pint PSR-12: **4 archivos PHP**, correcto.
- Comprobación de whitespace de los cuatro archivos: correcta. Las copias previas y hashes permiten comparar aun cuando estos archivos pertenecen a trabajo anterior sin commit.
- Base de PHPUnit: exclusivamente `sysforest_acceptance_testing`, con configuración temporal y guard de nombre. Ningún fixture de pruebas se aplicó a la base funcional QA o al piloto principal.

No se volvió a ejecutar toda la suite PHP/JavaScript de Fase 15: los cambios se limitaron al módulo Import y a Nginx, con sus comprobaciones pertinentes. Las cifras históricas de Fase 15 (1806 PHP y 64 JavaScript) permanecen históricas y no se suman a estos resultados.

Comandos PHP ejecutados dentro del contenedor `app` de QA, seleccionado por `.acceptance-work/compose.py`:

```bash
vendor/bin/phpunit -c /tmp/sysforest-acceptance-phpunit.xml tests/Feature/ImportTemplateTest.php tests/Unit/ImportWorkbookTest.php --fail-on-warning --fail-on-skipped --fail-on-risky --display-all-issues --log-junit /tmp/sysforest-acceptance-workbook-focal.xml
vendor/bin/phpunit -c /tmp/sysforest-acceptance-phpunit.xml --filter Import --fail-on-warning --fail-on-skipped --fail-on-risky --fail-on-incomplete --display-all-issues --log-junit /tmp/sysforest-acceptance-import-regression.xml
```

Pint se ejecutó en un contenedor efímero `sysforest-app:local`, usuario 1000:1000, con el proyecto montado en `/workspace`, usando `--preset psr12` y únicamente los cuatro archivos PHP modificados. La copia de la configuración PHPUnit usada se conserva en `workbook/phpunit-qa.xml`; fuerza el nombre de la base aislada tanto en `env` como en `server` y no modifica `phpunit.xml` del proyecto. La instancia QA ya fue retirada; estos comandos documentan la ejecución, no son una instrucción de recrearla sobre la base principal.

## Limitaciones de herramientas y pendientes

1. Excel de escritorio está instalado (versión binaria 16.0.17932.21000), pero la herramienta de control agotó la espera de aprobación al iniciarlo. No se abrió un libro ni se utilizó COM u otra vía para eludirlo. El intercambio con openpyxl no acredita Excel/LibreOffice.
2. La selección del XLSX provocó una pantalla «This page crashed» en dos pestañas del navegador integrado. La recuperación mediante una pestaña nueva reprodujo el problema. En la ventana de diagnóstico, cinco GET de importación devolvieron 200, no hubo POST de importación ni lotes. La causa del cierre del navegador no quedó identificada; no se atribuye al backend.
3. La carga de fotografía y una descarga mediante `downloadMedia` tuvieron demoras largas de la herramienta. Las siguientes descargas mediante evento `download` finalizaron normalmente. No se usan esos tiempos como medición de rendimiento del servidor.
4. Pendiente completar A07/A08 con navegador estable y un editor de escritorio; después cotejar dos individuos (uno sin ubicación y privado), geometría nula, exclusión pública y confirmación/duplicados reales. Pendiente también conflicto con dos sesiones independientes.
5. Fuera de alcance: restauración integral de backup, pruebas de carga, todos los reportes agregados, auditoría de seguridad exhaustiva, certificación de accesibilidad y despliegue externo.

## Evidencia local

Los artefactos de trabajo se conservan en `C:\Users\josev\OneDrive\Documents\ChatGPT\SysForest\.acceptance-work`, fuera del código de despliegue:

- `workbook/verification.md`, JUnit focal/import, copias previas y libros originales/regrabados.
- `artifact-review/review.md`, hashes y comparaciones de CSV/XLSX/GeoJSON; `pdf-review/` y renders de todas las páginas examinadas.
- `public-tree.pdf`, `private-tree.pdf`, `private-inventory.{csv,xlsx,geojson,pdf}`, `public-inventory.{csv,geojson}`.
- `evidence-download-check.json`: original descargado idéntico, SHA-256 `0e339f7a44970bd130e5804884c15344e5bac7f2e710d77819090e09f501226f`.
- `browser-upload-diagnostic.json`, pruebas de privacidad de Nginx y snapshots de metadatos de ambos entornos.

No se incluyen contraseñas, cookies, tokens ni `.env` en este informe. Las capturas del navegador se observaron durante la revisión; los PDF y sus renders son los archivos visuales conservados.

## Archivos de implementación modificados

- `app/Services/ImportTemplateService.php`
- `app/Imports/ImportWorkbookReader.php`
- `tests/Feature/ImportTemplateTest.php`
- `tests/Unit/ImportWorkbookTest.php`
- `docker/nginx/default.conf`
- `docs/acceptance-local.md`, `README.md` y `AGENTS.md` para registrar este resultado sin reescribir informes de fases anteriores.

La arquitectura, los permisos, las fórmulas forestales y la separación Tree/TreeInspection permanecen dentro del contrato aprobado.

## Cierre operativo

Nginx: `nginx -t` aprobó en QA y en el piloto principal. Se efectuaron recargas graduales a las 22:47:27 y 22:47:55 UTC, sin cambiar IDs de contenedor ni reiniciarlos. Una nueva sonda QA devolvió 200 y produjo registro seguro sin query ni Referer sintéticos. `/health/ready` del principal devolvió 200 después de la recarga. Esta corrección no borra logs históricos ni acredita que se hayan examinado.

La instantánea principal capturada durante la revisión y la final coinciden en conteos y huellas de metadatos de las 15 tablas examinadas, incluidas sesiones y auditoría. Se conservan **1 árbol, 1 inspección, 4 evidencias, 1 mantenimiento, 0 lotes de importación y 112 eventos**. La instantánea inicial fue tardía (aproximadamente 22:26 UTC): permite afirmar igualdad desde esa captura, no demostrar por sí sola lo ocurrido antes.

QA terminó con **1 árbol, 1 inspección, 1 evidencia, 1 mantenimiento, 0 lotes, 39 eventos y 7 revisiones de publicación**. Los siete servicios persistentes estaban saludables y los dos inicializadores habían terminado con salida 0.

A las **22:51:22 UTC** se retiró exclusivamente `sysforest-acceptance-20260928`, tras verificar nombres, etiquetas, montajes y ausencia de consumidores ajenos: nueve contenedores, cuatro volúmenes nombrados, el volumen anónimo propio de `db-init` y una red. Se eliminó el directorio temporal de secretos. La comprobación posterior no encontró recursos QA restantes. `cleanup-result.json` conserva las identidades eliminadas sin secretos.

Después de esa limpieza, `docker compose ps` del piloto principal mostró saludables **app, mailpit, nginx, postgres, queue, redis y scheduler**. Continúan los puertos principales 127.0.0.1:8080 y 127.0.0.1:8025. La instancia temporal 8097/8098 ya no está disponible.

Se cerraron las pestañas temporales que respondían y se restauró el viewport original. Las dos pestañas fallidas quedaron sin marca de conservación; la política del navegador impidió operarlas al mostrar su página interna de error. La pestaña original del usuario en `localhost:8080/login` se conservó.

La revisión entrega correcciones verificadas y una lista concreta de aceptación pendiente; no declara aceptación completa del intercambio con Excel y del recorrido de importación en navegador.

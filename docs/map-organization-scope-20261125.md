# Organización activa al abrir Cartografía · 25/11/2026

## Comportamiento acordado

La entrada normal a Cartografía muestra exclusivamente los árboles de la organización activa, también para administradores nacionales y departamentales. Crear o seleccionar otra organización y abrir Cartografía desde la navegación usa esa institución. El comportamiento anterior elegía automáticamente el mayor alcance autorizado y podía mostrar otras organizaciones, aunque el encabezado institucional mostrara una selección distinta.

El panel «Organizaciones en esta vista» indica qué se está consultando. «Ver todas las organizaciones autorizadas» y «Ver organizaciones de mis departamentos» aparecen solo con sus concesiones vigentes. Son elecciones explícitas; no conceden permisos nuevos ni cambian el contexto de escritura. El regreso a «Solo la organización seleccionada» utiliza la institución activa, sin escoger otra automáticamente.

Diagramas/Gráficas y Reportes privados comparten el valor inicial institucional. Los enlaces con `scope` explícito mantienen su alcance exacto, sujetos a reautorización; las descargas siguen usando sus permisos y filtros. El portal público conserva el universo publicado y sus proyecciones independientes.

## Sin organización seleccionada

Las tres páginas HTML presentan «Selecciona una organización» sin consultar árboles. Un administrador con capacidad amplia conserva acceso de navegación y puede elegir expresamente la vista autorizada. Una cuenta institucional puede abrir la página de selección, pero no recibe opciones nacionales o departamentales por esa razón. No se cambió la política previa de ocultar el menú institucional cuando falta contexto.

Los endpoints de datos, búsquedas, opciones y fotografías no recurren a una vista amplia al faltar organización. Sin contexto válido ni alcance amplio explícito autorizado, rechazan la consulta. Las concesiones, pertenencias y permisos actuales siguen siendo la autoridad, no el texto del rol ni un parámetro del navegador.

## Filtros y navegación

Cambiar de vista limpia departamento, municipio, organización e inventario. Se conservan los demás filtros aplicados que sean compatibles con los permisos del destino; se retiran los de inspecciones o mantenimientos cuando no estén autorizados allí. El modo de dibujo se conserva. Los enlaces se actualizan al aplicar filtros, con una URL funcional también sin JavaScript.

«Restablecer mapa» limpia criterios y conserva la vista elegida. Atrás hacia una URL sin `scope` vuelve al valor institucional, incluso si la página anterior era nacional. El cambio global de organización redirige a su ficha y el menú de Cartografía abre el nuevo contexto sin arrastrar el alcance anterior. Un enlace que contiene un alcance explícito continúa representando esa elección.

## Implementación y comprobación

- `MapAccessService::resolve` usa organización como valor inicial; `availableScopes` separa capacidad de navegación y selección actual. `ReportAccessService` reutiliza esa distinción.
- `MapScopeSelectionService` prepara selección y enlaces; los controladores HTML de mapas, reportes y gráficas presentan esa respuesta antes de consultar datos.
- El motor SQL compartido, los permisos, las rutas, los contratos públicos/móviles y las reglas científicas no cambian. No hay migraciones ni escrituras de negocio para esta corrección.
- Pint y sintaxis PHP aprobados en siete archivos; sintaxis JavaScript aprobada en dos archivos y `git diff --check` sin incidencias.
- **58/58 observaciones de integración aprobadas** en PostgreSQL/Laravel aislados con datos ficticios y rollback: organizaciones A/B, puntos/agrupaciones/calor/extensión/búsquedas/opciones/fotos, alcance amplio explícito, reportes/gráficas, ausencia de contexto, permisos de distintos roles, referencias ajenas y revocaciones. Evidencia local: `.map-scope-fix-20261125/qa/integration-observations.json`.
- Observaciones temporales del JavaScript real confirmaron enlaces con filtros compatibles, comportamiento de Atrás y conservación del alcance al restablecer. No equivalen a una prueba visual en navegador. Evidencia: `.map-scope-work/js-observations.json`.
- Android se revisó sin cambiar su código: diez resultados de helpers confirmaron separación por cuenta/inventario, limpieza de filtros, exclusión de lecturas tardías y alcance vacío sin inventario. No se generó APK. Evidencias: `.map-scope-fix-20261125/mobile/review.json` y `manual-observed-values.json`.

## Estado y límites

Fuente preparada y revisión aislada aprobada; **publicación y comprobación productiva pendientes** al redactar este documento. No se migró la base principal ni DB_TEST. No se crearon suites permanentes. No se ejecutó validación Android física ni SQLite nativo; la revisión móvil no acredita revocación remota inmediata mientras el dispositivo está desconectado. La revisión visual web y la verificación HTTPS productiva deben registrarse por separado cuando se realicen.

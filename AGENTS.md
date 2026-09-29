# SYSFOREST: acuerdos de implementación

Los ajustes de usabilidad posteriores a la aceptación local incorporan continuidad de sesión en formularios, calendario del árbol en America/Bogota y orientación explícita para cerrar mantenimientos. La verificación y sus límites se registran en [docs/usability-fixes.md](docs/usability-fixes.md); no usar cifras de fases anteriores como validación de estos cambios. Se conservan permisos, vencimiento absoluto, aislamiento institucional y el contrato ISO de importación.

La revisión de aceptación local del 28/09/2026 corrigió formato Texto de la plantilla XLSX, blancos inlineStr de editores y duplicación del access_log de Nginx. Regresión Import: 122 pruebas / 1697 aserciones; PSR-12 de cuatro archivos PHP aprobado. La instancia QA aislada fue retirada; el piloto principal sigue saludable. Hay pendientes explícitos de Excel de escritorio e importación en navegador por límites de las herramientas; no afirmar aceptación integral. Consulta docs/acceptance-local.md.

La Fase 13 está completada y activada con aprobación expresa del usuario. Su regresión pasó 1668 pruebas PHP y 9898 aserciones, 59 pruebas JavaScript y PSR-12 en 552 archivos; el informe permanece en docs/phase-13.md.
La Fase 14 está completada y activada; su regresión completa pasó 1803 pruebas PHP y 11104 aserciones, 59 pruebas JavaScript y PSR-12 en 576 archivos. Consulta docs/phase-14.md.
La Fase 15 está completada: 1806 pruebas PHP y 11138 aserciones, 64 pruebas JavaScript y PSR-12 en 577 archivos; `bash bin/check` finalizó con código 0. Consulta docs/phase-15.md y docs/phase-15-files.txt.
Se conservaron todos los cambios de Fases 12–14 y la arquitectura aprobada. La rama queda en codex/phase-15, sin commit final ni despliegue externo. Las guías vigentes están en docs/user-guide.md, docs/operations.md y docs/testing.md.
La API pública usa proyecciones explícitas, publicación privada por defecto y el motor común de filtros. No depende de JWT, usuarios ni TenantContext. Nunca expone funcionarios, auditoría, textos internos ni archivos originales con metadatos.
Consolidar el motor de filtros de árboles, última inspección determinista y ANY/ALL. El mapa amplio usa scopes explícitos de lectura sin ampliar TenantContext ni permisos de escritura. PostGIS es la fuente espacial; nunca insertar datos productivos ficticios para llenar el mapa.
Los catálogos nacionales no llevan organization_id; solo SUPER_ADMIN administra, los demás roles consultan.
La semántica forestal del Excel tiene prioridad sobre el mockup. No inventar equivalencias ni valores sin respaldo.
La única fuente de verdad es /home/josev/SYSFOREST. No depender de phase2-staging.

- Laravel 13 y PHP 8.4 en Docker dentro de Ubuntu-24.04 WSL.
- MVC y flujo obligatorio Controller → Service → Repository → Model.
- Los Controllers coordinan; los Services contienen reglas; los Repositories acceden a datos.
- Las comprobaciones técnicas de infraestructura usan DB/Redis desde un Repository sin inventar entidades de negocio.
- Toda interfaz y los reportes/PDF utilizan Blade. CSV, Excel y GeoJSON reutilizarán Services, Repositories y filtros.
- PostgreSQL/PostGIS, Redis, Nginx, PHP-FPM, queue y scheduler en servicios separados.
- Una base multi-tenant con organization_id; Tree separado de TreeInspection e inventory_trees.
- Autenticación JWT con cookies HttpOnly, refresh opaco con hash, rotación y revocación. Tree conserva la identidad; TreeInspection conserva las mediciones y observaciones históricas.
- PHPDoc, comentarios útiles y documentación en español; identificadores técnicos en inglés.
- Formato PSR-12. No comentar cada línea ni repetir lo evidente.
- No consultar desde Blade ni poner reglas de negocio en Controllers o JavaScript.
- Nunca versionar .env, secretos, vendor ni datos de almacenamiento.
- Ejecutar pruebas y formatter al cerrar cada fase. Documentar resultados reales y limitaciones.
- Mantener comandos pequeños, verificables y sin operaciones destructivas sobre datos preexistentes.

Los requisitos del usuario reemplazan los pasos genéricos del esqueleto oficial relativos a PHP 8.5, instaladores del host y Laravel Boost. PHP/Composer se ejecutan en contenedores de PHP 8.4; no hace falta instalar esos componentes en Windows o Ubuntu.

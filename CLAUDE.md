# SYSFOREST: acuerdos de implementación

La Fase 8 fue aprobada y cerrada en el commit f750f66, con 949 pruebas PHP y 4229 aserciones. El usuario autorizó continuar con la Fase 9: mantenimientos manuales e integración con evidencias.
Trabajar en codex/phase-9. No iniciar Fase 10, portal público, importaciones ni reportes finales.
Conservar el cierre F8; dejar los cambios de Fase 9 sin commit final hasta entregar el reporte y recibir revisión.
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

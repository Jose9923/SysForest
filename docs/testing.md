# Verificación del código y revisión manual

Las pruebas automatizadas de PHP y JavaScript fueron retiradas del proyecto por solicitud expresa del usuario. Se eliminaron `tests/` y `phpunit.xml`, el autoload de las clases de prueba y el script `composer test`. Esta decisión no elimina datos, cuentas, bases ni evidencias históricas.

Los informes `phase-*.md`, `acceptance-local.md` y `usability-fixes.md` conservan resultados anteriores a la retirada. Sus comandos y cifras documentan ejecuciones históricas; no describen una suite disponible en el checkout actual.

## Comprobaciones estáticas

Desde `/home/josev/SYSFOREST` en Ubuntu-24.04 WSL:

~~~bash
bash bin/check
~~~

El comando revisa PSR-12, sintaxis JavaScript y sintaxis PHP, en ese orden. No ejecuta Laravel, reglas de negocio, pruebas automatizadas, migraciones ni consultas a bases de datos. Un resultado correcto acredita únicamente estas comprobaciones estáticas.

También permite comprobaciones individuales:

~~~bash
bash bin/check lint
bash bin/check frontend
bash bin/check php
~~~

`lint` usa Pint en modo de comprobación; su argumento `--test` pertenece al formatter y no ejecuta casos de prueba. `frontend` utiliza `node --check` sobre los JavaScript propios de `public/js` y `resources/js`. `php` utiliza `php -l` sobre las carpetas de código PHP y `artisan`, excluyendo archivos de caché.

Se requieren `flock`, Node para JavaScript y Docker con la imagen `sysforest-app:local` para PHP y Pint. No hace falta levantar servicios Compose ni disponer de conexión a PostgreSQL o Redis. El código se monta como solo lectura. Los logs y el código de salida permanecen en `storage/app/checks/<ejecución>/`, excluidos de Git; el bloqueo evita dos ejecuciones simultáneas de `bin/check`.

Para aplicar PSR-12 a archivos elegidos y revisar el diff después:

~~~bash
docker run --rm --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" --workdir /workspace \
  --entrypoint /var/www/html/vendor/bin/pint sysforest-app:local \
  --preset psr12 --cache-file=/tmp/sysforest-pint.cache
~~~

El script `composer lint` y el formatter `composer format` se conservan. No se retiraron dependencias de desarrollo ni se actualizó `composer.lock`: la eliminación de las suites no requiere resolver paquetes nuevos. `package.json` conserva únicamente sus comandos de construcción y desarrollo, sin un ejecutor de pruebas.

## Revisión funcional y visual

La comprobación de comportamiento se realiza de forma manual en un entorno autorizado. Revisa inicio y cierre de sesión, conservación de formularios, calendario, roles, organización activa, registros forestales, cierre de mantenimientos, filtros, archivos y reportes según el cambio. No fabriques registros operativos para completar una revisión visual.

Comprueba escritorio y móvil, foco visible, etiquetas, navegación por teclado, mensajes de validación y controles Select2. Una tabla puede desplazarse dentro de su contenedor; la página completa no debe ensancharse por contenido oculto o un enlace largo.

La [guía de operaciones](operations.md) documenta disponibilidad de servicios, logs, PostgreSQL/PostGIS, Redis, cola y scheduler. Los healthchecks se conservan porque supervisan los procesos necesarios para operar la aplicación.

## Verificación de riesgo carbono y sincronización

Esta lista describe comprobaciones a ejecutar en un entorno aislado autorizado; no acredita que ya se hayan realizado. La autorización de pruebas temporales no restablece una suite permanente. Registra datos de entrada, resultado real y diferencias, y conserva los informes históricos sin reutilizar sus cifras como evidencia nueva.

1. Crear y corregir una visita web con confirmación profesional, condiciones contradictorias, captura sin condiciones evaluables y condiciones excluidas de la metodología. Comprobar sugerencia ausente y aviso, cobertura y justificación cuando correspondan.
2. Rechazar matrices nuevas que reduzcan la prioridad al aumentar severidad o extensión; comprobar que las versiones históricas permanecen intactas y que una institución no modifica otra.
3. Publicar y despublicar una inspección. Comparar ficha, mapa, filtros, agregados y archivos: conservar derivados vigentes, autor y fecha de confirmación originales, sin sobrescribir instantáneas anteriores. Confirmar que el portal no expone los nuevos datos privados.
4. Incluir una especie sin estimaciones en la gráfica de carbono y descargar PNG, CSV y Excel. Mantener **Sin estimación** o ausencia en lugar de cero. Probar exclusión de muertos y selección única de última visita compatible.
5. Solicitar dos veces la actualización institucional de carbono; comprobar una sola tarea activa, progreso, reintento sin duplicados y transacciones breves. Revocar permiso o publicar una referencia durante el trabajo y revisar la cancelación autorizada y su motivo.
6. Consultar inspecciones por API móvil con y sin permiso de riesgo. Probar compatibilidad con respuestas anteriores, cola offline, edición local y recuperación posterior de derivados. Repetir POST/PATCH con el mismo recibo: su contenido confirmado se conserva; GET obtiene el estado vigente.
7. Revisar manuales Word renderizados en todas sus páginas y contrastar los campos de API con la guía. Las capturas históricas ilustran controles de su fecha y no certifican la interfaz nueva.

## Alcance de la retirada

La copia recuperable de las pruebas y sus hashes se conserva fuera del repositorio, en la carpeta de trabajo local `.ornato-work`. No se borraron logs, informes, capturas o archivos generados anteriormente. La base de pruebas y sus registros tampoco fueron eliminados, ni se modificaron seeders o permisos por esta retirada.

No quedan instrucciones vigentes para ejecutar PHPUnit o Node como suites. Si posteriormente se decide recuperar las pruebas, primero deben restaurarse sus archivos y configuración de la copia, y revisarse su compatibilidad con el código existente; no basta ejecutar un comando de los informes históricos.

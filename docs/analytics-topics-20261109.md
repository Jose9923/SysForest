# Gráficas organizadas por tema — 09/11/2026

El tablero privado `/analytics` separa composición y estado, condiciones fitosanitarias, condiciones físico-mecánicas, gestión del riesgo, carbono, tamaño del arbolado, mantenimientos, inventarios y territorio, e inspecciones e historial. Solo aparecen apartados autorizados. «Ver todos» conserva los encabezados y el orden por tema. Carbono almacenado y su cobertura comparten apartado.

Los filtros se organizan de forma equivalente; territorio, fechas y coincidencia global se identifican como criterios compartidos. Fitosanitario y físico-mecánico tienen bloques independientes y un único control ANY/ALL que sigue combinando las dos evaluaciones sobre la misma última inspección. Elegir órganos sin condiciones sigue limitando series, no árboles. No se inventan filtros propios de carbono.

Los títulos y contexto de las tarjetas y PNG están centrados. Las etiquetas largas se reparten en líneas completas, reservando la misma altura suficiente para cada categoría. Las leyendas de tortas se presentan como texto adaptable fuera del canvas; el PNG conserva las claves de color y los valores. Los recursos propios se versionan por fecha de archivo para evitar reutilizar JavaScript o CSS anteriores en caché.

Cuando hay un solo órgano seleccionado, el contexto muestra ese órgano y las barras dicen «Sano», «Hongos», etc. Con varios órganos se conserva «Órgano · condición» para distinguirlos. Filas, columnas, selección y conteos permanecen canónicos; CSV y PNG conservan el contexto. Las tablas y descargas del reporte no cambian.

«Tamaño del arbolado» es un apartado técnico opcional. La etiqueta anterior «Clasificación forestal · versión 1 · 3» concatenaba versión y número de clase; no significaba una versión 1.3. Ahora aparece «Clase 3» y, con una única regla, la versión figura aparte. Cuando coexisten reglas, cada etiqueta mantiene su versión, incluidas filas sin dato. No se recalculan clases, no se mezclan versiones ni se modifican reglas científicas.

## Verificación

- Formato PSR-12, sintaxis PHP (658 archivos), JavaScript y `git diff --check` aprobados. Cambios finales de JS revisados nuevamente de forma dirigida.
- 37 comprobaciones manuales de integración aprobadas en PostGIS efímero aislado, con fixture ficticio y fuente estable. Incluyen filtros, población, selección por órgano, ceros, permisos, contratos públicos, clases, secciones únicas, columnas/CSV y render Blade.
- 8 observaciones manuales aisladas del helper de etiquetas aprobadas; conserva ceros, nombres y contexto.
- El formulario conserva las firmas de sus 38 controles autorizados y 23 restringidos; IDs únicos y un solo modo de coincidencia. Sin consultas de negocio en la comprobación de Blade.
- Revisión visual real con Chart.js y Select2 en navegador a 1440×1000 y 390×844: títulos centrados, textos completos, navegación temática, dos tarjetas de carbono juntas, leyenda adaptable, clases con versión separada, selección vacía y varios órganos. Sin desbordamiento horizontal observado ni errores de consola. PNG descargado e inspeccionado con título/contexto y clave de color.
- La previsualización usa datos ficticios: las opciones locales y descargas CSV/PNG funcionan, pero aplicar filtros y navegar a reportes requiere el backend real. No representa una prueba en Android nativo.
- Sin suites permanentes, nuevas dependencias, migraciones ni seeders. El cambio no modifica Flutter ni los contratos de captura/mapas/API pública.

## Publicación

Pendiente de activación al registrar la verificación local. El manifiesto, respaldo y comprobaciones HTTP de producción se registran al completar la entrega.

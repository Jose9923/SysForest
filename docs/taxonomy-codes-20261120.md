# Códigos internos automáticos de taxonomía

## Alcance

Solicitud del 20/11/2026: quitar ORNATO de los códigos existentes en Familias, Especies y Nombres comunes, y evitar que el usuario tenga que inventar un código al registrar esos catálogos.

Las altas web omiten el campo de captura y explican que el código se asigna al guardar. Los formatos internos de SysForest son `FAMILY_000001`, `SPECIES_000001` y `COMMON_000001`, con un contador nacional independiente por catálogo y un mínimo de seis dígitos. No representan un estándar botánico oficial. El código continúa siendo estable al editar el nombre. Se conservan permisos, padres activos, nombre principal y control de versiones.

`TaxonomyCodeRepository` reserva el consecutivo dentro de la misma transacción del alta. Un alta fallida revierte la reserva; las peticiones concurrentes se serializan sobre el contador. Se comprueban colisiones contra códigos actuales y alias históricos. Las peticiones antiguas con código explícito válido mantienen compatibilidad; el formulario nuevo no lo solicita.

## Migración y compatibilidad

La migración incremental `2026_11_20_000038_add_taxonomy_code_sequences_and_aliases` añade tres contadores y una tabla de alias inmutables con claves foráneas tipadas. No renombra datos existentes. Su reversión se rechaza si ya existen alias.

Los alias permiten que una importación o un enlace público anterior siga resolviendo la misma identidad. Los selectores y las exportaciones muestran el código actual. Las precargas taxonómicas y bibliográficas mantienen sus manifiestos, hashes y comprobaciones de nombre/padre; no reescriben perfiles ni cálculos de carbono. No se cambian paquetes fuente, fórmulas, UUID, IDs BIGINT, árboles, fotografías, inspecciones, recibos o historiales existentes. La sincronización móvil conserva su contrato y obtiene los códigos vigentes; esta entrega no modifica Flutter ni genera APK.

## SQL de normalización

Archivo: `database/operations/standardize-taxonomy-codes.sql`.

1. Respaldar la base y publicar primero el código compatible.
2. Aplicar únicamente la migración pendiente mediante `php artisan migrate --force` con la conexión de migraciones configurada. No usar `migrate:fresh` ni sembrar árboles.
3. Ejecutar el archivo SQL completo con el propietario/migrador, usando un cliente que detenga la ejecución ante errores (`psql -X -v ON_ERROR_STOP=1 -f ...`). La cuenta `sysforest_editor` de phpPgAdmin no tiene permisos para deshabilitar triggers y no sirve para esta operación.
4. Comprobar el resultado y el `COMMIT`, y luego sincronizar los catálogos móviles.

El SQL bloquea los tres catálogos y la infraestructura de códigos dentro de una transacción. Cambia únicamente códigos que contienen ORNATO; avanza versión y fecha, conserva preimágenes de los demás atributos, añade alias y eventos de auditoría, y restaura las tres protecciones de código. Las claves foráneas permanecen activas. No altera secuencias de IDs ni registros relacionados. Una segunda ejecución sin códigos ORNATO es inocua. Los códigos anteriores se conservan exclusivamente para compatibilidad y auditoría; no se borra su procedencia histórica.

La consulta productiva de solo lectura encontró 45 familias (37 códigos ORNATO), 143 especies (133) y 145 nombres comunes (135). Estas cifras describen la consulta; el SQL calcula su alcance en la transacción y evita colisiones con altas posteriores.

## Acceso local al visor

phpPgAdmin estaba saludable en el servidor. El error de `http://127.0.0.1:18084` correspondía a un túnel SSH local desconectado. Se restauró el reenvío y se comprobó HTTP 200 desde Windows con contenido de phpPgAdmin. El servicio sigue publicado solo en loopback; no se abrieron puertos públicos ni se modificaron credenciales. El túnel depende de la sesión/proceso local y puede necesitar reconexión al reiniciar Windows/WSL.

## Estado

Implementación y SQL preparados localmente. No se han publicado estos cambios, aplicado la migración 000038 en producción ni ejecutado allí la normalización. No se atribuye revisión visual interactiva ni prueba física Android.

## Verificación aislada

Se usó PostgreSQL/PostGIS y PHP 8.4 en contenedores desechables, sin puertos publicados, con una red interna y almacenamiento propios. Se creó una base de cero y se aplicó BaseSeeder; no se restauraron ni modificaron la base principal, DB_TEST o la base productiva. Los árboles del Plan de Ornato no se sembraron.

- Migraciones hasta 000038 y catálogo base: correctos.
- 18 comprobaciones de servicios: alta con código ausente/nulo/blanco, duplicado asociado al nombre visible, reserva revertida al fallar, estabilidad del código, versión obsoleta rechazada, alias reservados, inmutabilidad de alias y permisos nacionales.
- Cuatro reservas concurrentes: cuatro códigos diferentes y avance exacto de cuatro en el contador.
- SQL: 37 familias, 133 especies y 135 nombres comunes normalizados; cero códigos ORNATO vigentes. Huellas de identidades y demás atributos conservadas y tres triggers de código habilitados al terminar.
- Repetición del SQL: cero cambios y seis tablas —incluidos contadores, alias y eventos— idénticas.
- Importación: el código anterior y el actual resuelven la misma especie; se acepta la selección con etiqueta correcta y se rechazan etiquetas incorrectas y padres inactivos.
- Portal público: los tres códigos anteriores se normalizan a los actuales y las opciones seleccionadas conservan sus etiquetas.
- Precargas: 16 observaciones de guardas de Extended/Restrepo aprobadas; repetición de BaseSeeder sin cambios en los catálogos, alias, usuarios o asignaciones existentes. No se ejecutó la carga completa de árboles de Restrepo.
- Referencias de carbono: 143 coincidencias después de renombrar, repetición sin alterar perfiles, discrepancias de nombre/familia detectadas y ningún cálculo ejecutado. Cinco observaciones aprobadas y seis tablas conservadas después de revertir la transacción de prueba.
- Formato PSR-12 y sintaxis en 11 archivos PHP, compilación/sintaxis de la vista Blade y `git diff --check`: aprobados. No se agregaron suites permanentes.

Las observaciones combinadas de importación, portal público y BaseSeeder sumaron 27 comprobaciones aprobadas. Al finalizar se retiraron únicamente los dos contenedores, la red interna, el volumen y los secretos temporales propios del ensayo; se conservaron las evidencias. Los servicios y datos principales/productivos no se modificaron.

La huella SHA-256 del SQL ensayado es `75b6a8f36e376d4de42bf58147b01d120baca724e97b0b12b7c27ea729aeccf6`. Los prefijos numéricos no dependen del nombre, y las cuatro reservas concurrentes de QA explican por qué las familias del ensayo comienzan en 000005. En producción todavía no se han reservado estos consecutivos.


## Ampliación posterior: códigos de los seeders

El usuario pidió extender el cambio a los seeders y confirmar la conservación del inventario y los árboles actuales. La migración 000038 sigue siendo aditiva: no elimina ni recrea tablas de negocio ni ejecuta seeders. Para una actualización se aplica `migrate`, nunca `migrate:fresh`.

Las nuevas referencias de TaxonomySeeder y ExtendedTaxonomySeeder reciben códigos numéricos mediante TaxonomyRepository::seed. La reserva, el registro y el alias de procedencia quedan en la misma transacción. El bloqueo del contador precede a la segunda consulta de la referencia fuente, para evitar altas duplicadas simultáneas. Los catálogos ya existentes se reutilizan sin cambiar sus códigos, nombres, padres, versiones o decisiones administrativas. El SQL separado continúa siendo el encargado de normalizar los códigos ORNATO existentes.

La preparación taxonómica explícita de Restrepo usa el mismo generador y conserva la autorización y auditoría de TaxonomyService; el evento registra el código actual. Los tres códigos fuente se guardan como alias y los mapas en memoria se actualizan durante la carga. Se conservan los manifiestos y sus hashes, la procedencia de los datos y las validaciones de nombre, padre y vigencia.

`BaseSeeder` sigue cargando catálogos y preparando/conservando el administrador, sin árboles, visitas ni inventarios piloto. Esta ampliación no cambia el alcance del antiguo `DatabaseSeeder` ni ejecuta las importaciones del Plan de Ornato: para la carga base se selecciona explícitamente `BaseSeeder`.

Se ensayó una actualización genuina desde el código 098aab9 en otra base desechable, con un inventario, árbol, visita y recibo ficticios. La migración 000038 por sí sola conservó once tablas exactamente. Repetir BaseSeeder antes de la normalización conservó los códigos antiguos. El SQL normalizó 37 familias, 133 especies y 135 nombres comunes preservando ocho tablas de negocio/usuario/recibo y las identidades/relaciones de los catálogos. El seeder posterior conservó once tablas. No se usó fresh sobre esa base existente.

Diez observaciones adicionales comprobaron las 143 referencias de carbono (incluidas las diez especies base ahora resueltas por alias), su repetición, el alta taxonómica auditada de Restrepo y la repetición sin altas. Se revirtieron íntegramente nueve tablas al terminar las observaciones. No se ejecutaron cálculos de carbono ni la importación completa de árboles de Restrepo. Los cuatro archivos PHP cambiados en esta ampliación aprobaron PSR-12 y sintaxis; los paquetes fuente siguen idénticos.

La instalación nueva aprobó 18 observaciones: 45 familias, 143 especies y 145 nombres comunes, todos con códigos estándar, y 333 alias de procedencia. Repetir BaseSeeder conservó ocho tablas; el siguiente alta continuó los tres contadores y la transacción revertida conservó filas y consecutivos. Se retiraron los tres contenedores propios, la red, el volumen, los secretos y el archivo temporal del código anterior. No se ensayó concurrencia específica de seed() en esta ampliación; su bloqueo y segunda consulta se revisaron, y la prueba previa de cuatro reservas concurrentes corresponde únicamente a nextCode().

La ampliación permanece local: no se ha publicado ni ejecutado esta migración, normalización o reseed en producción.

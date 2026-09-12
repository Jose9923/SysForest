# Georreferenciación de Tree

## Datos y referencia espacial

La Fase 6 guarda un punto PostGIS y su evidencia textual de captura. `trees.geometry` es geometry(Point,4326), bidimensional y nullable. PostGIS almacena X=longitud, Y=latitud; la entrada humana y Leaflet usan latitud, longitud. No se crean columnas latitude/longitude duplicadas: los repositorios obtienen ST_Y/ST_X como atributos de consulta.

| Campo | Persistencia | Regla de captura |
| --- | --- | --- |
| coordinate_raw | text, nullable | Texto original recibido por el parser, con hemisferios y formato conservados. |
| coordinate_format | varchar(20), nullable | decimal o dms, determinado por backend; no es un campo editable del cliente. |
| datum | varchar(80), nullable | WGS84 cuando fue declarado; NULL si se desconoce. |
| accuracy_m | numeric(12,3), nullable | Precisión informada en metros, no negativa, hasta tres decimales. No se estima desde el mapa. |
| gps_brand | varchar(100), nullable | Marca informada; Garmin es una sugerencia operativa. |
| gps_model | varchar(100), nullable | Modelo conocido, sin un valor de ejemplo convertido en dato real. |
| captured_at | timestamp(6) con zona, nullable | Fecha/hora conocida; almacenamiento normalizado a UTC y hasta microsegundos. |

El servicio admite accuracy_m hasta el límite de almacenamiento 999999999.999; la validación operativa de formularios limita la entrada a 999999.999. El servicio limita coordinate_raw a 2000 caracteres y el flujo HTTP a 500; no se trunca texto silenciosamente.

Un Tree puede carecer de punto. También pueden declararse metadatos conocidos, como la marca del equipo, sin inventar coordenadas. Dejar la información desconocida en NULL evita convertir una ausencia en una precisión, fecha o equipo supuestos.

WGS84, WGS 84 y WGS-84 se normalizan como WGS84. Desconocido, unknown, cadena vacía o NULL se representan como NULL. Otros datums se rechazan con un error comprensible porque esta fase no implementa transformaciones entre sistemas. No se afirma que el SRID de trabajo demuestre el datum original del levantamiento: cuando datum es NULL, la interfaz advierte que se usa WGS84 como referencia de trabajo sin certificación de origen.

La configuración temporal de la aplicación es UTC. Una fecha ISO con offset explícito conserva el instante al normalizarse; por ejemplo, 2026-09-12T14:30:00-05:00 corresponde a 19:30 UTC. Una fecha/hora sin zona se interpreta en la zona configurada de la aplicación. No se rellena captured_at con la fecha de creación si el dato no fue suministrado.

## Parser y formatos admitidos

CoordinateParserService::parse(string $raw): ParsedCoordinates valida toda la cadena. No extrae un par aparentemente válido de una entrada que contenga texto adicional, otro par o una coordenada incompleta.

Ejemplos:

| Formato | Entrada | Latitud normalizada | Longitud normalizada |
| --- | --- | --- | --- |
| Decimal | 4.154, -73.633 | 4.154 | -73.633 |
| DMS | 4°15'19.2" N, 73°34'19.1" W | 4.255333333333333 | -73.57197222222223 |
| DMS | 4°15'19.2" S, 73°34'19.1" E | -4.255333333333333 | 73.57197222222223 |

La conversión DMS es grados + minutos/60 + segundos/3600; S y W aplican el signo negativo. Los dos ejes se validan por su hemisferio, no solo por la posición numérica. La segunda fila es el ejemplo requerido en Fase 6 y no sustituye el ejemplo histórico del Excel.

El formato decimal utiliza punto decimal y coma entre ejes. El parser acepta signos y espacios exteriores conservando raw; rechaza una coma usada como separador decimal, notación científica, NaN, INF y valores incompletos. La latitud está en [-90,90] y la longitud en [-180,180]. Incluso una fracción ínfima que exceda 90 o 180 se rechaza antes de que un float pueda redondearla al límite.

DMS exige grados enteros, minutos enteros de 0 a 59, segundos de 0 inclusive a 60 exclusivo y hemisferios N/S para latitud, E/W para longitud. Se aceptan minúsculas, primas Unicode y grados °/º. Los hemisferios permiten separar los ejes con coma o espacio. No se acepta mezclar signo negativo y hemisferio ni intercambiar los hemisferios de los ejes. En 90° o 180° los minutos y segundos deben ser cero.

Los errores se entregan como ValidationException bajo coordinate_raw, en español. ParsedCoordinates es un DTO readonly con latitude/longitude float, raw string y format decimal|dms. toArray() y la serialización JSON usan exactamente esas cuatro claves.

Los números enviados a PostGIS se serializan con precisión suficiente antes de enlazarlos. No se usa la conversión abreviada habitual de float a string de PDO, que podría alterar un DMS al guardarlo y producir un cambio falso cuando se reenvía.

## Ubicación actual e historial

TreeLocationService::apply(Tree $tree, User $actor, array $input, bool $initial=false): bool participa en la transacción ya autorizada del árbol. TreeLocationRepository vuelve a resolver el snapshot mediante organization_id e id y lo bloquea. Llamar este servicio no sustituye la autorización ni el control de versión de TreeService/TreeWriteService.

Los campos reconocidos son coordinate_raw, datum, accuracy_m, gps_brand, gps_model, captured_at, location_change_type y location_reason. Omitir un campo conserva su valor; enviar NULL explícitamente lo vacía. Un texto de coordenadas vacío equivale a limpiar el punto, raw y formato. Los metadatos omitidos permanecen.

Una captura inicial con algún dato real genera historial con change_type=NULL y old_geometry/old_coordinate_raw=NULL. Si no se proporciona ningún dato de ubicación ni metadato, no se crea una observación ficticia. Para cambios posteriores se exige un tipo y motivo no vacío:

| Tipo | Significado |
| --- | --- |
| correction | Corrección de coordenadas o metadatos. |
| relocation | Traslado informado. |
| transplant | Trasplante informado. |

La captura inicial no se etiqueta como correction. Una restricción parcial permite una sola fila inicial por Tree. Las filas posteriores conservan old_geometry, new_geometry, old_coordinate_raw, new_coordinate_raw, old_metadata, new_metadata, changed_by, changed_at, change_type y reason.

Los objetos JSONB de metadatos incluyen coordinate_format, datum, accuracy_m, gps_brand, gps_model y captured_at. El estado previo inicial es un objeto vacío; no se inventa un equipo ni una referencia previa. Las geometrías anteriores se copian directamente desde PostGIS, sin volver a interpretar el texto original. Limpiar el punto mantiene su valor anterior en el historial.

Un cambio solo de precisión, fecha, equipo o datum también es un cambio de ubicación documentado. Cambiar únicamente la redacción del texto raw queda trazado aunque represente el mismo punto. Reenviar el mismo DMS, una precisión equivalente como 3.5/3.500 o el mismo instante con otro offset no añade historial tras normalización. Cambiar solo el motivo o el tipo sin modificar datos tampoco crea una fila.

## Historial y atomicidad

La ubicación, su historial y el evento de seguridad tree.location_changed se confirman dentro de la transacción del árbol. Si falla una operación posterior, se revierten todos. El evento contiene actor, organización y resource_id/resource_code; las coordenadas y metadatos completos pertenecen al historial forestal, no se copian a logs de seguridad.

El servicio devuelve true cuando modifica información y false cuando no hay cambios. No incrementa lock_version: TreeWriteService realiza una única actualización de versión para toda la operación. Los helpers de persistencia requieren una transacción activa y no son endpoints independientes.

Las FK compuestas (organization_id,tree_id) enlazan cada historial con un Tree de la misma organización. Sus geometrías mantienen Point4326 y límites válidos. Un CHECK exige que ambos metadatos sean objetos JSONB. El historial es append-only por trigger: no se corrigen ni se eliminan filas anteriores.

## Consultas espaciales

TreeLocationRepository usa SQL parametrizado y aplica organization_id a cada consulta. El argumento de organización procede del contexto autorizado; el repositorio no decide por sí solo los permisos RBAC.

| API | Resultado y criterio |
| --- | --- |
| withinBoundingBox(orgId, west, south, east, north, inventoryId=null) | Eloquent Collection de árboles que intersectan la caja; bordes inclusivos. |
| withinRadius(orgId, latitude, longitude, meters, inventoryId=null) | Collection ordenada por distance_m e id, limitada al radio geográfico en metros. |
| nearest(orgId, latitude, longitude, limit=10, inventoryId=null) | Collection de proximidad ordenada por distance_m e id; limit entre 1 y 100. |

El inventario opcional se comprueba mediante la relación y su organization_id. Un inventario de otra organización produce una colección vacía; un identificador mal formado se rechaza. Los árboles anulados se excluyen mediante SoftDeletes. Los árboles sin geometry no aparecen como si estuvieran en 0,0.

Las cajas emplean ST_Intersects y ST_MakeEnvelope en SRID4326. Se rechazan límites invertidos; para atravesar el antimeridiano el llamante debe dividir la consulta en dos cajas. No se aplica una conversión métrica a grados.

Los radios y distancias usan ST_DWithin/ST_Distance sobre geometry::geography. Su resultado está en metros, no en grados ni en coordenadas de pantalla. Un radio de cero es válido para coincidencias; los radios negativos y no finitos se rechazan. nearest tampoco trae todos los árboles a PHP para ordenar distancias.

Existen índices GiST separados: trees_geometry_gist sobre geometry y trees_geography_gist sobre la expresión geometry::geography. El primero sirve a búsquedas por caja y el segundo a búsquedas métricas.

## Mapa individual y flujo de captura

La interfaz usa Leaflet local para el punto individual. Pegar o editar coordenadas llama POST /coordinates/preview, protegido por autenticación, contexto institucional, permisos de captura y limitación de frecuencia. CoordinatePreviewService usa el mismo parser del guardado; esta llamada no persiste un árbol ni un historial.

El navegador muestra la respuesta validada y puede actualizar el texto decimal al arrastrar o seleccionar un punto. Guardar vuelve a validar todo en el servidor y exige una versión vigente. Una respuesta de previsualización no autoriza otra organización ni confirma la exactitud del GPS.

La cartografía de fondo depende de teselas de OpenStreetMap. Si no hay red o falla una tesela, el texto de coordenadas se conserva y el fallo se informa. La ficha sin coordenadas no crea un marcador ficticio. No hay mapa general, mapa de calor ni cálculo de superficie en esta fase.

## Verificación

CoordinateParserTest tiene 55 casos y 151 aserciones verificados: decimales, DMS, N/S/E/W, signos, Unicode, límites exactos, entradas incompletas y serialización.

TreeSpatialTest pasó **19 pruebas y 120 aserciones** sobre PostgreSQL/PostGIS de testing. Verifica: Point4326 con ejes correctos, GiST, bbox, metros, proximidad, filtro institucional y de inventario, exclusión de anulados, precisión de DMS y microsegundos, metadatos, captura inicial, cambios, limpieza explícita, no-op, auditoría y rollback. Sus resultados finales y los de toda Fase 6 se registran en [phase-6.md](phase-6.md).

La exactitud del levantamiento, transformaciones de otros datums, altitud, corrección diferencial y validación territorial avanzada no se deducen de la geometría almacenada. No forman parte de esta implementación.

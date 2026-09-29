# Catálogo territorial de Colombia

`TerritorySeeder` carga el catálogo nacional sin depender de Internet durante su ejecución. La interfaz existente usa estos registros para escoger departamentos y municipios; Select2 permite buscar el nombre del municipio junto con el departamento y el país.

## Fuente y alcance

La copia versionada `database/seeders/data/colombia-territories.json` procede del [servicio DIVIPOLA del DANE, Marco Geoestadístico Nacional 2025](https://geoportal.dane.gov.co/mparcgis/rest/services/Divipola/Serv_DIVIPOLA_MGN_2025/FeatureServer). Sus capas oficiales son [Departamento, 319](https://geoportal.dane.gov.co/mparcgis/rest/services/Divipola/Serv_DIVIPOLA_MGN_2025/FeatureServer/319) y [Municipio, 317](https://geoportal.dane.gov.co/mparcgis/rest/services/Divipola/Serv_DIVIPOLA_MGN_2025/FeatureServer/317). La fecha de consulta y las huellas SHA-256 de las respuestas originales están en `source`.

La cobertura incluye **32 departamentos y Bogotá D.C.**, ubicada en el mismo nivel de selección de la jerarquía existente. Hay **1.104 opciones**: 1.103 registros que DIVIPOLA clasifica como `MUNICIPIO`, incluidos distritos, y San Andrés, clasificado como `ISLA`. El tipo de origen se conserva en el JSON. Meta dispone de sus **29 municipios**.

Las 18 áreas no municipalizadas de la respuesta oficial no se presentan como municipios. Sus códigos y nombres quedan identificados en `source.excluded_non_municipalized_areas`. Incorporarlas como otra clase de jurisdicción requeriría ampliar expresamente el modelo y sus etiquetas. No se importan centros poblados ni geometrías o límites territoriales.

Los códigos DANE se conservan como texto de dos y cinco dígitos, incluidos los ceros iniciales. Los nombres nuevos mantienen la escritura de la fuente; los territorios preexistentes conservan sus nombres y demás atributos.

## Ejecución

Desde `/home/josev/SYSFOREST`:

```bash
docker compose exec -T app php artisan db:seed --class=TerritorySeeder
```

Ya se ejecutó en el piloto local. También forma parte de `DatabaseSeeder` para instalaciones nuevas; no hace falta ejecutar la siembra general para actualizar únicamente este catálogo.

El flujo `TerritorySeeder → TerritorySeedService → TerritorySeedRepository → Models` separa coordinación, reglas y consultas. La validación del archivo ocurre antes de escribir: estructura, códigos únicos, tipos incluidos y correspondencia entre cada municipio y su departamento. Una transacción y el bloqueo del país serializan la siembra. Solo se crean registros ausentes; no se reemplazan UUID, nombres, activación, geometrías ni relaciones de los existentes.

La carga no concede acceso a nuevas instituciones ni cambia roles. Los selectores nacionales pueden consultar el catálogo completo; los mapas departamentales, institucionales y públicos mantienen sus filtros y permisos. Si no aparece una organización en otro municipio, eso depende de que exista y del acceso del usuario, no de la lista de municipios.

## Resultado local verificado

- Antes: un país, un departamento y dos municipios.
- Después: un país, 33 registros de nivel departamental y 1.104 opciones municipales.
- Se añadieron 32 departamentos y 1.102 municipios; Villavicencio y Restrepo, Meta, conservaron sus registros.
- Los mismos Services y Repositories usados por los formularios devuelven 33 departamentos, 1.104 municipios y 29 municipios de Meta.
- La segunda ejecución no creó ni modificó registros.
- Las huellas de territorios previos, organizaciones, campañas, árboles, inspecciones, participaciones, evidencias, mantenimientos, usuarios y lotes/filas de importación permanecieron iguales.
- Se revisaron el formato PSR-12 y la sintaxis de los archivos PHP modificados. No se crearon ni ejecutaron suites automatizadas.

El respaldo previo está en `/home/josev/.local/state/sysforest/backups/before-territories-20260929T030417Z.dump`. Su archivo es legible por `pg_restore --list`; no se ejecutó una restauración.

Los resultados operativos y respuestas originales de esta entrega están en `.territory-work` del espacio de trabajo de Windows. La fuente publicada es la edición 2025; el seeder no consulta actualizaciones remotas automáticamente.

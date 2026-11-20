# Extracto de referencias de carbono

Precarga solicitada por el usuario el 25/10/2026 para las 143 especies del catálogo local. No modifica nombres taxonómicos ni mediciones. Cada entidad recibe copias independientes de sus perfiles; una parametrización posterior tiene prioridad sobre la precarga.

## Fuente y atribución

Fischer, F. J., Chave, J., Zanne, A., Jucker, T., Fajardo, A., Fayolle, A. y colaboradores (2026). **Global Wood Density Database v.2**, entrega GWDD v2.2. DOI de esta versión: [10.5281/zenodo.18262736](https://doi.org/10.5281/zenodo.18262736); DOI del conjunto: [10.5281/zenodo.16919509](https://doi.org/10.5281/zenodo.16919509). Publicación asociada: [10.1111/nph.70860](https://doi.org/10.1111/nph.70860).

Licencia de la fuente: [Creative Commons Attribution 4.0 International](https://creativecommons.org/licenses/by/4.0/). Cambios efectuados: extracción de los registros usados en el catálogo local y construcción de correspondencias explícitas, estados y límites de aplicación. No se modificaron los valores originales de densidad.

Archivo consultado: `gwddagg_v2.2_binomial.csv`, obtenido desde el repositorio oficial. SHA-256: `d7158322fa9b45e64d55a72990932630186c5dc5ddfb1d5cb71dda5fd49e975d`.

`carbon-gwdd-extract.csv` conserva las filas originales seleccionadas, incluyendo familia, grupo, conteos y las columnas de estimación. SHA-256: `d10e58a9ee50b8dbe885e47e463d090df6d8359cbbf6c5f16bd66e815b2d21ad`.

Se usa únicamente **wsg_est_trunk**, estimación modelada para tronco, en g/cm³. Los autores distinguen esta columna del promedio crudo `wsg_raw`, cuyo uso práctico desaconsejan para especies poco muestreadas. No se usa el promedio de género ni se rellenan especies por semejanza de nombres. Los decimales de almacenamiento no implican precisión científica adicional.

## Correspondencias revisadas

Los códigos y nombres completos del catálogo están fijados en `carbon-species-references.json`. La extracción de autores del nombre se efectuó durante la preparación; el seeder no ejecuta emparejamiento difuso. Para publicar resuelve una clave fuente o su huella histórica a la misma identidad y exige coincidencia exacta de nombre completo y familia frente al catálogo actual. No usa coincidencias aproximadas.

Tres correspondencias adicionales tienen respaldo explícito:

- Licania tomentosa → Moquilea tomentosa: [Kew POWO](https://powo.science.kew.org/taxon/urn:lsid:ipni.org:names:726782-1).
- Schefflera morototoni → Didymopanax morototoni: [Kew POWO](https://powo.science.kew.org/taxon/914555-1).
- Trema micrantha → Trema micranthum: [Kew MPNS](https://mpns.science.kew.org/mpns-portal/plantDetail?dbs=wcsCmp&nameType=all&plantId=2518720).

Estas correspondencias sirven para localizar densidad; no renombraron especies ni fusionaron identidades del catálogo.

## Estados y límites

El paquete contiene 92 especies con referencia bibliográfica provisional para cálculo, 24 que requieren otra metodología, 16 sin coincidencia específica de densidad respaldada y 11 con identificación pendiente. Los casos pendientes conservan valores nulos.

Los límites iniciales de las referencias aplicables proceden de la envolvente global del [conjunto de cosecha de Chave et al. (2014)](https://jeromechave.github.io/pantropical_allometry.htm): 4.004 árboles, DAP 5–212 cm y altura 1,2–70,7 m. No son intervalos de calibración de cada especie ni una validación para arbolado urbano. Se exige hábito Árbol y un fuste. La precarga excluye conservadoramente palmas, cícadas, bambúes, coníferas y Carica papaya hasta disponer de métodos específicos o evaluación profesional adecuada.

Cada referencia explicita la ausencia de revisión profesional local. No tiene autor humano de aprobación. La interfaz, fichas, mapa y exportaciones indican su procedencia; el agregado contabiliza por separado los estimados que usan bibliografía provisional.

La huella del paquete JSON se verifica en `CarbonReferenceSeedService`. Cualquier cambio deliberado exige revisar datos, correspondencias y actualización de esa huella. Repetir el seeder no reemplaza perfiles existentes, ni vuelve a imponer valores tras una edición institucional.


## Identificadores de referencia neutrales (20/11/2026)

La revisión nominal del paquete cambia únicamente `species_code` a claves `SOURCE_SPECIES_…` y agrega `legacy_species_code_hash` en las 133 entradas afectadas. Las 143 referencias científicas y todos sus parámetros permanecen iguales. La versión `GWDD_2_2_SYSFOREST_20261025` se conserva como `source_package` para no reiniciar precargas retiradas ni duplicar recibos. SHA-256 del JSON revisado: `e8ea3e934d3271857d459e3fba72f4916e0bc9b06f0372404d9ce0e7f3a46d95`.

El catálogo complementario se denomina `reference-catalogs-v2.json`; su contrato está en [README de catálogos](reference-catalogs-README.md). La resolución exige que la clave neutral y cualquier huella histórica coincidan en una sola identidad; las discrepancias se rechazan. No se recalculan resultados ni se sobrescriben perfiles existentes por cambiar los nombres de procedencia.

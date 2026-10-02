# Carga de Plan de Ornato de Restrepo 2025

**Recuperación local del 02/10/2026:** el Excel original se recuperó de Descargas y se integró la carga en `--seed` para el entorno local. La instancia recuperada tiene sus propios UUID y artefactos normalizados; los identificadores y respaldos del informe histórico siguiente no son los de esta base. Consulta la [integración y verificación actual](restrepo-ornato-seed.md#verificación-de-la-recuperación-del-02102026).

**Actualización posterior:** la clasificación inicial fue corregida por solicitud del usuario: 65 Muertos, 1.897 En observación y 892 Activos. Las inspecciones y filas importadas permanecen intactas. Consulta [criterio, motivos y conciliación](restrepo-status-review.md). Las cantidades de revisión de este informe describen la situación original de carga.

La carga quedó completada en el piloto local el 28 de septiembre de 2026: **2.854 árboles y 2.854 inspecciones**, organizados en 29 lotes confirmados. Se reutilizaron los Services, Repositories, validaciones, permisos, transacciones por lote y auditoría de SYSFOREST. La organización, campaña, árboles e inspecciones permanecen privados.

## Dónde consultar

Desde [Organizaciones](http://localhost:8080/organizations), selecciona **Plan de Ornato de Restrepo** y abre Inventarios, Árboles o Importaciones. El superadministrador mantiene su acceso nacional. La organización usa el código `RESTREPO-META` y la campaña `ORNATO-2025`. No se concedieron permisos institucionales adicionales a otros usuarios.

El territorio corresponde a Restrepo, Meta, código DANE 50606: [ficha municipal del DANE](https://sitios.dane.gov.co/cnpv/app/views/informacion/fichas/50606.pdf). Las coordenadas del censo son coherentes con ese destino.

## Fuente y decisiones autorizadas

Se utilizó `Planilla Plan Ornato Restrepo 2025 Total para riesgos.xlsx`, hoja `CENSO FORESTAL URBANO`, filas 7–2860. Su SHA-256 es `54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63`. El original no fue modificado. Las 40 filas posteriores solo tenían numeración; `ZONA A` repite un subconjunto del censo y no se importó otra vez.

La fecha **30/11/2025** es una referencia de entrega elegida con autorización expresa del usuario. No se presenta como un día de visita conocido. Todas las observaciones conservan esta distinción y el periodo de recolección informado, septiembre–noviembre de 2025. El estado inicial **Activo** se asignó a todos por instrucción del usuario. En 65 registros la fuente dice «Muerto en pie»; el texto original y su contradicción quedan señalados para revisión.

Los números de árbol de la planilla se conservaron en `external_number`. SYSFOREST asignó sus códigos institucionales sin reemplazar ni fusionar individuos existentes.

## Resultado conciliado

| Comprobación | Resultado |
| --- | ---: |
| Individuos y participaciones en campaña | 2.854 |
| Inspecciones con fecha de referencia 30/11/2025 | 2.854 |
| Lotes completados | 29 |
| Filas persistidas cotejadas con los 110 campos preparados | 2.854, sin diferencias |
| Individuos con posición PostGIS | 2.851 |
| Individuos sin posición por ausencia o datos incompletos | 3 |
| Individuos sin especie identificada en la fuente | 51 |
| Individuos con revisión material pendiente | 123 |
| Publicaciones realizadas | 0 |

Las huellas de todos los registros previos de INEM en árboles, inspecciones, participaciones, evidencias y mantenimientos coinciden antes y después. Los cinco usuarios y sus hashes de contraseña se conservaron. El respaldo previo de la base está en `/home/josev/.local/state/sysforest/backups/before-restrepo-20260929T022326Z.dump`, con permisos privados y archivo comprobado como legible; no se ejecutó una restauración.

## Tratamiento de inconsistencias

Se normalizaron 1.328 comas decimales sin cambiar magnitudes, 131 residuos numéricos OOXML menores o iguales a 1e-12 y 11 escapes o signos de puntuación inequívocos en coordenadas. Los originales y las decisiones siguen documentados.

Doce medidas de nueve individuos no cumplían el formato o las reglas del dominio. Sus campos numéricos quedaron vacíos y sus valores originales, celdas y motivos permanecen en observaciones. No se corrigieron por intuición factores como `4,5` a `0,45`. De las marcas de condiciones no estándar, 34 contenían una X inequívoca con aclaración; las otras 50 se preservaron como texto pendiente de revisión, sin asumir una selección.

Riesgo, interferencias, marcación, barrio, dirección, zona, fauna y descripciones de fenología se conservaron en los campos de texto correspondientes. El riesgo original no se convirtió en prioridad de mantenimiento ni en otra clasificación. Los nombres científicos y familias se transcribieron del censo y su hoja de consenso; no se realizó una revisión nomenclatural externa. Los resultados dasométricos se calcularon en el servidor; las fórmulas del Excel no se ejecutaron.

El detalle de las transformaciones permanece en `.ornato-work/mapping-decisions.md` y sus JSON de diagnóstico en el área de trabajo de Windows. La copia normalizada está en almacenamiento privado de SYSFOREST; cada observación contiene libro, hoja, fila y huella de origen.

## Seeders y retirada de pruebas

`DevelopmentIdentitySeeder` define las identidades locales y solicita contraseña oculta solo al crear cuentas ausentes. La base almacena hashes; se retiraron las ocho variables de usuarios del `.env`, del ejemplo y de los procesos PHP. Reejecutarlo conserva las claves existentes. Consulta [cuentas locales](development-accounts.md).

`RestrepoOrnatoSeeder` prepara el territorio, la organización, la campaña y los catálogos fuente. Creó 37 familias, 133 especies, 135 nombres comunes y 10 emplazamientos. Su segunda ejecución no produjo altas, modificaciones ni eventos adicionales. Consulta [preparación de Restrepo](restrepo-ornato-seed.md).

Por decisión expresa del usuario se retiraron 124 archivos de pruebas automatizadas, su configuración y sus comandos, con un respaldo recuperable fuera del proyecto. `bin/check` conserva PSR-12 y análisis de sintaxis; no ejecuta suites. Consulta [verificaciones vigentes](testing.md).

## Verificación de esta entrega

La validación previa de los 2.854 registros terminó sin errores. La confirmación y la conciliación cotejaron todos los campos preparados, las referencias a árboles e inspecciones y los registros preexistentes. La repetición exacta del comando de confirmación reconoció los 29 lotes completados, sin volver a crear árboles ni inspecciones.

`bash bin/check all` terminó con código 0: PSR-12 en 478 archivos, sintaxis de 479 archivos PHP y de 24 archivos JavaScript. Los registros están en `storage/app/checks/20260929T023648Z-GDXfgR`. El ajuste posterior que rechaza códigos automáticos y explícitos mezclados entre varios lotes se verificó adicionalmente con PSR-12 y sintaxis sobre el archivo modificado. No se ejecutaron suites automatizadas ni se verificó esta carga desde una sesión de navegador autenticada.

## Registros para revisión

Las categorías se superponen: la tabla contiene 123 individuos distintos. Los valores originales y el motivo detallado están en la inspección de cada árbol. Abre los enlaces después de seleccionar la organización de Restrepo.

| N.º original | Código SYSFOREST | Fila Excel | Revisión pendiente |
| --- | --- | ---: | --- |
| 88 | [COL-MET-RES-ORNATO-000088](http://localhost:8080/trees/01a0eb09-15f7-71ef-9c08-237ba8d2e580) | 94 | Estado Activo y fuente «Muerto en pie» (G94) |
| 128 | [COL-MET-RES-ORNATO-000128](http://localhost:8080/trees/01a0eb09-4787-72f6-9a15-bd04404b96df) | 134 | Estado Activo y fuente «Muerto en pie» (G134) |
| 133 | [COL-MET-RES-ORNATO-000133](http://localhost:8080/trees/01a0eb09-49b7-7218-a6ad-7b5fc8a9393d) | 139 | Estado Activo y fuente «Muerto en pie» (G139) |
| 137 | [COL-MET-RES-ORNATO-000137](http://localhost:8080/trees/01a0eb09-4b65-730c-849e-29ca248257a1) | 143 | Estado Activo y fuente «Muerto en pie» (G143) |
| 221 | [COL-MET-RES-ORNATO-000221](http://localhost:8080/trees/01a0eb09-8b4c-728a-98aa-33a69ef69c7e) | 227 | Marca de condición ambigua (AC227) |
| 222 | [COL-MET-RES-ORNATO-000222](http://localhost:8080/trees/01a0eb09-8bb6-7153-9a0b-1f586adf5ef8) | 228 | Marca de condición ambigua (AC228) |
| 225 | [COL-MET-RES-ORNATO-000225](http://localhost:8080/trees/01a0eb09-8ced-715a-896e-195dc6d1bffa) | 231 | Marca de condición ambigua (AC231) |
| 244 | [COL-MET-RES-ORNATO-000244](http://localhost:8080/trees/01a0eb09-94f5-7381-b4c4-fb99b852aadd) | 250 | Estado Activo y fuente «Muerto en pie» (G250) |
| 245 | [COL-MET-RES-ORNATO-000245](http://localhost:8080/trees/01a0eb09-9561-730e-8011-36fdbc642cbc) | 251 | Estado Activo y fuente «Muerto en pie» (G251) |
| 251 | [COL-MET-RES-ORNATO-000251](http://localhost:8080/trees/01a0eb09-97de-7061-b0c0-46c7c27ec1a2) | 257 | Estado Activo y fuente «Muerto en pie» (G257) |
| 256 | [COL-MET-RES-ORNATO-000256](http://localhost:8080/trees/01a0eb09-99ea-7014-941e-59aab8c10287) | 262 | Marca de condición ambigua (CO262) |
| 257 | [COL-MET-RES-ORNATO-000257](http://localhost:8080/trees/01a0eb09-9a54-7078-87ae-bc4787cee6b9) | 263 | Marca de condición ambigua (BN263, CN263) |
| 262 | [COL-MET-RES-ORNATO-000262](http://localhost:8080/trees/01a0eb09-9c75-70ec-8255-2b17d9e359ac) | 268 | Estado Activo y fuente «Muerto en pie» (G268) |
| 275 | [COL-MET-RES-ORNATO-000275](http://localhost:8080/trees/01a0eb09-a1f3-71b9-8af7-90c5430ae5cf) | 281 | Marca de condición ambigua (BN281) |
| 283 | [COL-MET-RES-ORNATO-000283](http://localhost:8080/trees/01a0eb09-a5dd-73fa-9824-e9136d6ec266) | 289 | Estado Activo y fuente «Muerto en pie» (G289) |
| 294 | [COL-MET-RES-ORNATO-000294](http://localhost:8080/trees/01a0eb09-ab37-70e6-a7a0-4ebfa9ecd993) | 300 | Estado Activo y fuente «Muerto en pie» (G300) |
| 299 | [COL-MET-RES-ORNATO-000299](http://localhost:8080/trees/01a0eb09-ad4f-705c-a79d-2af4aec20461) | 305 | Estado Activo y fuente «Muerto en pie» (G305) |
| 302 | [COL-MET-RES-ORNATO-000302](http://localhost:8080/trees/01a0eb09-ce7f-730d-a5c8-d5fd428341cd) | 308 | Marca de condición ambigua (AC308) |
| 314 | [COL-MET-RES-ORNATO-000314](http://localhost:8080/trees/01a0eb09-d4b4-73e0-b5c9-62b896653590) | 320 | Estado Activo y fuente «Muerto en pie» (G320) |
| 316 | [COL-MET-RES-ORNATO-000316](http://localhost:8080/trees/01a0eb09-d584-70a8-9331-dfd876d1c5d6) | 322 | Estado Activo y fuente «Muerto en pie» (G322) |
| 326 | [COL-MET-RES-ORNATO-000326](http://localhost:8080/trees/01a0eb09-da02-730b-af4c-a2706170b697) | 332 | Estado Activo y fuente «Muerto en pie» (G332) |
| 359 | [COL-MET-RES-ORNATO-000359](http://localhost:8080/trees/01a0eb09-e874-7037-8d30-6c8b45f70a89) | 365 | Estado Activo y fuente «Muerto en pie» (G365) |
| 532 | [COL-MET-RES-ORNATO-000532](http://localhost:8080/trees/01a0eb0a-8146-71cb-97c7-033d38626110) | 538 | Estado Activo y fuente «Muerto en pie» (G538) |
| 533 | [COL-MET-RES-ORNATO-000533](http://localhost:8080/trees/01a0eb0a-81ac-7386-9c1d-e75176d2d850) | 539 | Marca de condición ambigua (BN539) |
| 537 | [COL-MET-RES-ORNATO-000537](http://localhost:8080/trees/01a0eb0a-8377-7056-b059-ebe1dc4881a3) | 543 | Marca de condición ambigua (BN543) |
| 543 | [COL-MET-RES-ORNATO-000543](http://localhost:8080/trees/01a0eb0a-8644-7155-aa14-3c8011b67846) | 549 | Estado Activo y fuente «Muerto en pie» (G549) |
| 560 | [COL-MET-RES-ORNATO-000560](http://localhost:8080/trees/01a0eb0a-8e00-7221-b4cf-fd869223ceb2) | 566 | Marca de condición ambigua (BN566) |
| 620 | [COL-MET-RES-ORNATO-000620](http://localhost:8080/trees/01a0eb0a-cd74-7213-a63c-62eb73189087) | 626 | Estado Activo y fuente «Muerto en pie» (G626) |
| 637 | [COL-MET-RES-ORNATO-000637](http://localhost:8080/trees/01a0eb0a-d4f2-7163-a311-f0e488621367) | 643 | Estado Activo y fuente «Muerto en pie» (G643) |
| 639 | [COL-MET-RES-ORNATO-000639](http://localhost:8080/trees/01a0eb0a-d5da-71a0-9f55-6bdead06d462) | 645 | Estado Activo y fuente «Muerto en pie» (G645) |
| 641 | [COL-MET-RES-ORNATO-000641](http://localhost:8080/trees/01a0eb0a-d6c6-7269-92f3-eebb7e270eb2) | 647 | Marca de condición ambigua (BI647) |
| 642 | [COL-MET-RES-ORNATO-000642](http://localhost:8080/trees/01a0eb0a-d739-724a-9cb9-bb4f45506739) | 648 | Marca de condición ambigua (BI648) |
| 645 | [COL-MET-RES-ORNATO-000645](http://localhost:8080/trees/01a0eb0a-d89c-7238-b630-5c69f41a24db) | 651 | Estado Activo y fuente «Muerto en pie» (G651) |
| 648 | [COL-MET-RES-ORNATO-000648](http://localhost:8080/trees/01a0eb0a-d9f1-7163-91a2-a19e789bb453) | 654 | Estado Activo y fuente «Muerto en pie» (G654) |
| 664 | [COL-MET-RES-ORNATO-000664](http://localhost:8080/trees/01a0eb0a-e291-7204-a2c9-5b0bb7d14413) | 670 | Marca de condición ambigua (BI670) |
| 671 | [COL-MET-RES-ORNATO-000671](http://localhost:8080/trees/01a0eb0a-e5a1-703d-bf6a-770c4a1b2350) | 677 | Estado Activo y fuente «Muerto en pie» (G677) |
| 698 | [COL-MET-RES-ORNATO-000698](http://localhost:8080/trees/01a0eb0a-f115-718b-918a-e02220a2fcf0) | 704 | Marca de condición ambigua (BN704) |
| 785 | [COL-MET-RES-ORNATO-000785](http://localhost:8080/trees/01a0eb0b-38bb-72aa-b35f-111faf235296) | 791 | Estado Activo y fuente «Muerto en pie» (G791) |
| 798 | [COL-MET-RES-ORNATO-000798](http://localhost:8080/trees/01a0eb0b-3f29-7393-a6aa-c0caefa4e61b) | 804 | Estado Activo y fuente «Muerto en pie» (G804) |
| 800 | [COL-MET-RES-ORNATO-000800](http://localhost:8080/trees/01a0eb0b-4012-71fa-8663-744c843a02ea) | 806 | Estado Activo y fuente «Muerto en pie» (G806) |
| 815 | [COL-MET-RES-ORNATO-000815](http://localhost:8080/trees/01a0eb0b-6522-722e-bacd-7ec943b972b7) | 821 | Estado Activo y fuente «Muerto en pie» (G821) |
| 817 | [COL-MET-RES-ORNATO-000817](http://localhost:8080/trees/01a0eb0b-65f8-7023-b7b3-b2446cd1e149) | 823 | Marca de condición ambigua (BN823) |
| 818 | [COL-MET-RES-ORNATO-000818](http://localhost:8080/trees/01a0eb0b-6671-703d-92b5-82a34bec399d) | 824 | Marca de condición ambigua (BN824) |
| 843 | [COL-MET-RES-ORNATO-000843](http://localhost:8080/trees/01a0eb0b-71bb-71e2-beca-2d2e76e51c3c) | 849 | Estado Activo y fuente «Muerto en pie» (G849) |
| 852 | [COL-MET-RES-ORNATO-000852](http://localhost:8080/trees/01a0eb0b-7592-7235-b00c-b71ef18b3b08) | 858 | Medición no admitida como valor numérico (Q858) |
| 881 | [COL-MET-RES-ORNATO-000881](http://localhost:8080/trees/01a0eb0b-841e-7141-9836-3a3e59944325) | 887 | Estado Activo y fuente «Muerto en pie» (G887) |
| 907 | [COL-MET-RES-ORNATO-000907](http://localhost:8080/trees/01a0eb0b-b205-733b-89ef-aec3320f1dad) | 913 | Estado Activo y fuente «Muerto en pie» (G913) |
| 935 | [COL-MET-RES-ORNATO-000935](http://localhost:8080/trees/01a0eb0b-befd-7299-bada-6cf765ba7cd6) | 941 | Mediciones inconsistentes; Marca de condición ambigua (S941, CN941) |
| 937 | [COL-MET-RES-ORNATO-000937](http://localhost:8080/trees/01a0eb0b-bfdb-7126-af11-665469f9ab62) | 943 | Marca de condición ambigua (CN943) |
| 938 | [COL-MET-RES-ORNATO-000938](http://localhost:8080/trees/01a0eb0b-c04c-7151-9012-0d111085abca) | 944 | Marca de condición ambigua (CN944) |
| 939 | [COL-MET-RES-ORNATO-000939](http://localhost:8080/trees/01a0eb0b-c0bb-73bc-995c-3f01a72d0250) | 945 | Marca de condición ambigua (CN945) |
| 973 | [COL-MET-RES-ORNATO-000973](http://localhost:8080/trees/01a0eb0b-d110-7379-8eb9-cffab8ae8de1) | 979 | Estado Activo y fuente «Muerto en pie» (G979) |
| 988 | [COL-MET-RES-ORNATO-000988](http://localhost:8080/trees/01a0eb0b-d982-72db-a8cf-edf449684f1b) | 994 | Estado Activo y fuente «Muerto en pie» (G994) |
| 1000 | [COL-MET-RES-ORNATO-001000](http://localhost:8080/trees/01a0eb0b-e038-72b8-831d-78b25df84484) | 1006 | Estado Activo y fuente «Muerto en pie» (G1006) |
| 1001 | [COL-MET-RES-ORNATO-001001](http://localhost:8080/trees/01a0eb0b-fd63-7188-a43b-61b676484262) | 1007 | Estado Activo y fuente «Muerto en pie» (G1007) |
| 1002 | [COL-MET-RES-ORNATO-001002](http://localhost:8080/trees/01a0eb0b-fdba-731c-b05b-f438bf0b6093) | 1008 | Estado Activo y fuente «Muerto en pie» (G1008) |
| 1003 | [COL-MET-RES-ORNATO-001003](http://localhost:8080/trees/01a0eb0b-fe16-73da-9bc2-758f1d7d3c12) | 1009 | Estado Activo y fuente «Muerto en pie» (G1009) |
| 1004 | [COL-MET-RES-ORNATO-001004](http://localhost:8080/trees/01a0eb0b-fe73-70eb-8bea-40f515392ac3) | 1010 | Estado Activo y fuente «Muerto en pie» (G1010) |
| 1006 | [COL-MET-RES-ORNATO-001006](http://localhost:8080/trees/01a0eb0b-ff46-7058-96fd-3b5a11ae7fe1) | 1012 | Estado Activo y fuente «Muerto en pie» (G1012) |
| 1007 | [COL-MET-RES-ORNATO-001007](http://localhost:8080/trees/01a0eb0b-ffa3-72bf-8adc-a93f239077ed) | 1013 | Estado Activo y fuente «Muerto en pie» (G1013) |
| 1012 | [COL-MET-RES-ORNATO-001012](http://localhost:8080/trees/01a0eb0c-01c7-7113-98e8-1db23f98cf8e) | 1018 | Estado Activo y fuente «Muerto en pie» (G1018) |
| 1014 | [COL-MET-RES-ORNATO-001014](http://localhost:8080/trees/01a0eb0c-0292-72b6-adef-5b2d5fb8d23c) | 1020 | Marca de condición ambigua (CN1020) |
| 1038 | [COL-MET-RES-ORNATO-001038](http://localhost:8080/trees/01a0eb0c-0d94-730d-9a8f-64ed54e1ba15) | 1044 | Marca de condición ambigua (CN1044) |
| 1049 | [COL-MET-RES-ORNATO-001049](http://localhost:8080/trees/01a0eb0c-127c-723c-bdb8-927c826785cc) | 1055 | Marca de condición ambigua (CN1055) |
| 1090 | [COL-MET-RES-ORNATO-001090](http://localhost:8080/trees/01a0eb0c-2af3-707b-ae97-828784af64b0) | 1096 | Estado Activo y fuente «Muerto en pie» (G1096) |
| 1138 | [COL-MET-RES-ORNATO-001138](http://localhost:8080/trees/01a0eb0c-5da0-7267-8d56-6e019a59fded) | 1144 | Marca de condición ambigua (CO1144) |
| 1139 | [COL-MET-RES-ORNATO-001139](http://localhost:8080/trees/01a0eb0c-5e13-734b-ace4-76e89653b174) | 1145 | Marca de condición ambigua (CO1145) |
| 1140 | [COL-MET-RES-ORNATO-001140](http://localhost:8080/trees/01a0eb0c-5e82-706c-9024-e006eabf4b47) | 1146 | Estado Activo y fuente «Muerto en pie» (G1146) |
| 1146 | [COL-MET-RES-ORNATO-001146](http://localhost:8080/trees/01a0eb0c-6102-71d8-86c5-806769d0868c) | 1152 | Estado Activo y fuente «Muerto en pie» (G1152) |
| 1160 | [COL-MET-RES-ORNATO-001160](http://localhost:8080/trees/01a0eb0c-6816-70c5-bd52-dfae46f3654c) | 1166 | Marca de condición ambigua (CN1166) |
| 1206 | [COL-MET-RES-ORNATO-001206](http://localhost:8080/trees/01a0eb0c-9f95-72af-9f11-5c0a3a0d6490) | 1212 | Estado Activo y fuente «Muerto en pie» (G1212) |
| 1345 | [COL-MET-RES-ORNATO-001345](http://localhost:8080/trees/01a0eb0d-2748-731b-a249-788b0f2b98be) | 1351 | Marca de condición ambigua (CO1351) |
| 1362 | [COL-MET-RES-ORNATO-001362](http://localhost:8080/trees/01a0eb0d-38f5-73ed-bb54-591aae31f1c7) | 1368 | Estado Activo y fuente «Muerto en pie» (G1368) |
| 1386 | [COL-MET-RES-ORNATO-001386](http://localhost:8080/trees/01a0eb0d-4b38-73cc-81e2-1cf7972b85a6) | 1392 | Marca de condición ambigua (CO1392) |
| 1422 | [COL-MET-RES-ORNATO-001422](http://localhost:8080/trees/01a0eb0d-9197-72b3-a5b2-5a0c12342b5a) | 1428 | Marca de condición ambigua (BT1428) |
| 1488 | [COL-MET-RES-ORNATO-001488](http://localhost:8080/trees/01a0eb0d-bf4b-7306-808c-2f17f6bc6792) | 1494 | Estado Activo y fuente «Muerto en pie» (G1494) |
| 1523 | [COL-MET-RES-ORNATO-001523](http://localhost:8080/trees/01a0eb0e-05e9-70fc-8040-199b4746f410) | 1529 | Marca de condición ambigua (AE1529) |
| 1530 | [COL-MET-RES-ORNATO-001530](http://localhost:8080/trees/01a0eb0e-0aab-7395-b423-247e48f05a23) | 1536 | Medición no admitida como valor numérico (Q1536) |
| 1575 | [COL-MET-RES-ORNATO-001575](http://localhost:8080/trees/01a0eb0e-28f8-70e2-a7dd-130f4191e1ea) | 1581 | Estado Activo y fuente «Muerto en pie» (G1581) |
| 1576 | [COL-MET-RES-ORNATO-001576](http://localhost:8080/trees/01a0eb0e-2964-71d1-99a1-d9de10838c73) | 1582 | Estado Activo y fuente «Muerto en pie» (G1582) |
| 1579 | [COL-MET-RES-ORNATO-001579](http://localhost:8080/trees/01a0eb0e-2b16-7361-9f92-8ccc4d3d10a6) | 1585 | Estado Activo y fuente «Muerto en pie» (G1585) |
| 1692 | [COL-MET-RES-ORNATO-001692](http://localhost:8080/trees/01a0eb0e-af84-7044-a192-185df4302c99) | 1698 | Mediciones inconsistentes (S1698) |
| 1833 | [COL-MET-RES-ORNATO-001833](http://localhost:8080/trees/01a0eb0f-5cde-73b2-a5f7-69f9c7cb3f78) | 1839 | Marca de condición ambigua (AU1839) |
| 1871 | [COL-MET-RES-ORNATO-001871](http://localhost:8080/trees/01a0eb0f-739b-7131-bed5-962d0d6526c9) | 1877 | Marca de condición ambigua (AX1877) |
| 1944 | [COL-MET-RES-ORNATO-001944](http://localhost:8080/trees/01a0eb0f-ba45-71c6-9fb2-2821d71fd100) | 1950 | Medición no admitida como valor numérico (Q1950) |
| 1956 | [COL-MET-RES-ORNATO-001956](http://localhost:8080/trees/01a0eb0f-bfd7-7035-9de3-97b76911920a) | 1962 | Estado Activo y fuente «Muerto en pie» (G1962) |
| 1964 | [COL-MET-RES-ORNATO-001964](http://localhost:8080/trees/01a0eb0f-c4c2-702c-a3f0-6fce848451de) | 1970 | Estado Activo y fuente «Muerto en pie»; Medición no admitida como valor numérico (G1970, L1970, P1970, Q1970, R1970) |
| 2073 | [COL-MET-RES-ORNATO-002073](http://localhost:8080/trees/01a0eb10-1eaa-73a8-a7f7-a05e8203bc9f) | 2079 | Marca de condición ambigua (AF2079) |
| 2076 | [COL-MET-RES-ORNATO-002076](http://localhost:8080/trees/01a0eb10-2026-70e9-b3c0-76ecb50a38fe) | 2082 | Estado Activo y fuente «Muerto en pie» (G2082) |
| 2084 | [COL-MET-RES-ORNATO-002084](http://localhost:8080/trees/01a0eb10-2424-707f-b6c1-f3cedd737f5d) | 2090 | Estado Activo y fuente «Muerto en pie» (G2090) |
| 2088 | [COL-MET-RES-ORNATO-002088](http://localhost:8080/trees/01a0eb10-25fa-709e-82c6-43ddf41bff14) | 2094 | Estado Activo y fuente «Muerto en pie» (G2094) |
| 2132 | [COL-MET-RES-ORNATO-002132](http://localhost:8080/trees/01a0eb10-6462-7082-b396-34e8004bdc40) | 2138 | Marca de condición ambigua (AF2138) |
| 2140 | [COL-MET-RES-ORNATO-002140](http://localhost:8080/trees/01a0eb10-6998-7134-bef6-993e01d3ecd9) | 2146 | Marca de condición ambigua (AP2146, CJ2146) |
| 2170 | [COL-MET-RES-ORNATO-002170](http://localhost:8080/trees/01a0eb10-7aaa-72b9-9140-00c985f43093) | 2176 | Estado Activo y fuente «Muerto en pie» (G2176) |
| 2196 | [COL-MET-RES-ORNATO-002196](http://localhost:8080/trees/01a0eb10-892d-7380-913d-7a058d7c607e) | 2202 | Estado Activo y fuente «Muerto en pie» (G2202) |
| 2197 | [COL-MET-RES-ORNATO-002197](http://localhost:8080/trees/01a0eb10-8a25-72c6-a422-eee8aec3d1a5) | 2203 | Medición no admitida como valor numérico (W2203) |
| 2212 | [COL-MET-RES-ORNATO-002212](http://localhost:8080/trees/01a0eb10-b5a7-7011-8174-0b051b33f684) | 2218 | Marca de condición ambigua (CD2218) |
| 2308 | [COL-MET-RES-ORNATO-002308](http://localhost:8080/trees/01a0eb11-0d99-73e4-a3ab-deae9477f0f0) | 2314 | Marca de condición ambigua (AH2314) |
| 2316 | [COL-MET-RES-ORNATO-002316](http://localhost:8080/trees/01a0eb11-11b2-7221-8155-f20316f852db) | 2322 | Marca de condición ambigua (BU2322) |
| 2325 | [COL-MET-RES-ORNATO-002325](http://localhost:8080/trees/01a0eb11-173a-7309-b7c3-b4734f8bdb40) | 2331 | Estado Activo y fuente «Muerto en pie» (G2331) |
| 2360 | [COL-MET-RES-ORNATO-002360](http://localhost:8080/trees/01a0eb11-2af4-7011-8d39-77c051179fff) | 2366 | Marca de condición ambigua (AH2366) |
| 2361 | [COL-MET-RES-ORNATO-002361](http://localhost:8080/trees/01a0eb11-2b96-7359-8068-7fd4bcb86822) | 2367 | Marca de condición ambigua (CB2367) |
| 2393 | [COL-MET-RES-ORNATO-002393](http://localhost:8080/trees/01a0eb11-3b42-72c5-af33-c3138fa6e83e) | 2399 | Estado Activo y fuente «Muerto en pie» (G2399) |
| 2396 | [COL-MET-RES-ORNATO-002396](http://localhost:8080/trees/01a0eb11-3c93-7305-a136-831a78b83bb7) | 2402 | Estado Activo y fuente «Muerto en pie» (G2402) |
| 2418 | [COL-MET-RES-ORNATO-002418](http://localhost:8080/trees/01a0eb11-7052-712a-b91b-d14029a53f14) | 2424 | Marca de condición ambigua (AH2424) |
| 2484 | [COL-MET-RES-ORNATO-002484](http://localhost:8080/trees/01a0eb11-923f-721c-850d-dfad6f242ead) | 2490 | Estado Activo y fuente «Muerto en pie» (G2490) |
| 2505 | [COL-MET-RES-ORNATO-002505](http://localhost:8080/trees/01a0eb11-bb21-727f-b334-7b2c89815906) | 2511 | Marca de condición ambigua (CD2511) |
| 2544 | [COL-MET-RES-ORNATO-002544](http://localhost:8080/trees/01a0eb11-d838-73bc-badb-fd493bf3e056) | 2550 | Coordenadas incompletas (F2550) |
| 2604 | [COL-MET-RES-ORNATO-002604](http://localhost:8080/trees/01a0eb12-2c98-7031-8838-b39f138091ff) | 2610 | Marca de condición ambigua (BI2610) |
| 2617 | [COL-MET-RES-ORNATO-002617](http://localhost:8080/trees/01a0eb12-33d6-7063-a6d0-c3c96ef06aed) | 2623 | Marca de condición ambigua (CJ2623) |
| 2639 | [COL-MET-RES-ORNATO-002639](http://localhost:8080/trees/01a0eb12-4698-7015-a878-1b592bb4e3f8) | 2645 | Estado Activo y fuente «Muerto en pie» (G2645) |
| 2640 | [COL-MET-RES-ORNATO-002640](http://localhost:8080/trees/01a0eb12-471c-7373-9b89-86411fce88be) | 2646 | Estado Activo y fuente «Muerto en pie» (G2646) |
| 2670 | [COL-MET-RES-ORNATO-002670](http://localhost:8080/trees/01a0eb12-5b58-73f7-85b9-4ccebbad6f82) | 2676 | Estado Activo y fuente «Muerto en pie» (G2676) |
| 2720 | [COL-MET-RES-ORNATO-002720](http://localhost:8080/trees/01a0eb12-9fe6-70bc-b366-4be3866c59e8) | 2726 | Estado Activo y fuente «Muerto en pie» (G2726) |
| 2734 | [COL-MET-RES-ORNATO-002734](http://localhost:8080/trees/01a0eb12-a660-73de-8bf2-32723a9bec41) | 2740 | Coordenadas incompletas (F2740) |
| 2759 | [COL-MET-RES-ORNATO-002759](http://localhost:8080/trees/01a0eb12-b45f-70af-b97b-c18c51c63202) | 2765 | Coordenadas ausentes (F2765) |
| 2781 | [COL-MET-RES-ORNATO-002781](http://localhost:8080/trees/01a0eb12-c3a1-7122-bd06-26a9fcd44c49) | 2787 | Marca de condición ambigua (AI2787) |
| 2782 | [COL-MET-RES-ORNATO-002782](http://localhost:8080/trees/01a0eb12-c424-72df-b499-468dd23a83ae) | 2788 | Marca de condición ambigua (AI2788) |
| 2783 | [COL-MET-RES-ORNATO-002783](http://localhost:8080/trees/01a0eb12-c499-7284-aeba-fec4c9d7f597) | 2789 | Marca de condición ambigua (AI2789) |
| 2789 | [COL-MET-RES-ORNATO-002789](http://localhost:8080/trees/01a0eb12-c7a9-723c-b657-28e18d607a3a) | 2795 | Mediciones inconsistentes (S2795) |
| 2830 | [COL-MET-RES-ORNATO-002830](http://localhost:8080/trees/01a0eb12-eebf-71ae-94c5-c3a75185da6f) | 2836 | Mediciones inconsistentes (S2836) |
| 2839 | [COL-MET-RES-ORNATO-002839](http://localhost:8080/trees/01a0eb12-f2d2-7110-8771-8c83905f3bed) | 2845 | Estado Activo y fuente «Muerto en pie» (G2845) |
| 2848 | [COL-MET-RES-ORNATO-002848](http://localhost:8080/trees/01a0eb12-f6f9-7329-825e-0761c83bc9bb) | 2854 | Marca de condición ambigua (AF2854) |

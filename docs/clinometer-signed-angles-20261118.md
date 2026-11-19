# Inclinómetro con ángulos firmados — 18/11/2026

## Estado

Contrato confirmado por el usuario. Revisión independiente, integración backend, revisión visual web y compilación de la imagen production V2 aprobadas, con los límites descritos abajo. La revisión visual y compilación final de Android siguen su evidencia separada. **V2 aún no se ha activado en producción ni se ha publicado la APK corregida**.

El corte anterior V1 se recuperó de forma segura al cambiar la fórmula solicitada: imagen/fuentes anteriores saludables, `000036` retenida y APK V1 sin publicación. Se conserva su evidencia, incluida la distinción entre datos de negocio intactos y cambios de sesiones durante la reapertura, en [el informe V1](clinometer-follow-up-20261118.md).

## Fórmulas y campos

Las cuatro lecturas son la distancia horizontal `Dh` y tres ángulos en grados: superior hacia el ápice, superior hacia la base de la copa y lectura inferior hacia la base del árbol. Esta última conserva el signo de campo: negativa bajo el horizonte, positiva sobre él.

```text
AT = Dh × [tan(superior total) − tan(inferior)]
AF = Dh × [tan(superior fuste) − tan(inferior)]
```

Por confirmación explícita del usuario, `AT` se guarda en `total_height_m` y `AF` en el campo existente `commercial_height_m`, presentado como **Altura comercial (fuste)**. No se añade una tercera altura ni se usa altura de la persona. Ambas fórmulas comparten la misma distancia y lectura inferior; los dos ángulos superiores son independientes.

| Entrada V2 | Validación |
|---|---|
| `clinometer_distance_m` | Metros, mayor que cero. |
| `clinometer_elevation_degrees` | Superior total: 0 ≤ ángulo < 90. |
| `clinometer_stem_elevation_degrees` | Superior fuste: 0 ≤ ángulo < 90. |
| `clinometer_lower_degrees` | Inferior firmado: −90 < ángulo < 90. |

Entradas decimales finitas, hasta doce dígitos enteros y seis decimales; no notación científica. El servidor calcula y redondea las alturas a seis decimales, exige AT>0, AF≥0 y AF≤AT y rechaza resultados fuera de precisión. AF igual a cero es una medición válida, distinta de ausencia, y conserva el contrato manual existente. No aplica valor absoluto, clamp, intercambio de ángulos ni corrección silenciosa. Flutter solo presenta una vista previa; la regla definitiva permanece en el servidor. La implementación de la fórmula solicitada no acredita calibración ni validación de campo.

## Compatibilidad y persistencia

`uses_clinometer` conserva su contrato. `clinometer_method` admite `legacy` o `signed_angles` y es solo un selector de entrada: no tiene columna, no se audita como dato persistente y no se añade por defecto al request antes de calcular su hash. `clinometer_formula_version` está prohibido como entrada y lo fija el servidor (`INCLINOMETER_V1` o `INCLINOMETER_V2`).

La migración incremental `000037` añade dos columnas nullable `numeric(8,6)` —superior de fuste e inferior firmado— y amplía la restricción de coherencia de `tree_inspections`. Conserva V1 con depresión/persona y campos V2 nulos; V2 exige sus lecturas y guarda depresión/persona nulas. Los registros manuales o sin método mantienen todos los operandos y versión nulos. No ejecuta UPDATE histórico ni recalcula visitas. `000036` permanece byte idéntica: ya está aplicada y no se modifica para introducir el nuevo contrato. La reversión de `000037` rechaza retirar columnas si existen lecturas V2.

Un request V1 sin selector sigue usando V1; su UUID, cuerpo, hash y recibo no se enriquecen con defaults V2. En PATCH, el servicio obtiene el método del registro vigente después de comprobar el recibo móvil. Un cliente legado que omite el modo y reenvía alturas iguales conserva la medición; un cambio manual explícito de esas alturas limpia los operandos conforme al contrato existente. La selección V2 requiere sus nuevos ángulos. Proyecciones GET y nuevas revisiones admiten los campos V2, sin reescribir snapshots, documentos de auditoría ni resultados de recibos históricos. No se amplía la API pública ni los permisos.

## Formularios y móvil

Web inicia nuevas mediciones con V2. Al editar V1 ofrece conservar el método anterior o cambiar expresamente al nuevo: distancia y superior total pueden conservarse, pero superior del fuste e inferior empiezan vacíos y deben medirse. Los grupos no seleccionados se deshabilitan y quedan fuera del formulario/vista previa; sus controles conservan el texto mientras el usuario alterna. La lectura histórica muestra los operandos del método registrado.

Android requiere `supports_clinometer_v2` para una nueva captura V2; `supports_clinometer` continúa identificando compatibilidad V1. Un servidor anterior no recibe las claves V2 desde captura manual. Borradores y correcciones V1 recuperan el método anterior sin reinterpretar depresión/persona. Cambiar de método requiere confirmación explícita y nuevas lecturas de fuste/base, sin inferirlas de alturas anteriores. La versión recibida solo se conserva como contexto local del borrador; no sale en el payload. Se mantiene la cola, el envío exclusivamente manual, los cuerpos congelados y los recibos.

Seguimiento, filtros y permisos de la entrega anterior siguen dentro del alcance. Android consulta el historial autorizado descargado; no incorpora Reportes ni una API de auditoría completa. Ejecutar **Sincronizar ahora** será necesario para renovar capacidades después de publicar la plataforma compatible, sin afirmar todavía que esa publicación ocurrió.

## Revisión y límites

Revisión independiente de fuente: fórmulas/rangos/redondeo, 036 intacta/037 aditiva, selector posterior al recibo, versión exclusiva del servidor, PATCH legado, grupos web disabled/required, conservación de controles, borradores V1/V2 y ausencia de cambios en envío/cola. Evidencia y hashes en `.clinometer-v2-work/independent-review.json`. Esa lectura no sustituye la integración con base de datos ni una prueba visual o física.

No se añadieron suites permanentes. La evidencia de integración y web incorporada a continuación no acredita publicación productiva; el resultado móvil final se registra por separado. No afirmar validación de teléfono físico, SQLite nativo mediante una vista previa web, migración productiva V2 ni publicación de APK hasta contar con evidencia específica.

## Cierre backend y candidato production

- 32/32 observaciones de integración en PostgreSQL aislado: fórmulas con inferior negativo/positivo/cero, AF cero, límites, escritura V2, preservación de V1, cambio explícito de método, manual y PATCH legado. La migración `000037` conservó las huellas de siete tablas y los antecedentes manual/V1; sus dos campos nuevos quedaron nulos en ellos.
- 4/4 observaciones adicionales sobre un **recibo V1 simulado** sin claves V2: respuesta reproducida exactamente, hash/resultado conservados y ninguna visita/revisión nueva. Todo dentro de una transacción revertida. No se presenta como replay de una solicitud histórica real de producción.
- PSR-12 y sintaxis de nueve PHP aprobados; 036, DendrometryInput y MobileMutationService permanecieron byte idénticos. Revisión independiente de 12 puntos del backend/web/Flutter sin bloqueos.
- CUA web real en escritorio y 390 px: cálculos con inferior −10° y +10°, guardado/ficha en la fixture y conversión V1 que deja vacíos los ángulos nuevos. Sin escrituras de prueba productivas. Las capturas auxiliares están en `.clinometer-v2-work/web/`.
- Imagen targetproduction `sysforest-app:clinometer-v2-production-20261118`, `sha256:b56ed192726b239031b2d9fa6592a356a2f303ccd5c8479ade77ec367bd8f756`, 249.033.087 bytes. Las 951 fuentes runtime congeladas quedaron intactas durante el build y los ocho servicios locales conservaron ID y salud. La compilación no ejecutó migraciones ni activó servicios.
- Compatibilidad 8/8 en contenedores efímeros sin red: PHP 8.4.27, entrypoint/FPM, usuario, configuración y composer.lock coinciden con la evidencia productiva anterior; autoload de las clases nuevas aprobado. No es una migración ni una prueba funcional contra producción.

Artefactos: `.clinometer-v2-work/backend/{integration-observations,migration-observations,historical-receipt-observations,static-verification}.json` y `.clinometer-v2-work/release/{backend-production-source-freeze,backend-production-image-result,production-candidate-compatibility}.json`. El paquete futuro combina 34 archivos runtime V1+V2/Seguimiento y tres documentos; no basta aplicar únicamente el diff V2 porque el release activo fue recuperado. `000036` se incluye como fuente histórica aplicada; solo `000037` corresponde al próximo cambio de esquema. Corte y publicación permanecen pendientes de su evidencia final.

# Edición concurrente

Las mutaciones forestales conservan su control optimista de versión y sus bloqueos transaccionales. La Fase 14 incorpora el mismo contrato a organizaciones, nueve catálogos simples, familias, especies, nombres comunes, categorías y condiciones, y versiones de clasificación: dieciséis tablas adicionales.

## Contrato de formulario

El formulario de edición incluye `lock_version` leído al abrirlo. Se exige un entero positivo canónico, hasta 2147483646 para reservar el siguiente valor dentro de PostgreSQL integer. No se admiten versiones ausentes, booleanas, decimales, con notación exponencial o con ceros iniciales. La creación no admite una versión enviada por el cliente.

El Service obtiene el registro bajo bloqueo dentro de la transacción, valida la versión recibida y la incrementa al guardar. La versión del servidor nunca sustituye una versión ausente. Una segunda pantalla con la versión anterior recibe HTTP 409; no sobrescribe valores ni genera una auditoría exitosa. La respuesta solicita recargar y revisar el cambio vigente. Después de un error de validación se conserva la versión original del formulario, para evitar convertir silenciosamente una edición antigua en una escritura autorizada sobre datos nuevos.

`VersionInput` concentra la validación y `VersionedWriteService` aplica la comparación sobre el modelo bloqueado. El incremento se persiste por el Repository. Los valores de auditoría anteriores se capturan antes del incremento. La restricción de base exige `lock_version >= 1`.

## Referencias y transacciones

La selección de catálogo para capturas forestales materializa y bloquea de forma compartida la referencia dentro de la transacción. Para especies también mantiene disponible la familia. La captura de inspecciones bloquea la versión de clasificación y sus estados de madurez, y carga las relaciones después de adquirir el bloqueo. Una desactivación concurrente debe esperar a que termine la captura; las siguientes capturas observan la desactivación.

Una referencia histórica que no se cambia sigue siendo válida al corregir otros datos, incluso si se desactivó después. Esta regla no permite elegir una referencia inactiva nueva. Las consultas fuera de una transacción no retienen un bloqueo innecesario.

Los Services mantienen el orden de escritura, la revalidación de permisos y los reintentos limitados de transacciones existentes. La base conserva unicidad de códigos, claves foráneas institucionales y protección de autoría. No se trasladan reglas a Blade o JavaScript.

## Verificación

`AdministrativeConcurrencyTest` reproduce dos formularios para los dieciséis tipos administrativos, además de versiones ausentes/inválidas y restricciones SQL. `CatalogReferenceConcurrencyTest` usa procesos y conexiones PostgreSQL independientes: observa `pg_stat_activity` para comprobar que la desactivación espera por un bloqueo y que, tras confirmar, las nuevas selecciones se rechazan. Los fixtures y las operaciones concurrentes se limitan a `sysforest_testing`.

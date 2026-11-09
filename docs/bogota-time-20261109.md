# Hora de Bogotá en la web y PostgreSQL

Por solicitud del 9 de noviembre de 2026, las horas visibles en SysForest se presentan en `America/Bogota`. Una captura de las 17:08 en Bogotá corresponde al instante 22:08 UTC. La conversión no modifica el instante ni exige restar cinco horas a los registros existentes.

## Aplicación y base de datos

`BogotaDateTime` concentra la presentación y los límites de los días de Bogotá. Acepta instantes con zona, conserva microsegundos y trata las marcas heredadas sin offset como UTC. Devuelve una nueva instancia inmutable internamente; no altera los modelos consultados. No se aplica a fechas calendario de inspección, programación o realización de mantenimientos.

El valor predeterminado de zona de la base debe ser `America/Bogota`. Las nuevas sesiones SQL directas muestran así `now()` y las columnas `timestamp with time zone` en hora colombiana. `docker/postgres/database.sql` incluye esta configuración para nuevas instalaciones. Para la base existente, una conexión administrativa a esa misma base puede aplicar:

```sql
DO $timezone$
BEGIN
    EXECUTE format('ALTER DATABASE %I SET timezone TO %L', current_database(), 'America/Bogota');
END
$timezone$;
```

La comprobación del valor predeterminado requiere una conexión nueva. No requiere cambiar el reloj del host ni reiniciar PostgreSQL. Este archivo no acredita por sí mismo que el cambio operativo ya se haya aplicado.

La conexión ORM de Laravel fija `timezone => UTC` y la aplicación conserva UTC. Esto es deliberado: PDO enlaza ciertos objetos de fecha como cadenas sin offset. Cambiar solamente esa sesión a Bogotá desplazaría cinco horas las escrituras que entregan componentes UTC. El ajuste de presentación y el valor predeterminado de la base resuelven la hora visible sin introducir ese desplazamiento.

La inspección previa encontró cinco columnas heredadas sin zona: `users.email_verified_at`, `users.created_at`, `users.updated_at`, `password_reset_tokens.created_at` y `failed_jobs.failed_at`. La migración `000034` convierte exclusivamente esas columnas a `timestamp with time zone`, usando `AT TIME ZONE 'UTC'` para interpretar correctamente el origen. Conserva la precisión individual, nulabilidad y valores predeterminados. Así, las consultas directas con zona Bogotá también presentan esas fechas localmente, sin modificar el instante. La reversión utiliza la operación inversa en UTC. No ejecuta UPDATE ni seeders.

## Pantallas, captura y consultas

La presentación abarca registro y seguimiento del árbol, inspecciones y riesgo, importaciones, evidencias y mantenimiento, publicaciones, sesiones, invitaciones, referencias de carbono y estado de procesos. El pie institucional identifica la zona.

Los calendarios de fotografías reciben hora de Bogotá y la convierten a UTC al guardar. Al editar una fotografía y conservar el mismo minuto visible, se conserva también la precisión del instante original. La captura de ubicación ya utilizaba el calendario colombiano.

Los filtros Desde/Hasta de auditoría y fotografías representan días completos de Bogotá. El límite inicial se incluye y el inicio del día siguiente se excluye. SQL y Mongo consultan los mismos límites UTC. Por ejemplo, el 9 de noviembre de 2026 equivale a `[2026-11-09T05:00:00Z, 2026-11-10T05:00:00Z)`.

PDF y Excel muestran hora de generación colombiana. Los reportes privados de ubicación y confirmación profesional incluyen ISO con offset `-05:00`, con encabezados de Bogotá. El metadato estructurado `generatedAt` conserva su ISO UTC; la conversión se realiza al presentar PDF/Excel. No se cambian contratos móviles, recibos, snapshots, documentos Mongo ni fechas calendario.

## Verificación realizada antes de publicación

Se aplicó PSR-12 a los PHP propios modificados y una inspección manual ejecutable de solo lectura en el contenedor local. Se observó:

- `22:08Z` y `17:08-05:00` muestran `17:08` del mismo día.
- `03:00Z` muestra `22:00` del día anterior.
- Una marca sin offset se interpreta como UTC y una marca nula permanece nula.
- Los microsegundos se conservan en ISO; el objeto original permanece intacto.
- El rango diario de Bogotá tiene límites UTC coincidentes con el calendario local.
- La edición sin cambio conserva `22:08:12.123456Z`; escribir `17:09` local produce `22:09Z`.
- La conexión ORM local reportó `UTC`, mientras la consulta explícita `AT TIME ZONE 'America/Bogota'` mostró cinco horas menos para el mismo instante.

Evidencia: `.web-timezone-work/changed-files.json`, `format.log` y `manual-read-result.json` en el workspace de entrega. No se creó una suite automatizada. La verificación final de publicación y la configuración efectiva de la base se registran en el informe conjunto de entrega.

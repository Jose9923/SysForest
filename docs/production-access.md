# Preparación del acceso en producción

El traslado de la base local conserva usuarios, identificadores, roles, organizaciones, inventarios, árboles, fotografías e historial. Las cuentas que todavía usan la contraseña pública del piloto no deben conservar ese acceso al exponer el servidor. El comando administrativo `sysforest:prepare-production-access` prepara ese cambio sin ejecutar seeders ni añadir instituciones.

## Condiciones

El comando solo funciona desde consola con `APP_ENV=production` o en una verificación aislada con `APP_ENV=testing`. Debe ejecutarse después de restaurar la base y antes de exponer la aplicación y activar sus trabajadores. Requiere el rol nacional `SUPER_ADMIN` ya restaurado, con sus permisos de administración. No crea ni repara la matriz de autorización.

El operador debe confirmar un correo real bajo control del administrador. Para una cuenta nueva, esta confirmación administrativa autoriza marcar el correo verificado durante la preparación por consola; no constituye una verificación automática mediante correo. El comando no envía mensajes, recuperaciones ni invitaciones.

## Vista previa

Sin `--apply`, únicamente se consultan cantidades:

```sh
php artisan sysforest:prepare-production-access
php artisan sysforest:prepare-production-access --admin-email=administracion@tu-dominio.org
```

La respuesta muestra usuarios, contraseñas de desarrollo detectadas, sesiones aún no revocadas y enlaces de recuperación aún no consumidos. Estos últimos conteos incluyen filas expiradas que todavía no han sido retiradas. El estado del administrador puede ser `not_selected`, `new`, `existing_secure` o `blocked`. La vista previa no modifica credenciales, estados ni auditoría.

## Aplicar con un administrador nuevo

Utiliza una terminal interactiva para escribir y confirmar la contraseña oculta:

```sh
php artisan sysforest:prepare-production-access \
  --admin-email=administracion@tu-dominio.org \
  --admin-name="Administrador de producción" \
  --revoke-all-sessions --apply
```

Para una ejecución controlada sin terminal, utiliza `--password-file=/run/sysforest-bootstrap/admin-password`. El archivo debe existir dentro del contenedor, con ruta absoluta, como archivo regular sin enlace simbólico, permisos `0400` o `0600` y fuera de `public` y `storage/app/public`. Debe contener una sola contraseña; se admite un salto de línea final. El comando no acepta la contraseña mediante argumento o variable de entorno, no la imprime y no elimina el archivo. El operador debe retirar el archivo privado después de confirmar el resultado.

La contraseña nueva cumple la política existente: de 12 a 128 caracteres, con mayúscula, minúscula, número y símbolo. No se admite usar una dirección de ejemplo o un dominio local como `.test`. El nombre se exige únicamente al crear al administrador.

La operación completa usa una transacción y bloqueos. Primero valida que puede preparar al administrador; un error revierte todas las modificaciones. Si el correo no existe, crea una cuenta activa y verificada y le asigna el rol nacional existente, sin membresías ficticias. Si ya existe, solo acepta un superadministrador activo y verificado cuya clave no sea la predeterminada; conserva exactamente su nombre, contraseña y roles. Cualquier otra cuenta existente se rechaza: este comando no es un mecanismo de promoción ni de restablecimiento de claves personalizadas.

## Contraseñas y sesiones trasladadas

La detección utiliza `Hash::check` exclusivamente contra la antigua clave general `DevelopmentPasswordService::DEFAULT_PASSWORD`, no el dominio del correo ni el rol. Las claves específicas que el propietario autorizó después para las tres cuentas Meta/INEM no se incorporan a esta detección; su configuración reproducible está descrita en [cuentas de desarrollo](development-accounts.md). Solo las coincidencias se sustituyen por contraseñas aleatorias no entregadas, y se renueva su `remember_token`. Se conservan el identificador, nombre, correo, estado, verificación y asignaciones de cada cuenta. Para esas cuentas se invalidan los enlaces `password_reset` pendientes y se revocan todas sus sesiones.

La opción explícita `--revoke-all-sessions` extiende la revocación a todas las sesiones web y móviles de la base restaurada y consume todos sus enlaces pendientes de recuperación de contraseña. Conserva las contraseñas personalizadas. Los refresh opacos quedan inutilizables porque su sesión está revocada; no se borran sesiones, tokens ni auditoría. Esta opción debe utilizarse en el traslado a producción, además de configurar una nueva clave JWT.

El comando no invalida invitaciones o verificaciones de correo de otros tipos, no ejecuta trabajos pendientes y no cambia `APP_KEY`. Estas decisiones pertenecen al procedimiento de despliegue.

Las cuentas cuya clave se retiró necesitan recuperación mediante un correo real y un transporte de correo configurado, o la gestión administrativa correspondiente. Las direcciones locales `.test` permanecen como identidades históricas y no reciben mensajes reales. No debe volver a ejecutarse `DevelopmentPasswordSeeder` en producción.

## Auditoría y repetición

La salida contiene cantidades y el ID del administrador, nunca contraseñas, hashes, JWT ni tokens de recuperación. Los eventos registran la preparación administrativa, asignación nacional, retiro de claves, invalidación de enlaces y revocación de sesiones con sus identificadores y motivos. Los cambios se confirman junto a la auditoría.

Repetir con el mismo administrador preparado y sin nuevas sesiones o enlaces produce cero retiros y no cambia su contraseña ni vuelve a conceder roles. Con `--revoke-all-sessions`, una repetición sí revoca sesiones creadas después de la ejecución anterior: usarla únicamente durante la preparación o una revocación administrativa deliberada.

## Verificación del 4 de noviembre de 2026

Se ejecutaron 49 comprobaciones temporales sobre PostgreSQL y PostGIS en una base independiente creada para esta revisión, con datos sintéticos y transacción revertida. Se comprobaron vista previa sin escrituras, rechazo del entorno local y de contraseñas débiles, colisiones de correo sin promoción, retiro exclusivo de la clave de desarrollo, conservación exacta de hashes personalizados, estado de cuentas y datos territoriales/forestales, administrador nuevo, repetición idempotente, revocación total, inutilización de refresh web/móvil, autenticación del administrador y auditoría sin secretos. La entrada por archivo comprobó permisos, enlace simbólico, carpeta pública, múltiples líneas y tamaño máximo.

Estas comprobaciones no ejecutan el comando sobre la base operativa ni certifican el despliegue, la entrega de correo o el acceso público. El script temporal y su base se retiran después de la revisión; no se añade una suite permanente.

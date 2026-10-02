# Cuentas de desarrollo

## Alcance y contraseña predeterminada

Por solicitud expresa del usuario, la contraseña local predeterminada es **`password`**. `DevelopmentPasswordService::DEFAULT_PASSWORD` define este valor y `User` almacena únicamente su hash. No se utilizan variables de correo o contraseña en `.env`.

`DevelopmentIdentitySeeder` prepara cuatro perfiles del piloto y aplica la contraseña predeterminada a todas las cuentas existentes mediante `DevelopmentPasswordSeeder`. Para restablecer solo las claves, sin preparar perfiles, roles ni organizaciones, se ejecuta directamente `DevelopmentPasswordSeeder`.

Ambos seeders solo admiten `APP_ENV=local` o `APP_ENV=testing`; rechazan los demás entornos antes de modificar datos, incluso con `--force`. Esta excepción de desarrollo no modifica la política de contraseñas de los formularios de invitación o recuperación.

`DatabaseSeeder` incorpora territorios, organización piloto, catálogos y autorizaciones. Por solicitud expresa del 02/10/2026, en `APP_ENV=local` ejecuta también `DevelopmentIdentitySeeder` (incluido el restablecimiento de contraseñas), `RestrepoOrnatoSeeder` y `RestrepoOrnatoImportSeeder`. Esto aplica a `db:seed` y `migrate --seed`; en otros entornos no añade esas llamadas. La carga requiere el [paquete privado de Ornato](restrepo-ornato-seed.md#integración-local-con---seed). Las cuentas normales se incorporan mediante invitaciones; no existe registro público abierto.

| Perfil preparado por DevelopmentIdentitySeeder | Correo local | Alcance |
| --- | --- | --- |
| SUPER_ADMIN | superadmin@sysforest.test | Nacional; no necesita membresías ficticias. |
| DEPARTMENT_ADMIN | departamento.meta@sysforest.test | Departamento Meta, código 50. |
| ORGANIZATION_ADMIN | admin.inem@sysforest.test | Organización INEM-VVC. |
| OFFICIAL | funcionario.inem@sysforest.test | Organización INEM-VVC. |

El restablecimiento abarca también cualquier otra cuenta de la base local. Las identidades y la contraseña predeterminada corresponden al piloto de desarrollo.

## Instalación local nueva

En `/home/josev/SYSFOREST`:

~~~bash
docker compose exec -T app php artisan db:seed --class=DatabaseSeeder
docker compose exec -T app php artisan db:seed --class=DevelopmentIdentitySeeder --no-interaction
~~~

No se solicita entrada interactiva. Las cuentas nuevas se crean activas, con correo verificado y contraseña `password`. El seeder prepara las dependencias del piloto, los perfiles y sus asignaciones de rol dentro de una transacción.

Los usuarios se buscan por correo normalizado. Una cuenta existente conserva su ID, nombre, estado y verificación. Se sincroniza su asignación con el rol definido; las membresías existentes conservan estado y fechas y una membresía organizacional ausente se crea activa. No se duplican las asignaciones ni se eliminan cuentas. Modificar los correos en el seeder define otras identidades y podría crear cuentas adicionales; no lo utilices para renombrar usuarios existentes.

## Restablecer todas las contraseñas locales

~~~bash
docker compose exec -T app php artisan db:seed --class=DevelopmentPasswordSeeder --no-interaction
~~~

Este comando aplica `password` a todas las cuentas existentes, sin crear usuarios ni modificar permisos, membresías, organizaciones o datos forestales. Cada cambio de contraseña revoca las sesiones de esa cuenta; se debe iniciar sesión nuevamente con la clave predeterminada.

Si una cuenta ya utiliza `password`, se omite su actualización: conserva su hash y sus sesiones. Repetir el comando sin cambios de contraseña intermedios no vuelve a modificarlas. Para elegir después una contraseña individual se utiliza el flujo de la aplicación; volver a ejecutar cualquiera de los dos seeders la restablecerá al valor predeterminado.

## Configuración anterior

Se retiraron las ocho variables de correo y contraseña `DEV_*` de `.env`, `.env.example` y `bin/setup-env`, junto con `config/development.php`. Los secretos técnicos de Laravel, JWT, PostgreSQL y Redis mantienen su configuración independiente.

La migración inicial conservó las contraseñas existentes y pedía entrada oculta para las cuentas nuevas. La autorización posterior de una contraseña predeterminada reemplaza ese comportamiento por los seeders no interactivos descritos aquí. Los informes históricos conservan los resultados correspondientes a su fecha.

## Verificación del restablecimiento autorizado

La ejecución de `DevelopmentPasswordSeeder` actualizó las contraseñas de las cinco cuentas locales existentes; ninguna utilizaba ya el valor predeterminado. Se comprobó que los cinco hashes corresponden a `password` y que el superadministrador inicia sesión correctamente mediante `AuthService`. Esta última comprobación se realizó en una transacción revertida, sin conservar su sesión ni exponer tokens. Inmediatamente después del restablecimiento no quedaban sesiones vigentes; los usuarios pueden volver a iniciarlas con la nueva clave.

Una segunda ejecución informó cero actualizaciones y cinco cuentas sin cambios. La comparación de esa repetición confirmó que los perfiles, roles y datos forestales permanecieron iguales. PSR-12 y sintaxis PHP finalizaron correctamente en los cuatro archivos de código modificados o añadidos.

La revisión de código comprobó las restricciones de consola y entorno, el cast de hash, el bloqueo de usuarios dentro de la transacción, la invalidación de enlaces de recuperación, la revocación de sesiones y la auditoría sin contraseñas ni hashes. También comprobó que las cuentas que ya usan la clave predeterminada se omiten y que la política de contraseñas web permanece intacta.

A petición del usuario no se crean ni se ejecutan pruebas automatizadas.

## Integración local de `--seed` del 02/10/2026

Se verificó `DatabaseSeeder` con Pint PSR-12 y `php -l`, ambos correctos. La ejecución manual de `php artisan db:seed --no-interaction` terminó con código 0 e incluyó las cuentas y la preparación de Restrepo. Las cuatro contraseñas ya coincidían y Restrepo informó cero altas en la repetición. No se ejecutaron suites automatizadas.

La base de este equipo tenía cero árboles. Inicialmente no se encontró el JSON normalizado ni el manifiesto privado de corrección de estados. Después el usuario indicó la ubicación del Excel en Descargas; su huella coincide con el original y permitió reconstruir la carga revisada. La integración posterior y sus límites están documentados en [carga local de Ornato](restrepo-ornato-seed.md#integración-local-con---seed).

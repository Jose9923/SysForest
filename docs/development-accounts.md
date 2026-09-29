# Cuentas de desarrollo

## Alcance

`DevelopmentIdentitySeeder` prepara cuatro perfiles del piloto local. Solo admite `APP_ENV=local` o `APP_ENV=testing`; rechaza producción antes de modificar datos, incluso con `--force`.

Los nombres y correos locales se definen directamente en `database/seeders/DevelopmentIdentitySeeder.php`. Las contraseñas iniciales se introducen de forma oculta al ejecutar el seeder y se almacenan mediante el cast de hash de `User`. No se guardan en `.env`, archivos de configuración, código fuente ni registros.

`DatabaseSeeder` incorpora territorios, organización piloto, catálogos y autorizaciones. **No ejecuta `DevelopmentIdentitySeeder` automáticamente**. Las cuentas normales se incorporan mediante invitaciones; no existe registro público abierto.

| Perfil | Correo local | Alcance |
| --- | --- | --- |
| SUPER_ADMIN | superadmin@sysforest.test | Nacional; no necesita membresías ficticias. |
| DEPARTMENT_ADMIN | departamento.meta@sysforest.test | Departamento Meta, código 50. |
| ORGANIZATION_ADMIN | admin.inem@sysforest.test | Organización INEM-VVC. |
| OFFICIAL | funcionario.inem@sysforest.test | Organización INEM-VVC. |

Estas identidades identifican el piloto de desarrollo; no constituyen credenciales de una instalación de producción.

## Instalación local nueva

Desde una terminal interactiva, en `/home/josev/SYSFOREST`:

~~~bash
docker compose exec app php artisan db:seed --class=DatabaseSeeder
docker compose exec app php artisan db:seed --class=DevelopmentIdentitySeeder
~~~

El segundo comando pide y confirma una contraseña **solo para cada cuenta que todavía no existe**. Debe tener entre 12 y 128 caracteres, con mayúsculas, minúsculas, números y símbolos. Las respuestas no aparecen en pantalla. Guarda las contraseñas elegidas en tu gestor de contraseñas.

No utilices `-T` ni `--no-interaction` para crear cuentas: el seeder exige una terminal capaz de ocultar la entrada y no admite un modo que muestre el secreto. No pases contraseñas como argumentos del comando. Si falta una cuenta y no hay terminal interactiva, el comando termina sin crear cuentas ni sembrar dependencias.

La lectura y validación terminan antes de la transacción. Las cuentas nuevas se crean activas y con correo verificado. El seeder prepara las dependencias del piloto y sus asignaciones de rol dentro de la misma transacción.

## Ejecuciones posteriores

Si las cuatro cuentas ya existen, el seeder también puede ejecutarse sin interacción:

~~~bash
docker compose exec -T app php artisan db:seed --class=DevelopmentIdentitySeeder --no-interaction
~~~

Los usuarios se buscan por correo normalizado. Una cuenta existente conserva su ID, contraseña hash, nombre, estado y verificación. No se le solicita contraseña ni se restablece. Se sincroniza su asignación con el rol definido; las membresías existentes conservan estado y fechas y una membresía organizacional ausente se crea activa. No se duplican las asignaciones ni se eliminan cuentas.

Para cambiar una contraseña utiliza el flujo de contraseña de la aplicación. Modificar los correos en el seeder define otras identidades y podría crear cuentas adicionales; no lo utilices para renombrar usuarios existentes.

## Migración desde la configuración anterior

Se retiraron las ocho variables de correo y contraseña `DEV_*` de `.env`, `.env.example` y `bin/setup-env`, junto con `config/development.php`. Los secretos técnicos de Laravel, JWT, PostgreSQL y Redis mantienen su configuración independiente.

Las cuatro identidades configuradas coincidían con las cuentas existentes del piloto. La migración conserva las contraseñas almacenadas: se inicia sesión con las mismas credenciales que ya utilizabas. Tras retirar las variables se deben recrear los contenedores PHP para que tampoco permanezcan en su entorno de procesos; reiniciar únicamente PHP no reemplaza las variables con las que se crearon los contenedores.

## Verificación de la modificación

Se ejecutó el seeder con `--no-interaction` sobre el piloto existente y terminó correctamente sin pedir claves. La comparación íntegra de los cinco registros de usuario, antes y después, confirmó que se conservaron todos sus campos, incluidos los hashes de contraseña. Se retiraron exactamente ocho variables del `.env` real; el resto de su contenido y sus permisos `0600` permanecieron intactos.

La sintaxis PHP del seeder, la sintaxis de Bash en `bin/setup-env` y PSR-12 sobre el seeder finalizaron correctamente. La búsqueda de referencias activas no encontró lecturas de las variables retiradas. A petición del usuario no se crean ni se ejecutan pruebas automatizadas. La creación de cuentas ausentes con entrada interactiva se revisó en código y no se ejercitó sobre la base del piloto, para conservar sus cinco usuarios.

# Cuentas de desarrollo

## Contraseñas iniciales por cuenta

Por solicitud expresa del 4 de noviembre de 2026, `DevelopmentPasswordService::passwordForEmail()` define la contraseña inicial elegida por el propietario para estas tres identidades exactas:

| Rol | Correo | Alcance |
| --- | --- | --- |
| DEPARTMENT_ADMIN | departamento.meta@sysforest.test | Departamento Meta, código 50. |
| ORGANIZATION_ADMIN | admin.inem@sysforest.test | Organización INEM-VVC. |
| OFFICIAL | funcionario.inem@sysforest.test | Organización INEM-VVC. |

El resolver normaliza espacios externos y mayúsculas del correo. La selección es por correo exacto, no por rol ni por dominio: no asigna esta clave a otros administradores o funcionarios. `DevelopmentPasswordService::DEFAULT_PASSWORD` conserva el valor general anterior para el resto de las cuentas locales, incluido `superadmin@sysforest.test`. La cuenta administradora de producción `varcor.soluciones@gmail.com` no forma parte de este mapeo.

La configuración inicial solicitada está centralizada en el servicio; la documentación no duplica su valor. El modelo `User` guarda únicamente su hash en la base; no se agregan credenciales de usuario a `.env` ni se cambia la autenticación para aceptar claves alternativas.

`DevelopmentIdentitySeeder` prepara los cuatro perfiles del piloto y utiliza el resolver al crear cada cuenta. Al finalizar llama a `DevelopmentPasswordSeeder`, que restablece las cuentas existentes con el valor que corresponda a su correo. Para restablecer solo claves sin preparar perfiles, roles u organizaciones, se ejecuta directamente este último seeder.

Ambos seeders solo admiten consola con `APP_ENV=local` o `APP_ENV=testing`; rechazan producción antes de modificar datos, incluso con `--force`. Publicar código o reiniciar servicios no ejecuta estos seeders ni restablece contraseñas. La política de los formularios web permanece intacta.

`DatabaseSeeder` incorpora territorios, organización piloto, catálogos y autorizaciones. En `APP_ENV=local` ejecuta también las cuentas de desarrollo y la carga autorizada de Restrepo. La carga completa requiere el [paquete privado de Ornato](restrepo-ornato-seed.md#integración-local-con---seed). Para preparar únicamente las cuentas utiliza el comando específico siguiente.

## Preparar las cuentas locales

Desde `/home/josev/SYSFOREST`:

~~~bash
docker compose exec -T app php artisan db:seed --class=DevelopmentIdentitySeeder --no-interaction
~~~

Las cuentas nuevas se crean activas y verificadas con la clave correspondiente a su correo. El seeder prepara las dependencias del piloto, perfiles y roles en una transacción. `SUPER_ADMIN` tiene alcance nacional; `DEPARTMENT_ADMIN` tiene jurisdicción Meta sin membresías institucionales ficticias; los dos perfiles INEM pertenecen a INEM-VVC.

Los usuarios se buscan por correo normalizado. Una cuenta existente conserva su ID, nombre, estado y verificación. Se sincroniza su asignación con el rol definido; las membresías existentes conservan estado y fechas y una membresía organizacional ausente se crea activa. No se duplican asignaciones ni se eliminan cuentas. Modificar correos en el seeder define otras identidades; no es un mecanismo para renombrar usuarios.

## Restablecer las contraseñas locales

~~~bash
docker compose exec -T app php artisan db:seed --class=DevelopmentPasswordSeeder --no-interaction
~~~

Este comando aplica la clave específica configurada a las tres cuentas de la tabla y el valor general anterior al resto de las cuentas existentes. No crea usuarios ni modifica permisos, membresías, organizaciones o datos forestales. Cada cambio renueva `remember_token`, invalida enlaces de recuperación pendientes, revoca las sesiones de esa cuenta y registra auditoría sin la contraseña ni su hash.

Si una cuenta ya utiliza su clave configurada, se omite la actualización y conserva el hash y sus sesiones. Repetir el comando sin cambios intermedios no vuelve a modificarla. Una contraseña elegida posteriormente mediante la aplicación se conserva durante los despliegues, pero volver a ejecutar expresamente estos seeders locales la restablece al valor configurado.

## Relación con producción

Las mismas tres cuentas del servidor recibieron la clave elegida mediante una operación administrativa explícita el 4 de noviembre, conservando sus roles. Este cambio en el código hace reproducible la preparación local; no ejecuta un restablecimiento adicional en el VPS.

El comando independiente `sysforest:prepare-production-access` conserva su detección de la antigua clave general; este ajuste no amplía su alcance para retirar las claves específicas posteriormente autorizadas. No utilizar los seeders de desarrollo para actualizar producción. Consulta [preparación del acceso](production-access.md).

## Configuración anterior

Se retiraron las ocho variables de correo y contraseña `DEV_*` de `.env`, `.env.example` y `bin/setup-env`, junto con `config/development.php`. Los secretos técnicos de Laravel, JWT, PostgreSQL y Redis mantienen su configuración independiente.

La migración inicial conservó las contraseñas existentes y pedía entrada oculta para las cuentas nuevas. La autorización posterior de una contraseña predeterminada reemplaza ese comportamiento por los seeders no interactivos descritos aquí. Los informes históricos conservan los resultados correspondientes a su fecha.

## Verificación histórica del restablecimiento general

La ejecución de `DevelopmentPasswordSeeder` actualizó las contraseñas de las cinco cuentas locales existentes; ninguna utilizaba ya el valor predeterminado. Se comprobó que los cinco hashes corresponden a `password` y que el superadministrador inicia sesión correctamente mediante `AuthService`. Esta última comprobación se realizó en una transacción revertida, sin conservar su sesión ni exponer tokens. Inmediatamente después del restablecimiento no quedaban sesiones vigentes; los usuarios pueden volver a iniciarlas con la nueva clave.

Una segunda ejecución informó cero actualizaciones y cinco cuentas sin cambios. La comparación de esa repetición confirmó que los perfiles, roles y datos forestales permanecieron iguales. PSR-12 y sintaxis PHP finalizaron correctamente en los cuatro archivos de código modificados o añadidos.

La revisión de código comprobó las restricciones de consola y entorno, el cast de hash, el bloqueo de usuarios dentro de la transacción, la invalidación de enlaces de recuperación, la revocación de sesiones y la auditoría sin contraseñas ni hashes. También comprobó que las cuentas que ya usan la clave predeterminada se omiten y que la política de contraseñas web permanece intacta.

Esta verificación histórica corresponde al valor general anterior. Se conserva la decisión de no añadir una suite permanente.

## Integración local de `--seed` del 02/10/2026

Se verificó `DatabaseSeeder` con Pint PSR-12 y `php -l`, ambos correctos. La ejecución manual de `php artisan db:seed --no-interaction` terminó con código 0 e incluyó las cuentas y la preparación de Restrepo. Las cuatro contraseñas ya coincidían y Restrepo informó cero altas en la repetición. No se ejecutaron suites automatizadas.

La base de este equipo tenía cero árboles. Inicialmente no se encontró el JSON normalizado ni el manifiesto privado de corrección de estados. Después el usuario indicó la ubicación del Excel en Descargas; su huella coincide con el original y permitió reconstruir la carga revisada. La integración posterior y sus límites están documentados en [carga local de Ornato](restrepo-ornato-seed.md#integración-local-con---seed).

## Verificación de las claves por cuenta del 4 de noviembre de 2026

Pint con PSR-12 y `php -l` aprobaron los dos archivos PHP modificados. Una comprobación temporal en memoria verificó los tres correos, su normalización, el hash del modelo, el valor general para identidades ajenas y el rechazo de ambos seeders cuando el proceso declara producción: 16 comprobaciones, cero consultas a la base. No se ejecutó un restablecimiento real ni se cambiaron cuentas durante esta revisión. La comprobación temporal se retiró y no se añadió una suite permanente.

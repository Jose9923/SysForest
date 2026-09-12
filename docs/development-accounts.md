# Cuentas de desarrollo

## Alcance

DevelopmentIdentitySeeder prepara cuatro cuentas para comprobar los perfiles de acceso en el entorno local. Solo admite APP_ENV=local o APP_ENV=testing. Rechaza producción antes de modificar datos, incluso si el comando Artisan utiliza --force.

DatabaseSeeder incorpora territorios, organización piloto y AuthorizationSeeder. **No ejecuta DevelopmentIdentitySeeder automáticamente**, en ningún entorno.

| Perfil | Asignación | Membresía creada |
| --- | --- | --- |
| SUPER_ADMIN | Nacional | Ninguna |
| DEPARTMENT_ADMIN | Departamento Meta, código 50 | Ninguna |
| ORGANIZATION_ADMIN | Rol organizacional en INEM-VVC | INEM |
| OFFICIAL | Rol organizacional en INEM-VVC | INEM |

El administrador nacional no requiere pertenencias ficticias para acceder a las organizaciones.

## Variables

config/development.php obtiene los datos de estas variables, sin contraseñas ni correos predeterminados:

| Cuenta | Correo | Contraseña inicial |
| --- | --- | --- |
| SUPER_ADMIN | DEV_SUPER_ADMIN_EMAIL | DEV_SUPER_ADMIN_PASSWORD |
| DEPARTMENT_ADMIN | DEV_DEPARTMENT_ADMIN_EMAIL | DEV_DEPARTMENT_ADMIN_PASSWORD |
| ORGANIZATION_ADMIN | DEV_ORGANIZATION_ADMIN_EMAIL | DEV_ORGANIZATION_ADMIN_PASSWORD |
| OFFICIAL | DEV_OFFICIAL_EMAIL | DEV_OFFICIAL_PASSWORD |

Configura sus valores en el .env local no versionado o mediante el mecanismo de secretos del entorno. No incluyas sus valores reales en documentación, repositorio ni capturas.

Los cuatro correos deben ser válidos y distintos, sin distinción de mayúsculas. Cada contraseña debe tener entre 12 y 128 caracteres e incluir mayúsculas, minúsculas, números y símbolos. Toda la configuración se valida antes de crear cuentas o sembrar catálogos. Los mensajes de error identifican las variables que requieren revisión sin incluir sus valores.

## Ejecución explícita

Desde el proyecto local:

~~~bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan db:seed --class=DatabaseSeeder
docker compose exec app php artisan db:seed --class=DevelopmentIdentitySeeder
~~~

El seeder de desarrollo también prepara sus dependencias de catálogo y piloto dentro de una transacción. No elimina cuentas, organizaciones ni membresías.

Las cuentas nuevas se crean activas y con correo verificado, con las contraseñas almacenadas mediante el cast de hash de User. Pueden utilizar la pantalla /login con el correo y contraseña configurados para ese perfil.

## Ejecuciones posteriores

Los usuarios se buscan por correo normalizado. Si una cuenta ya existe:

- Conserva su ID, contraseña, nombre, estado global y verificación.
- Se sincroniza la asignación con el rol definido para el perfil de desarrollo.
- Las membresías existentes conservan su estado y fechas; si falta la membresía INEM de un perfil organizacional, se crea activa.
- Las asignaciones nacionales/departamentales y las membresías organizacionales no se duplican.

Cambiar una variable DEV_*_PASSWORD y volver a ejecutar el seeder **no restablece la contraseña de una cuenta existente**. Para cambiarla se utiliza el flujo de contraseña de la aplicación. Una cuenta existente desactivada o sin verificar tampoco se habilita silenciosamente.

Las contraseñas configuradas deben seguir cumpliendo la política aunque las cuentas ya existan, porque la validación se completa antes de cualquier escritura.

## Pruebas

~~~bash
docker compose exec app php artisan test --filter=DevelopmentIdentitySeederTest
~~~

Las pruebas sustituyen la configuración por credenciales de fixture y usan la base transaccional protegida por PostgresTestCase. No imprimen ni dependen de las credenciales reales del entorno.

La suite focalizada comprueba entornos local/testing, rechazo de producción, validación previa, correos distintos, roles correctos, ausencia de membresías ficticias, idempotencia, conservación de contraseñas y exclusión del seeder de desarrollo del flujo general. Resultado: **9 pruebas aprobadas y 57 aserciones**.

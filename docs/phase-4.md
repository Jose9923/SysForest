# SYSFOREST · Cierre de Fase 4

Fecha de verificación: 12 de septiembre de 2026. Proyecto: `/home/josev/SYSFOREST`, Ubuntu-24.04 WSL. Alcance: autenticación, usuarios globales, RBAC, membresías e identidad por correo. **La Fase 5 no se inició.**

Se conserva Laravel 13, PHP 8.4, MVC, Controller → Service → Repository → Model y Blade. No se agregaron módulos forestales, mapas operativos, importadores ni reportes forestales.

## Checkpoint previo

Se inspeccionó Git y se comprobó que `.phase3-work/` no existe en el proyecto definitivo ni es una dependencia de ejecución. Los transportes de parches están fuera de él, en el espacio de trabajo de Windows. Se excluyen `.phase*-work/` tanto de Git como del contexto Docker.

Antes de implementar pasaron las **78 pruebas y 255 aserciones** de la Fase 3 y PSR-12 sobre 86 archivos. Se creó el checkpoint solicitado:

~~~text
28e7c4b feat: complete phase 3 multitenancy foundation
~~~

La implementación se hizo en `codex/phase-4`, mediante pasos pequeños, migraciones incrementales y comprobaciones separadas.

## A. Migraciones nuevas

| Archivo en database/migrations | Cambio |
| --- | --- |
| 2026_09_12_000002_extend_users_for_identity.php | users.active, índice único lower(email), security_events. |
| 2026_09_12_000003_create_authorization_tables.php | Roles, permisos, relaciones y asignaciones por ámbito con FK y CHECK. |
| 2026_09_12_000004_create_authentication_tables.php | Sesiones autenticadas y refresh opacos almacenados como hash. |
| 2026_09_12_000005_create_account_action_tables.php | Invitaciones y tokens de verificación/recuperación. |

Aplicadas en `sysforest` y `sysforest_testing` por el rol migrador. No se ejecutó migrate:fresh ni se sustituyeron los datos existentes. Antes del índice único se comprobó que no hubiera grupos de correos duplicados al ignorar mayúsculas.

## B. Tablas

Once tablas nuevas: `security_events`, `roles`, `permissions`, `role_permissions`, `national_role_assignments`, `department_role_assignments`, `organization_role_assignments`, `auth_sessions`, `refresh_tokens`, `invitations` y `account_action_tokens`.

`users.id` conserva BIGINT. `users.active` es global; `organization_memberships.active` afecta únicamente a la pertenencia correspondiente. Se conserva la unicidad usuario/organización previa.

Las FK compuestas impiden asignar un rol a un scope incompatible y relacionar una membresía de B con una asignación de A. Los hashes son únicos y tienen formato hexadecimal validado en PostgreSQL. Solo puede existir un refresh no consumido por sesión y una invitación pendiente por organización/correo.

## C. Models

Nuevos: `SecurityEvent`, `Role`, `Permission`, `NationalRoleAssignment`, `DepartmentRoleAssignment`, `OrganizationRoleAssignment`, `AuthSession`, `RefreshToken`, `Invitation` y `AccountActionToken`.

Actualizados: `User` implementa JWTSubject y MustVerifyEmail y convierte active a booleano; `OrganizationMembership` incorpora la relación roleAssignment. Los hashes se ocultan de las representaciones serializadas de tokens e invitaciones.

## D. Roles

| Rol | Uso actual |
| --- | --- |
| SUPER_ADMIN | Administración nacional explícita, sin membresías artificiales. |
| DEPARTMENT_ADMIN | Consulta del departamento asignado y sus organizaciones. |
| ORGANIZATION_ADMIN | Administración institucional, membresías e invitaciones de su organización. |
| OFFICIAL | Consulta institucional; sin gestión de usuarios ni configuración sensible. |

## E. Permisos

El catálogo inicial tiene **18 permisos**:

- organizations.view, organizations.create, organizations.manage.
- memberships.view, memberships.create, memberships.update, memberships.activate, memberships.deactivate.
- users.view, users.manage, users.invite.
- territories.view, territories.manage.
- roles.assign-national, roles.assign-department, roles.assign-organization.
- sessions.view, sessions.revoke.

La [matriz completa](authorization.md) distingue concesiones nacionales, departamentales y organizacionales. El perfil y las sesiones propias se autorizan por identidad/propiedad. No se sembraron capacidades forestales todavía.

## F. Asignaciones y scope

Se eligieron tres tablas de asignación, con FK reales, en lugar de scope_id polimórfico sin integridad referencial. Una cuenta puede ser ORGANIZATION_ADMIN en A y OFFICIAL en B. Un DEPARTMENT_ADMIN tiene un departamento concreto.

`TenantContext` contiene actor, organización y modo: `organization`, `national` o `department_read`. Un permiso de consulta departamental no se convierte en permiso de escritura organizacional. Las asignaciones y el estado de la cuenta se reconsultan; no se confía en un rol incluido en el JWT.

## G. Services

Nuevos servicios y responsabilidades:

| Service | Responsabilidad |
| --- | --- |
| AuthService | Comprobar credenciales y crear sesión autenticada. |
| TokenService | Emitir y verificar JWT y sus claims. |
| RefreshTokenService | Rotar tokens y confirmar revocación por reuso. |
| SessionService | Validar y revocar sesiones persistidas. |
| LogoutService | Revocar mediante refresh y/o access válido. |
| UserService | Perfil, estado global, sesiones propias y asignaciones administrativas. |
| PermissionService | Decisiones centralizadas de permiso y ámbito. |
| RoleAssignmentService | Autorizar asignaciones por scope. |
| InvitationService | Emisión y aceptación transaccional de invitaciones. |
| PasswordService | Recuperación y cambio con revocación de sesiones. |
| EmailVerificationService | Emisión y consumo de verificación de correo. |
| SecurityMailService | Preparar correo Blade y encolarlo cifrado tras commit. |
| WebSessionService | Renovar sesión web/CSRF y construir respuestas con cookies. |
| IdentityPageService | Preparar datos y validar enlaces de identidad. |
| MembershipPageService | Preparar datos para vistas de miembros e invitaciones. |

Actualizados OrganizationService, OrganizationMembershipService, TenantContextService y TerritoryService. Las reglas y transacciones permanecen en Services. Los Controllers adaptan entrada/salida HTTP y Blade recibe datos preparados.

## H. Repositories

Nuevos: AccountActionTokenRepository, AuthSessionRepository, InvitationRepository, PermissionRepository, RefreshTokenRepository, RoleAssignmentRepository, RoleRepository y SecurityEventRepository.

Actualizados: UserRepository, OrganizationMembershipRepository, OrganizationRepository, CountryRepository y DepartmentRepository. Se usan consultas explícitas por ámbito, eager loading y bloqueo de filas; no se introdujeron interfaces vacías.

## I. Controllers

Nuevos: LoginController, LogoutController, RefreshController, PasswordResetController, EmailVerificationController, InvitationController, ProfileController, UserController y OrganizationAdministrationController.

OrganizationController, OrganizationMembershipController y TerritoryController se integraron con los nuevos Services. TenantSelectionController conserva la selección validada por TenantContextService.

La administración nacional de organizaciones inactivas tiene rutas separadas bajo `/administration/organizations`; no requiere fingir un contexto operativo activo.

## J. Requests

IdentityRequest reúne mensajes de validación en español. Los formularios usan LoginRequest, RefreshRequest, PasswordEmailRequest, PasswordResetRequest, PasswordChangeRequest, AccountTokenRequest, InvitationCreateRequest, InvitationAcceptRequest, ProfileUpdateRequest, UserStateRequest, RoleAssignmentRequest, MembershipEditRequest y AdministrativeOrganizationRequest.

Los Requests institucionales existentes siguen validando organizaciones y selección. Los campos de reasignación institucional y atributos globales no permitidos son rechazados. Contraseñas y tokens no se guardan como old input.

## K. Policies

OrganizationPolicy y OrganizationMembershipPolicy comprueban cuenta, permiso, recurso y contexto. AppServiceProvider registra Gates respaldados por PermissionService, sin Gate::before universal ni permisos derivados del tipo de institución.

OFFICIAL no ve enlaces de administración de miembros y recibe 403 si solicita esas rutas. Un UUID conocido de otra organización no concede acceso.

## L. Middleware

- AuthenticateJwtCookie: valida exclusivamente el access de cookie, la firma, claims, sesión y estado global.
- OptionalJwtCookie: permite abrir enlaces públicos como invitado ante cookies ausentes o inválidas; no repite el controlador.
- BindAuthenticatedSession: una identidad/sesión distinta limpia la selección institucional anterior.
- RequireVerifiedEmail: bloquea uso interno normal de una cuenta no verificada.
- ShareTenantContext y ResolveTenantContext: revalidan selección y limpian contexto mediante finally.
- IdentityResponseHeaders: no-store, no-referrer, nosniff y protección de framing.

Se retiraron LocalAccessCommand, LocalAccessController, LoadLocalAccessGrant, LocalAccessService, LocalAccessContext y la vista de acceso técnico. Sus rutas ya no existen. La sesión web Redis solo conserva CSRF y navegación; el guard de contexto no recupera identidad de sesiones antiguas.

## M. Mailables

SecurityNotification renderiza `mail.security` para ocho clases de mensaje: invitación, bienvenida, verificación, recuperación, cuenta activada/desactivada y membresía activada/desactivada.

SendSecurityMail implementa ShouldQueue y ShouldBeEncrypted, se despacha afterCommit y utiliza Redis. Se rechaza el transporte log para mensajes con enlaces sensibles. Las excepciones de entrega no copian su cuerpo a los logs.

Mailpit captura SMTP dentro de Compose, en puerto interno 1025. Su buzón solo está publicado en `127.0.0.1:8025`. La comprobación real produjo el asunto **Recupera tu acceso a SYSFOREST**, recibido el 12/09/2026 a las 18:06:59 UTC, después de procesarlo el worker. No se configuró ningún proveedor externo.

## N. Vistas Blade

Pantallas nuevas: login, recuperación, restablecimiento, verificación, renovación de sesión, enlace inválido, perfil, sesiones propias, lista de miembros, nueva invitación, aceptación, usuarios globales y detalle administrativo de cuenta.

Se agregó el layout auth y auth.css. El layout institucional y la navegación muestran organización activa, modo departamental y opciones autorizadas. Se mantiene el logo original, la identidad verde y Select2 para los selectores institucionales existentes. El menú móvil permite múltiples filas.

No se añadieron consultas en Blade ni lógica de negocio en JavaScript. Se revisaron login y recuperación visualmente en el navegador, incluido 390 × 844 sin desbordamiento horizontal. Las vistas privadas se comprobaron mediante tests HTTP y los flujos HTTP reales descritos en S.

## O. Seeders

AuthorizationSeeder crea el catálogo de cuatro roles y 18 permisos. DatabaseSeeder carga el piloto y el catálogo, sin crear cuentas de desarrollo automáticamente.

DevelopmentIdentitySeeder debe ejecutarse explícitamente. Rechaza producción, exige configuración completa y contraseñas fuertes y conserva contraseñas y estado de cuentas existentes.

Se crearon y verificaron cuatro cuentas locales, una por rol. SUPER_ADMIN y DEPARTMENT_ADMIN tienen cero membresías; ORGANIZATION_ADMIN y OFFICIAL tienen una membresía del INEM. Las credenciales proceden de variables DEV_* en el archivo privado .env. [Procedimiento de desarrollo](development-accounts.md).

## P. Flujo JWT

1. POST /login con CSRF y límite de intentos.
2. AuthService comprueba credenciales/cuenta, bloquea usuario y crea auth_sessions.
3. TokenService emite JWT HS256 de **10 minutos**, limitado también por el vencimiento absoluto de la sesión.
4. AuthCookies entrega access y refresh mediante cookies HttpOnly, host-only, path / y SameSite=Lax.
5. Cada petición interna valida firma, emisor, audiencia, expiración, sub/sid, sesión no revocada y usuario activo.

Secure solo puede ser false en HTTP local; es true fuera de ese caso. No hay JWT en URLs, cuerpos de respuesta, localStorage ni sessionStorage.

Se verificó compatibilidad de php-open-source-saver/jwt-auth **2.9.3** con PHP 8.4 e Illuminate 13 antes de instalarlo. La versión está fijada en composer.json/lock. El refresh JWT del paquete no se utiliza. [Contrato de autenticación](authentication.md).

## Q. Flujo refresh y revocación

POST /auth/refresh necesita CSRF y la cookie opaca de refresh. El token aleatorio tiene 32 bytes y solo persiste SHA-256. Se bloquea en orden usuario → sesión → token, se consume el anterior y se crea un nuevo token y access.

La sesión/familia tiene vencimiento absoluto de **10080 minutos (7 días)**: renovar no lo extiende. La reutilización de un token consumido confirma la revocación de toda la sesión antes de responder 401. Dos renovaciones simultáneas del mismo token producen una rotación y luego revocación por reuso; no existe una ventana de tolerancia que deje un duplicado válido.

Logout revoca en base, borra ambas cookies e invalida sesión web/CSRF. Funciona con access vencido utilizando refresh y con access válido aunque falte refresh. Cambiar o restablecer contraseña revoca todas las sesiones.

Los enlaces de correo son tokens de acción distintos del JWT/refresh. Se almacenan mediante hash y se consumen por POST. Verificación/recuperación: 60 minutos. Invitaciones: 48 horas. La aceptación de una dirección ya registrada exige autenticarse con esa cuenta; no permite sustituir su contraseña.

## R. Selección tenant

Tras login se muestra el listado autorizado. La selección es **explícita**, incluso si solo existe una organización. Se puede cambiar sin cerrar sesión.

POST /active-organization recibe un UUID, pero TenantContextService verifica usuario activo/verificado, organización vigente, membresía/rol o alcance administrativo válido antes de guardar active_organization_id en Redis. Cada petición vuelve a validar esa autoridad.

El SUPER_ADMIN selecciona por permiso nacional, sin pertenencia artificial. DEPARTMENT_ADMIN activa modo department_read. La escritura requiere autorización organizacional/nacional y coincidencia con la selección. [Detalles de aislamiento](multitenancy.md).

## S. Resultados de seguridad

| Riesgo | Comprobación implementada |
| --- | --- |
| XSS | Escape Blade en perfil, formularios y correo; pruebas con texto script. |
| CSRF | Protección Laravel conservada y pruebas con comprobación real habilitada; HTTP local sin token devuelve 419. |
| IDOR / tenant escape | UUID ajeno se combina con tenant verificado; se prueba rechazo y ausencia de cambios. |
| Fijación de sesión | Login invalida la sesión web, regenera CSRF y no hereda selección arbitraria. |
| JWT inválido o revocado | Firma/claims y sesión persistida se validan; solo la cookie access autentica. |
| Refresh replay | Transacción, bloqueo real, índice de token actual y revocación de familia confirmada. |
| Escalada de privilegios | Roles y permisos persistidos; scopes con FK/CHECK; administración institucional no concede roles globales. |
| Mass assignment | Requests prohíben atributos protegidos y repositorios aplican listas explícitas. |
| Open redirect | Destinos nombrados internos; no se utiliza return_url arbitrario. |
| Enumeración en recuperación | Mismo mensaje de respuesta para cuenta inexistente/inactiva; no se publica el resultado del envío. |
| Tokens en logs | Nginx registra $uri sin query/referrer; correo cifrado en cola, metadatos de auditoría limitados y secretos omitidos de errores. |
| Rate limiting | Login 10/min, refresh 30/min, password 5/min e invitaciones 20/min, configurables; límite adicional por IP. |

Prueba real contra Nginx en localhost:8080, con credenciales solo en memoria:

| Rol | Login / perfil / refresh / logout | POST sin CSRF | Gestión de miembros |
| --- | --- | --- | --- |
| SUPER_ADMIN | PASS | 419 | 200 |
| DEPARTMENT_ADMIN | PASS | 419 | 403 |
| ORGANIZATION_ADMIN | PASS | 419 | 200 |
| OFFICIAL | PASS | 419 | 403 |

La comprobación de los 268 archivos versionables existentes en ese momento y de los últimos 20 minutos de logs de app/queue/nginx no encontró valores privados de .env ni JWT o enlaces de acción completos. .env está ignorado y .phase3-work no existe en el proyecto.

Composer audit sobre composer.lock: **sin avisos de vulnerabilidad conocidos** al ejecutar la comprobación. Esto no sustituye revisiones posteriores de dependencias.

## T. Tests nuevos

Diez archivos nuevos con 125 casos:

| Clase | Casos |
| --- | ---: |
| AuthenticationTest | 12 |
| AuthorizationTest | 23 |
| DevelopmentIdentitySeederTest | 9 |
| EmailVerificationTest | 8 |
| IdentityAdministrationHttpTest | 14 |
| IdentityHttpTest | 16 |
| IdentityLinkHttpTest | 7 |
| InvitationTest | 15 |
| PasswordSecurityTest | 13 |
| RefreshTokenTest | 8 |

Se conservaron los 78 casos previos. Los siete de acceso técnico ahora demuestran su retiro y la falta de autoridad de enlaces/grants/sesiones legadas. Los fixtures HTTP emiten JWT firmados y sesiones reales; no se usaron bypass de middleware para sostener las expectativas antiguas.

Las pruebas usan PostgreSQL/PostGIS real en sysforest_testing y protección explícita contra ejecución sobre la base operativa. La concurrencia utiliza procesos y conexiones separados y comprueba espera por bloqueo. Las suites que comparten esta base deben ejecutarse serialmente.

## U. Total de pruebas y aserciones

**203 pruebas aprobadas, 922 aserciones, 0 fallos.** Duración: 18,17 segundos. Salida del comando: 0. Incluye las 78 pruebas anteriores y 125 casos nuevos.

Comando final:

~~~bash
docker compose exec -T app php artisan test --fail-on-warning --log-junit /tmp/sysforest-phase4-tests.xml
~~~

## V. PSR-12

`composer format` aplica `pint --preset psr12`. Resultado sobre el proyecto: **166 archivos aprobados**. Comentarios y PHPDoc útiles en español; clases, métodos y variables técnicas en inglés.

La comprobación final usa `docker compose exec -T app composer lint`.

## W. Docker Compose, servicios y versiones

Se reconstruyó sysforest-app:local y se ejecutó **docker compose up -d**, con salida 0. No se rediseñó la estructura de Fase 2; se agregó Mailpit y se configuró correo/identidad.

| Componente | Versión comprobada |
| --- | --- |
| PHP | 8.4.25 |
| Laravel | 13.31.0 |
| PostgreSQL | 17.5 |
| PostGIS | 3.5.2 |
| Redis | PONG; imagen redis:7.4-alpine |
| JWT Auth | 2.9.3 |
| Mailpit | v1.31.1 |

Resultado de `docker compose ps -a` (estado observado tras la reconstrucción final):

~~~text
NAME                    IMAGE                     SERVICE     STATUS                     PORTS
sysforest-app-1          sysforest-app:local       app         Up 4 minutes (healthy)     9000/tcp
sysforest-db-init-1      postgis/postgis:17-3.5    db-init     Exited (0) 4 minutes ago
sysforest-init-1         sysforest-app:local       init        Exited (0) 4 minutes ago
sysforest-mailpit-1      axllent/mailpit:v1.31.1   mailpit     Up 38 minutes (healthy)    127.0.0.1:8025->8025/tcp
sysforest-nginx-1        nginx:1.28-alpine         nginx       Up 4 hours (healthy)       127.0.0.1:8080->80/tcp
sysforest-postgres-1     postgis/postgis:17-3.5    postgres    Up 4 hours (healthy)       5432/tcp
sysforest-queue-1        sysforest-app:local       queue       Up 4 minutes (healthy)     9000/tcp
sysforest-redis-1        redis:7.4-alpine          redis       Up 4 hours (healthy)       6379/tcp
sysforest-scheduler-1    sysforest-app:local       scheduler   Up 4 minutes (healthy)     9000/tcp
~~~

Siete servicios saludables y dos inicializadores completados.

Nginx expone exclusivamente public. PostgreSQL, Redis, PHP-FPM y SMTP no publican puertos del host. app, queue y scheduler comparten la imagen PHP. db-init e init finalizan correctamente con código 0.

Worker: queue:work redis, tres intentos y timeout de 60 segundos; correo cifrado procesado y entregado a Mailpit. Scheduler: schedule:work; conserva el probe de infraestructura cada minuto. Después de la reconstrucción final, el probe de cola registró procesamiento a las **18:19:53 UTC** y el scheduler registró ejecución a las **18:20:00 UTC** del 12/09/2026, consultados desde la página de infraestructura.

## X. Git status

Rama: `codex/phase-4`. HEAD: checkpoint `28e7c4b`. Los cambios de Fase 4 quedan sin commit para revisión; no se hizo merge ni publicación. .env permanece ignorado.

El [inventario completo](phase-4-files.txt) contiene git status --short --untracked-files=all al cierre. git diff --check finalizó con salida 0.

## Y. Archivos creados y modificados

[Lista completa y estado Git por archivo](phase-4-files.txt). Incluye infraestructura de correo, clases de identidad/autorización, migraciones, vistas, estilos, pruebas y documentación, así como el retiro explícito de los seis adaptadores temporales.

Documentación vigente: README.md, authentication.md, authorization.md, account-recovery.md, development-accounts.md, multitenancy.md y este reporte. Los reportes de Fases 2 y 3 conservan su valor histórico.

## Z. Incidencias, límites y decisiones pendientes

- La integración de Docker Desktop presentó el error conocido de su helper de credenciales Windows al descargar/construir imágenes. Se utilizó temporalmente una configuración vacía solo para imágenes públicas, sin modificar el helper global. El arranque final estándar funcionó.
- Se detectaron deadlocks cuando varios procesos de pruebas sembraban simultáneamente el mismo catálogo. Se detuvieron las ejecuciones paralelas y se ejecutó la suite completa de forma serial.
- La revisión corrigió logout sin refresh, cookies inválidas en enlaces públicos, invitaciones abiertas con otra identidad, parámetros token enviados como arrays y bloqueo estable de cuentas para mutaciones críticas.
- Una expectativa nueva asumía selección automática de la única organización. Se ajustó al flujo de selección explícita conservado por la aplicación, manteniendo la comprobación de aislamiento y CSRF.
- La revisión automática rechazó una acción que habría mostrado una contraseña de .env en la salida. No se expuso; se verificó el flujo local mediante HTTP usando credenciales solo en memoria y resultados resumidos.
- El transporte operativo de producción, TLS/dominio, copias de seguridad y política de retención/purga de sesiones/tokens/eventos siguen siendo decisiones de despliegue. No se elimina aún el historial de tokens consumidos porque participa en la detección de reuso.
- Los permisos de módulos futuros y la auditoría forestal completa corresponden a sus fases. No existen sus pantallas ni rutas en esta entrega.

La Fase 4 queda presentada para revisión. No iniciar la Fase 5 sin aprobación del usuario.

# Autorización de SYSFOREST

## Alcance

La Fase 4 utiliza RBAC con permisos persistidos y asignaciones nacionales, departamentales y organizacionales. Un usuario es global y conserva su identificador BIGINT. Las nuevas entidades de autorización utilizan UUID; la relación de roles y permisos tiene clave primaria compuesta.

La autenticación establece quién realiza la petición y comprueba su sesión. La autorización decide qué puede hacer sobre un ámbito concreto. Un JWT válido, una membresía o una selección de organización no conceden permisos por sí solos.

## Roles y permisos

Los cuatro roles tienen ámbito fijo:

| Rol | Ámbito de asignación |
| --- | --- |
| SUPER_ADMIN | Nacional |
| DEPARTMENT_ADMIN | Un departamento explícito |
| ORGANIZATION_ADMIN | Una membresía de una organización |
| OFFICIAL | Una membresía de una organización |

La matriz vigente contiene 20 permisos: los 18 de Fase 4 y dos de catálogos incorporados en Fase 5. En la tabla, **N** significa concesión nacional, **D** el departamento asignado y **O** la organización de la membresía autorizada. El guion indica ausencia del permiso.

| Permiso | SUPER_ADMIN | DEPARTMENT_ADMIN | ORGANIZATION_ADMIN | OFFICIAL |
| --- | --- | --- | --- | --- |
| organizations.view | N | D | O | O |
| organizations.create | N | — | — | — |
| organizations.manage | N | — | O | — |
| memberships.view | N | — | O | — |
| memberships.create | N | — | O | — |
| memberships.update | N | — | O | — |
| memberships.activate | N | — | O | — |
| memberships.deactivate | N | — | O | — |
| users.view | N | — | O | — |
| users.manage | N | — | — | — |
| users.invite | N | — | O | — |
| territories.view | N | D | O | O |
| territories.manage | N | — | — | — |
| roles.assign-national | N | — | — | — |
| roles.assign-department | N | — | — | — |
| roles.assign-organization | N | — | O | — |
| sessions.view | N | — | — | — |
| sessions.revoke | N | — | — | — |
| catalogs.view | Consulta | Consulta | Consulta | Consulta |
| catalogs.manage | N | — | — | — |

`catalogs.view` consulta valores nacionales compartidos, pero exige una asignación vigente dentro de su ámbito: cuenta activa/verificada, rol y permiso; además departamento/país activos o membresía/organización activas según corresponda. No exige seleccionar tenant ni convierte el permiso de lectura local en escritura nacional. `catalogs.manage` se comprueba exclusivamente con `allowsNational`.

El perfil y las sesiones propias se protegen por identidad y propiedad del recurso; no requieren conceder administración global a todos los usuarios. El permiso organizacional users.view permite consultar la gestión de miembros de esa organización, no otras membresías ni sesiones de esas cuentas.

El catálogo se define en database/seeders/AuthorizationSeeder.php. Su ejecución es transaccional e idempotente: conserva las identidades y sincroniza las relaciones de permisos de los roles definidos. No crea cuentas ni membresías.

SUPER_ADMIN recibe los códigos enumerados en este catálogo; no existe un comodín ni una concesión automática sobre permisos que alguien agregue a la tabla. Tampoco se utiliza un Gate::before universal que omita las comprobaciones de contexto.

Para sembrar exclusivamente el catálogo en un entorno autorizado:

~~~bash
docker compose exec app php artisan db:seed --class=AuthorizationSeeder
~~~

Los permisos de inventarios, árboles, inspecciones y otros módulos forestales se incorporarán en sus fases correspondientes.

## Integridad relacional

La migración 2026_09_12_000003_create_authorization_tables.php crea:

| Tabla | Responsabilidad |
| --- | --- |
| roles | Nombre único y ámbito fijo del rol. |
| permissions | Código único y descripción de cada permiso. |
| role_permissions | Pares únicos de rol y permiso, con ambas FK. |
| national_role_assignments | Relación entre usuario global y rol nacional. |
| department_role_assignments | Relación entre usuario, departamento y rol departamental. |
| organization_role_assignments | Rol organizacional actual de una membresía. |

Las restricciones se aplican en PostgreSQL:

- roles tiene UNIQUE (id, scope) y un CHECK que vincula cada nombre definitivo con su ámbito.
- Cada tabla de asignaciones fija su scope con un CHECK y contiene una FK (role_id, scope) hacia roles (id, scope).
- Una asignación organizacional contiene la FK (organization_id, membership_id) hacia organization_memberships (organization_id, id). Una membresía de B no puede asociarse a una asignación de A.
- organization_role_assignments tiene UNIQUE (membership_id): existe una asignación actual por membresía. Cambiar de rol conserva el ID de esa asignación.
- Las asignaciones nacionales son únicas por (user_id, role_id); las departamentales por (user_id, department_id, role_id).
- assigned_at es obligatorio; revoked_at, cuando existe, debe ser posterior o igual a assigned_at. Una asignación activa no puede tener revoked_at.
- Las FK restringen el borrado de recursos referenciados. Las asignaciones disponen de índices para usuario, ámbito y estado según su tabla.

La asignación organizacional no duplica user_id: el propietario procede de la membresía. Las tablas de asignación representan el estado actual, no un historial completo de cambios de rol.

## Resolución de permisos

PermissionService consulta los repositorios y devuelve un booleano. Sus contratos son:

~~~php
allowsNational(User $actor, string $permission): bool;
allowsDepartment(User $actor, string $permission, string $departmentId): bool;
allowsOrganization(User $actor, string $permission, string $organizationId): bool;
hasOrganizationRole(User $actor, string $organizationId): bool;
allowsCatalogView(User $actor): bool;
~~~

Cada decisión consulta el estado actual de la cuenta: debe existir, estar activa y tener email_verified_at. Un objeto User cargado antes de una desactivación no conserva sus derechos.

Una concesión requiere una asignación activa sin revoked_at y una relación role_permissions que contenga el permiso solicitado. Las concesiones organizacionales requieren también membresía activa sin left_at y organización activa/no eliminada. Las consultas departamentales comprueban departamento y país activos.

allowsDepartment admite una concesión nacional explícita o la concesión del departamento exacto. allowsOrganization admite una concesión nacional explícita o una concesión organizacional válida; **no consulta asignaciones departamentales**. Por ello, un permiso departamental de lectura no se transforma en autorización para modificar recursos de las organizaciones de ese departamento.

Los UUID desconocidos o inválidos no constituyen autoridad. PermissionService comprueba el ámbito solicitado; las Policies y los Services añaden la coincidencia con el contexto activo y con el recurso concreto. No se debe sustituir una consulta limitada al tenant por una consulta global únicamente porque el actor posea el código de un permiso.

Ejemplo dentro de un Service:

~~~php
$organization = $tenants->assertCurrentAccess($actor);

if (!$permissions->allowsOrganization($actor, 'memberships.view', $organization->id)) {
    throw new AuthorizationException('No puedes consultar la gestión de miembros.');
}

return $memberships->findForTenant($organization->id, $membershipId);
~~~

El ámbito de la consulta se toma del contexto revalidado, no de un organization_id arbitrario del formulario.

## Contexto de organización

TenantContext conserva actorId, organizationId y accessMode. No contiene una lista permanente de permisos. TenantContextService activa y revalida los siguientes modos:

| Modo | Origen de la autorización | Uso |
| --- | --- | --- |
| organization | Membresía y rol organizacional vigentes | Lectura y operaciones expresamente permitidas en la organización. |
| national | Concesión nacional de lectura | Acceso nacional explícito, sin crear una membresía. |
| department_read | Concesión de lectura del departamento de la organización | Consulta de esa organización; las Policies rechazan escritura en este modo. |

activate(User, organizationId) limpia primero el estado anterior, valida la cuenta y la organización y determina un modo autorizado. Una membresía sin rol no habilita el espacio de trabajo.

assertCurrentAccess(User, bool lock = false) devuelve Organization y revalida la procedencia del contexto. currentOrganization(User) utiliza ese método.

assertCurrentMembership(User, bool lock = false) mantiene un contrato diferente: devuelve una OrganizationMembership real y exige el modo organization. No inventa una membresía para un administrador nacional ni devuelve null como sustituto.

El parámetro lock permite a los Services transaccionales bloquear la organización y, cuando corresponde, la membresía antes de modificar datos. Las Policies de organizaciones mantienen la coincidencia de actor y organización seleccionada, también en modo nacional. La Policy de membresías comprueba permiso específico y tenant; OFFICIAL no obtiene acceso administrativo por consultar su propia membresía.

run(User, organizationId, Closure) limpia el contexto con finally. Los middleware y jobs deben conservar la misma disciplina para evitar que una ejecución posterior herede contexto.

La selección guardada en el servidor expresa una preferencia. Cada petición debe volver a comprobar la cuenta, la asignación y la pertenencia cuando sea necesaria.

Un contexto operativo requiere organización activa y no eliminada. La administración nacional de una organización inactiva debe usar un flujo administrativo explícito autorizado con allowsNational, no fingir que existe un contexto operativo válido.

## Concesión y cambio de roles

Las operaciones iniciadas por un usuario pasan por RoleAssignmentService:

~~~php
assignNational(
    User $actor,
    User $target,
    string $roleName = 'SUPER_ADMIN',
): NationalRoleAssignment;

assignDepartment(
    User $actor,
    User $target,
    string $departmentId,
    string $roleName = 'DEPARTMENT_ADMIN',
): DepartmentRoleAssignment;

assignOrganization(
    User $actor,
    OrganizationMembership $target,
    string $roleName,
): OrganizationRoleAssignment;
~~~

assignNational exige roles.assign-national nacional. assignDepartment exige roles.assign-department nacional y un departamento existente. Ninguna concesión departamental u organizacional permite llamar estas operaciones con autoridad nacional.

assignOrganization revalida el contexto dentro de una transacción, rechaza department_read, exige roles.assign-organization y recupera la membresía mediante su organización e ID con bloqueo. La membresía debe estar activa y sin fecha de salida. El rol debe pertenecer al ámbito organization: solo ORGANIZATION_ADMIN u OFFICIAL pueden pasar la validación y las restricciones del catálogo.

Conceder un rol no crea implícitamente una cuenta ni una membresía. Desactivar una membresía elimina su acceso efectivo, aunque conserve su asignación para su eventual reactivación. Las otras membresías de la misma cuenta permanecen independientes. Desactivar la cuenta global invalida todos sus ámbitos.

## Repositorios y flujos internos

Los repositorios concentran las consultas y la persistencia. No constituyen una API pública de autorización.

RoleRepository:

- findByName(string): Role, con permissions precargados.
- forScope(string): Collection, con los roles y permisos del ámbito.
- upsert(string name, string scope): Role, destinado al catálogo.
- syncPermissions(Role, array permissionIds): void.

PermissionRepository:

- upsert(string code, string description): Permission.
- all(): Collection.

RoleAssignmentRepository:

- grantNational(int userId, string roleName = 'SUPER_ADMIN').
- grantDepartment(int userId, string departmentId, string roleName = 'DEPARTMENT_ADMIN').
- grantOrganization(OrganizationMembership, string roleName).
- organizationRole(OrganizationMembership): ?OrganizationRoleAssignment, con role precargado. También devuelve asignaciones inactivas para que el flujo llamador decida cómo tratar su estado.
- nationalForUser(int): Collection de asignaciones nacionales vigentes con role.
- departmentsForUser(int): Collection de asignaciones departamentales vigentes con department.country y role.
- Métodos hasNationalPermission, hasDepartmentPermission y hasOrganizationPermission para las comprobaciones centralizadas.
- Métodos activeOrganization y activeDepartment para resolución del ámbito.

Los métodos grant son mecanismos de persistencia para seeders y flujos que ya comprobaron la autorización, como la aceptación validada de una invitación. No deben exponerse directamente desde Controllers. Verifican el ámbito del rol, pero no reciben un actor al que autorizar.

En invitaciones a una cuenta existente, el flujo debe inspeccionar organizationRole antes de aplicar un cambio para no degradar un rol administrativo existente de forma accidental. La política de aceptación pertenece al Service de invitaciones.

## Comprobación

La suite focalizada se ejecuta sobre la base de pruebas PostgreSQL protegida por PostgresTestCase:

~~~bash
docker compose exec app php artisan test --filter=AuthorizationTest
~~~

AuthorizationTest verifica idempotencia, rechazo SQL de scopes incompatibles y membresías cruzadas, unicidad del rol por miembro, fechas coherentes, acceso nacional sin membresías artificiales, lectura departamental, permisos distintos entre organizaciones, revocación, cuenta desactivada/no verificada, IDOR y rechazo de escaladas de privilegio.

Resultado de la ejecución focalizada: **23 pruebas aprobadas, 58 aserciones**. Este resultado corresponde únicamente a AuthorizationTest y no expresa el total de la aplicación.

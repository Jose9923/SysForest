# Aislamiento multi-tenant de SYSFOREST

## Base aprobada y extensión actual

Las secciones de identidad conservan la base de Fase 4 y las operaciones forestales documentan la extensión de Fase 6. Al final se añade la lectura cartográfica de Fase 10, con un alcance explícito separado del contexto institucional y sin ampliar sus permisos de escritura.

SYSFOREST comparte una aplicación y una base PostgreSQL/PostGIS entre organizaciones. La organización conserva la propiedad de sus datos; la cuenta que los registra no reemplaza esa propiedad. La jerarquía es `Country → Department → Municipality → Organization`, y toda organización tiene un municipio obligatorio.

Las entidades territoriales, organizaciones, membresías y asignaciones de roles usan UUID. `users` conserva su identificador BIGINT y representa una identidad global. El tipo de organización describe a la institución y no concede permisos.

Fase 4 añade autenticación JWT, sesiones revocables y autorización mediante **cuatro roles y 18 permisos**. Consulta [autenticación](authentication.md), [matriz de autorización](authorization.md#roles-y-permisos), [recuperación e invitaciones](account-recovery.md) y [cuentas de desarrollo](development-accounts.md). Fase 5 añadió dos permisos de catálogo y Fase 6 incorporó ocho operativos, para 28 permisos al cierre de esa fase. Inspecciones, evidencias y mantenimientos completaron 43 permisos hasta Fase 9. Fase 10 reutiliza esa autorización y no añade permisos persistidos.

## Identidad, pertenencia, selección y permiso

La aplicación comprueba cuatro elementos diferentes:

- **Identidad:** el JWT identifica al actor y a una `auth_session` vigente de PostgreSQL. La cuenta debe seguir activa.
- **Pertenencia:** `organization_memberships` relaciona una cuenta con una organización; cada pertenencia es independiente.
- **Selección:** `active_organization_id` en la sesión de navegación de Redis expresa qué organización quiere utilizar el actor.
- **Permiso:** una concesión vigente, consultada en PostgreSQL, autoriza una acción en el ámbito exacto.

La navegación en Redis mantiene CSRF y selección; no reemplaza la autenticación JWT ni la sesión revocable de `auth_sessions`. `BindAuthenticatedSession` vincula esa navegación con el ID de sesión autenticada y borra la selección cuando cambia la sesión de identidad.

Una membresía válida exige `active = true`, `left_at IS NULL` y organización activa/no eliminada. Para activar el ámbito organizacional necesita además un rol organizacional vigente y el permiso de consulta. La sola pertenencia no habilita el espacio de trabajo ni la administración de miembros.

Los permisos también admiten concesiones nacionales y departamentales. **`SUPER_ADMIN` no necesita ni recibe membresías artificiales para acceder por su concesión nacional.** `DEPARTMENT_ADMIN` consulta exclusivamente el departamento asignado y no obtiene escritura institucional. `ORGANIZATION_ADMIN` y `OFFICIAL` operan mediante membresías reales con permisos distintos.

## Contrato de TenantContext

`App\Tenancy\TenantContext` está registrado como `scoped`. Guarda únicamente `actorId`, `organizationId` y `accessMode` para la ejecución actual. No almacena una instantánea de permisos y no persiste entre peticiones o trabajos.

| `accessMode` | Autoridad que lo habilita | Límite |
| --- | --- | --- |
| `organization` | Membresía y rol organizacional vigentes, con permiso `organizations.view`. | Acciones expresamente permitidas dentro de la organización seleccionada. |
| `national` | Concesión nacional explícita de `organizations.view`. | Cada acción requiere su permiso y coincidencia con la organización seleccionada. No crea membresías. |
| `department_read` | Concesión de lectura para el departamento de la organización. | Solo consulta; las escrituras institucionales se rechazan. |

| Método del contexto | Contrato |
| --- | --- |
| `activate(int $actorId, string $organizationId, string $accessMode = 'organization'): void` | Uso interno del servicio tras validar la autoridad. Rechaza modos desconocidos. |
| `actorId(): int` | Actor verificado; lanza `AuthorizationException` si no hay contexto. |
| `organizationId(): string` | Organización verificada; lanza excepción si no hay contexto. |
| `accessMode(): string` | Procedencia del acceso verificado; lanza excepción si no hay contexto. |
| `isActive(): bool` | Los tres valores están establecidos. |
| `clear(): void` | Elimina actor, organización y modo. |

Activar directamente este objeto no acredita permisos. Controllers y jobs deben utilizar `TenantContextService`.

| Método del servicio | Responsabilidad |
| --- | --- |
| `activate(User $actor, string $organizationId): Organization` | Limpia el estado anterior, consulta la cuenta vigente, valida el UUID y la organización y determina un modo permitido. |
| `assertCurrentAccess(User $actor, bool $lock = false): Organization` | Revalida actor, organización y origen de la concesión. En modo organizacional revalida también la membresía. |
| `assertCurrentMembership(User $actor, bool $lock = false): OrganizationMembership` | Exige modo `organization` y una membresía real con rol vigente. No admite sustitutos para administradores nacionales. |
| `currentOrganization(User $actor): Organization` | Delega en `assertCurrentAccess`; permite los tres modos válidos. |
| `run(User $actor, string $organizationId, Closure $callback): mixed` | Activa el ámbito, ejecuta el callback y limpia siempre mediante `finally`. |

La cuenta debe existir, estar activa y tener correo verificado. La organización debe seguir activa y no eliminada. Si la revalidación falla, el servicio limpia el contexto y deniega el acceso. `lock = true` permite a las operaciones transaccionales bloquear las filas de organización y membresía que correspondan.

Una cuenta con autoridad sobre A y B debe seleccionar B antes de usar una ruta contextual de B. La concesión nacional no desactiva esta coincidencia. Una modificación nacional de una organización inactiva utiliza el flujo administrativo específico y su permiso nacional; no intenta activar un contexto operativo inválido.

## Petición HTTP y navegación

Las rutas internas resuelven la identidad con `AuthenticateJwtCookie`: comprueban el token y consultan `SessionService` para validar la sesión autenticada, su vigencia, revocación, propietario y estado actual de la cuenta. `RequireVerifiedEmail` protege el área institucional.

`ShareTenantContext` revisa la selección guardada si existe. Activa el contexto para la navegación, comparte la organización y el modo con Blade y elimina selecciones que han dejado de estar autorizadas. Limpia el contexto y las variables compartidas en `finally`.

Las rutas de recursos de una organización incorporan además `ResolveTenantContext`. Obtiene el actor de `Request::user()`, lee la selección del servidor y llama al servicio. Limpia antes de resolver y al terminar, incluso ante excepciones.

El listado de organizaciones y el cambio de selección no necesitan un contexto previo. Los listados se limitan por concesión nacional, departamento asignado o membresías con rol, según el caso. El cambio de selección verifica la organización elegida antes de persistirla. Un UUID en una URL o un campo de formulario no concede autoridad.

La renovación JWT y la navegación se describen en [autenticación](authentication.md). Las credenciales JWT y refresh no se exponen a Blade ni se usan como parámetros de selección institucional.

## Autorización y consultas

El flujo obligatorio es `Controller → Service → Repository → Model`. Los Form Requests validan entrada y autorización HTTP; los Services repiten las comprobaciones necesarias para proteger también llamadas desde consola o jobs. Blade recibe resultados preparados.

`PermissionService` concentra las concesiones:

| Método | Resolución |
| --- | --- |
| `allowsNational(User, permission)` | Permiso explícito de una asignación nacional vigente. |
| `allowsDepartment(User, permission, departmentId)` | Concesión nacional explícita o concesión del departamento exacto. |
| `allowsOrganization(User, permission, organizationId)` | Concesión nacional explícita o membresía/rol de la organización exacta. No consulta asignaciones departamentales. |

No existe un comodín de `SUPER_ADMIN` ni un bypass universal de Policies. Su rol contiene los códigos enumerados en el catálogo, y las operaciones mantienen sus restricciones de contexto.

Las lecturas de `department_read` aplican el permiso departamental mediante su flujo explícito. Su filtro utiliza `organization → municipality → department`. No interpreta esa adscripción como una prueba geométrica de contención; el piloto no inventa polígonos oficiales.

Las comprobaciones del ámbito acompañan siempre a las del recurso:

- `OrganizationService::show` revalida el contexto, compara la organización solicitada y aplica la Policy.
- `OrganizationMembershipRepository::findForTenant` combina el ID de organización con el de membresía; un recurso ajeno produce 404.
- Los Services de membresías revalidan acceso y permisos específicos, utilizan transacciones para mutaciones y recuperan los registros desde el ámbito autorizado.
- La actualización de una membresía no reasigna su cuenta ni su organización. Los campos de identidad no se toman de un formulario para cambiar de propietario.
- `RoleAssignmentService` revalida el contexto y la membresía objetivo antes de conceder un rol organizacional; rechaza el modo departamental y los roles de otro scope.

No se aplica un global scope de tenant que cambie implícitamente todas las consultas. Los repositorios exponen operaciones por ámbito y los Services autorizan su uso. Una consulta general del repositorio no debe convertirse en un atajo desde un Controller.

## Revocación y propiedad

La autenticación reconsulta sesión y cuenta. La autorización reconsulta cuenta, asignación de rol y permiso. El contexto reconsulta organización y membresía cuando corresponde. Un objeto cargado antes de una revocación no conserva derechos.

Desactivar una membresía afecta únicamente a esa organización. Desactivar la cuenta global afecta a todos sus ámbitos. Revocar una asignación elimina sus permisos efectivos aunque la membresía siga activa. Desactivar o eliminar lógicamente una organización impide usarla como contexto operativo.

`OrganizationPolicy` exige coincidencia de actor y organización; sus mutaciones requieren el permiso específico y rechazan `department_read`. `OrganizationMembershipPolicy` aplica el permiso de la operación y la pertenencia del recurso al contexto. `OFFICIAL` no recibe administración de miembros por consultar su propio perfil.

Los listados del perfil pueden mostrar las pertenencias del propio usuario sin abrir la administración de otros miembros. La administración global de cuentas y sesiones exige permisos nacionales; la gestión organizacional no modifica el estado global de una cuenta.

## Integridad en PostgreSQL

Las restricciones complementan a la autorización de aplicación:

- `organizations.municipality_id` es obligatorio y referencia un municipio existente.
- `organization_memberships` referencia usuario y organización con eliminación restringida.
- `UNIQUE(user_id, organization_id)` conserva una relación por cuenta e institución.
- `UNIQUE(organization_id, id)` expone una identidad compuesta para las referencias tenant.
- Las fechas de membresía impiden que una relación activa tenga salida y que la salida preceda al ingreso.
- `roles` fija nombre y scope mediante CHECK. Cada tabla de asignación fija su scope y referencia `roles(id, scope)` mediante FK compuesta.
- `organization_role_assignments(organization_id, membership_id)` referencia `organization_memberships(organization_id, id)`; PostgreSQL rechaza vincular una membresía de B con una asignación de A.
- `UNIQUE(membership_id)` permite un rol organizacional actual por membresía. Las asignaciones nacionales y departamentales tienen unicidades por usuario, rol y ámbito.
- Las asignaciones activas no pueden tener `revoked_at`; una revocación no precede a `assigned_at`.

Las restricciones no otorgan lectura ni escritura: evitan asociaciones incompatibles aunque un flujo defectuoso intentara persistirlas. La matriz e interfaces completas se documentan en [autorización](authorization.md).

## Jobs y módulos posteriores

`App\Jobs\Middleware\UseTenantContext` recibe el ID de actor y el UUID de organización. Limpia el estado anterior, recupera la cuenta mediante `UserRepository`, reconstruye el contexto mediante `TenantContextService::run` y lo limpia al terminar, incluso ante fallos.

Los jobs futuros transportarán identificadores y volverán a consultar permisos al ejecutar. Un contexto válido no reemplaza el permiso de la operación del job. No se serializa una concesión previa como autoridad permanente, y el ciclo `scoped` no reemplaza el `finally`.

Cada recurso operativo futuro incluirá `organization_id NOT NULL`. Los padres expondrán `UNIQUE(organization_id, id)`; las referencias a otro recurso del mismo tenant usarán ambas columnas.

Patrón implementado en Fase 6 para inventory_trees y ambos historiales:

~~~sql
UNIQUE (organization_id, id)

FOREIGN KEY (organization_id, tree_id)
    REFERENCES trees (organization_id, id)
~~~

La FK compuesta exige que padre e hijo pertenezcan a la misma organización. Las lecturas seguirán necesitando contexto revalidado, permisos y consultas restringidas.

## Verificación

Las pruebas cubren falta de contexto, cambio de actor, selección manipulada, lectura o modificación de B desde A, relaciones cruzadas, revocación, organización inactiva, limpieza al fallar jobs y cambio explícito de organización.

La cobertura de autorización añade ámbito nacional sin membresías artificiales, lectura departamental limitada, rol organizacional por pertenencia, rechazo de escaladas, restricciones SQL compuestas y diferencia entre permiso global y contextual. Las pruebas HTTP utilizan autenticación real de la fase y verifican que una sesión de navegación por sí sola no habilite el área protegida.

Las suites automatizadas se retiraron por solicitud del usuario. Los resultados descritos aquí son históricos; consulta [la verificación vigente](testing.md) para formato, sintaxis y revisión manual.

`TerritorialFoundationTest` verificó integridad territorial y membresías; `TerritorySpatialTest` verificó PostGIS. Los fixtures espaciales artificiales se limitaron a pruebas. `PostgresTestCase` protegió la base separada `sysforest_testing` y revirtió sus transacciones durante esas ejecuciones históricas.

## Operaciones forestales de Fase 6

Inventory y Tree incluyen organization_id obligatorio. Inventory ↔ Tree utiliza inventory_trees; el individuo no tiene inventory_id. Los padres exponen UNIQUE(organization_id,id) y la asociación referencia ambos padres con claves compuestas. Historiales de estado y ubicación también referencian (organization_id,tree_id). PostgreSQL rechaza una combinación Inventory B / Tree A aunque un cliente conozca ambos UUID.

| Permiso | SUPER_ADMIN | DEPARTMENT_ADMIN | ORGANIZATION_ADMIN | OFFICIAL |
| --- | --- | --- | --- | --- |
| inventories.view | Sí, tenant seleccionado | Consulta del departamento | Su organización | Su organización |
| inventories.create / update | Sí, tenant seleccionado | No | Su organización | No |
| trees.view | Sí, tenant seleccionado | Consulta del departamento | Su organización | Su organización |
| trees.create / update | Sí, tenant seleccionado | No | Su organización | Campañas activas de su organización |
| trees.void / restore | Sí, tenant seleccionado | No | Su organización | No |

ForestryAccessService revalida TenantContextService y los permisos persistidos. InventoryPolicy y TreePolicy verifican actor, tenant, operación y estado del recurso. No hay bypass nacional: SUPER_ADMIN selecciona la organización y usa su concesión global explícita. Un actor departamental solo consulta organizaciones activas del departamento autorizado; no cambia identidad, estado ni ubicación. Los servicios vuelven a comprobar el contexto al ejecutar.

Las rutas reciben UUID como cadenas con whereUuid y nunca usan binding global de Tree o Inventory. El Repository resuelve WHERE organization_id = tenant AND id = recurso antes de Policy y devuelve 404 para UUID ajenos. Los Form Requests de mutación Tree llaman la resolución autorizada antes de validar el cuerpo; datos inventados o UUID conocidos no revelan el recurso. En formularios, un inventario ajeno devuelve un error genérico de disponibilidad institucional. La base protege además la asociación.

OFFICIAL puede editar el árbol creado por otro funcionario de la misma organización si participa en al menos una campaña activa. created_by permanece inmutable y updated_by identifica al último editor. Los permisos no dependen de la autoría. Solo se añaden nuevos vínculos a campañas activas; no se borran campañas históricas al editar la ficha. Los filtros sí permiten consultar campañas cerradas.

Las escrituras bloquean actor, organización/membresía y recurso en transacción. TreeWriteService compara lock_version y devuelve 409 con el mensaje solicitado antes de modificar una versión obsoleta. Cada operación confirmada incrementa la versión; creación, código, pertenencias y estados/ubicación iniciales comparten commit. La secuencia por organización usa UPSERT ... RETURNING, con bloqueo de fila PostgreSQL, sin MAX+1. Las reservas de operaciones fallidas se revierten; los códigos de individuos confirmados o anulados nunca se reciclan.

Tree SoftDeletes significa registro erróneo, con autor/motivo/nota; dead, felled y removed no modifican deleted_at. Restaurar conserva UUID/código/autor y vínculos, registra restored_by/restored_at y auditoría. Los triggers protegen identidad e historiales; el rol de aplicación no elimina físicamente árboles ni reescribe el historial.

No hay consultas en Blade ni cálculo espacial de distancias en JavaScript. La ubicación se valida en servicios y persiste mediante PostGIS y bindings. Cada consulta bbox/radio/proximidad lleva organization_id y el filtro de campaña utiliza la asociación institucional.

Consulta el [README](../README.md) para la operación local y el [reporte de Fase 4](phase-4.md) para el cierre y los resultados consolidados. Los comandos anteriores son procedimientos; no expresan un total de pruebas ejecutadas.

## Lectura cartográfica explícita de Fase 10

El mapa introduce un alcance de consulta independiente de `TenantContext`. Incluso su modo nacional sigue representando una sola organización seleccionada; no se convierte en un contexto sin propietario ni permite saltar las Policies de las fichas.

`App\DTOs\TreeReadScope` es un DTO readonly con `kind`, `organizationId` y `departmentIds`. Sus fábricas son `organization(UUID)`, `department(UUID[])` y `national()`. El conjunto departamental no puede estar vacío. No contiene actor, no se guarda como concesión en sesión y no acredita permisos por sí mismo. Los wrappers institucionales construyen únicamente `organization(orgId)` después de su autorización previa; un valor nulo nunca significa consulta nacional.

| Operación | Contrato |
| --- | --- |
| `MapAccessService::resolve(User, ?requestedKind): TreeReadScope` | Reconsulta cuenta y concesiones actuales; exige `organizations.view` y `trees.view` en el ámbito correspondiente. |
| `MapAccessService::allows / authorize` | Comprueban `trees.view`, `inspections.view` o `maintenances.view` en todo el alcance; no aceptan permisos de escritura. |
| `MapAccessService::canView(User): bool` | Prepara navegación autorizada; solo transforma `AuthorizationException` en `false`. Los errores de infraestructura se propagan. |
| `MapScopeRepository::organizations(scope): Builder` | Restringe organizaciones no anuladas y activas con municipio, departamento y país activos. |
| `MapScopeRepository::applyTo(query, scope, alias): void` | Compone `EXISTS` por `organization_id` antes de filtros; no duplica filas. |
| `MapScopeRepository::findTree(scope, UUID): Tree` | Recupera un árbol vigente del alcance; UUID ajeno o anulado produce 404. |

`ORGANIZATION_ADMIN` y `OFFICIAL` usan su organización seleccionada con membresía y rol vigentes. `DEPARTMENT_ADMIN` consulta sus departamentos autorizados sin seleccionar una institución, pero no obtiene escritura. `SUPER_ADMIN` consulta el ámbito nacional permitido sin membresías artificiales ni selección previa. El código comprueba permisos, no nombres de rol.

Sin `scope` explícito se elige primero el nacional autorizado, después el departamental y finalmente la organización seleccionada. Solicitar `organization`, `department` o `national` solo expresa una selección; un ámbito no autorizado se rechaza. Departamento, municipio, organización, campaña y demás criterios del navegador siempre estrechan el alcance.

Los filtros y opciones de inspecciones requieren `inspections.view`; los de mantenimientos, `maintenances.view`. El resumen de última visita se omite si falta su permiso. Las respuestas usan Resources con campos explícitos, sin observaciones, metadatos GPS, autores ni rutas de evidencias.

### Navegación desde el mapa y revocación

Las rutas cartográficas están dentro de JWT, vinculación de sesión, verificación de correo y `ShareTenantContext`, pero fuera de `ResolveTenantContext` obligatorio. Consultar capas nacionales/departamentales no cambia `active_organization_id`.

La apertura utiliza `POST /map/trees/{tree}/open` con CSRF. El `open_url` conserva el tipo de `scope` elegido para no sustituirlo por otro ámbito de una cuenta multirrol. `MapTreeNavigationService` valida esa selección y resuelve otra vez permisos; no acepta un DTO de autoridad del navegador. Obtiene el propietario desde el árbol autorizado, llama a `TenantContextService::activate` y aplica `TreePolicy::view`. Solo entonces el Controller guarda la selección y redirige a `trees.show`. `scope=national` no convierte una membresía en concesión nacional; organización y URL de retorno manipuladas no deciden el destino.

La revalidación mantiene cuenta activa/verificada, membresía cuando corresponde, concesiones y organización vigentes. Una revocación invalida solicitudes posteriores aunque exista un scope anterior. `MapAccessService` revalida el contexto una vez por operación organizacional y consulta permisos específicos actuales; evita llamadas recursivas repetidas sin cachear permisos ni modificar los servicios globales.

Los middleware conservan su limpieza `finally`. Este alcance de lectura no se serializa como autorización para jobs ni reemplaza `UseTenantContext` en escrituras futuras.

### Restricciones y pruebas del mapa

El mapa excluye árboles anulados incluso para administradores; la consulta histórica de las fichas conserva sus políticas. `dead`, `felled` y `removed` siguen separados de `deleted_at`.

No se añadieron roles, permisos persistidos ni membresías para el mapa. `maps.view` es una capacidad de Gate respaldada por `MapAccessService`; Fase 10 mantiene los 43 permisos acumulados hasta Fase 9. Las garantías de FK compuesta, identidad inmutable, versiones y auditoría siguen vigentes.

`MapScopeSecurityTest` y `MapEndpointSecurityTest` cubren A/B, departamentos ajenos, cuentas multirrol, nacional sin selección, revocación, referencias manipuladas, permisos separados, anonimato, CSRF, proyección mínima y límites HTTP. Los fixtures pertenecen exclusivamente a `sysforest_testing` y las suites se ejecutan en serie.

Consulte [maps.md](maps.md) para API, proyecciones, límites, geometrías territoriales y mediciones; [map-filters.md](map-filters.md) para el motor compartido y la última inspección. Esta extensión conserva los límites históricos de fases anteriores y no autoriza el portal público.

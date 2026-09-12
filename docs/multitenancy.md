# Aislamiento multi-tenant de SYSFOREST

## Alcance

SYSFOREST utiliza una aplicación y una base PostgreSQL/PostGIS compartidas. La organización es propietaria de sus datos; la cuenta que los registra no sustituye esa propiedad. La jerarquía territorial es `Country → Department → Municipality → Organization`. Toda organización tiene un municipio obligatorio, incluido un organismo departamental o nacional.

Esta documentación describe el fundamento implementado en Fase 3. Las entidades territoriales, organizaciones y membresías tienen UUID. Se conserva el identificador BIGINT de `users` para mantener las cuentas existentes. El tipo de organización describe una institución; no concede permisos.

## Membresía, selección y autorización

Son comprobaciones distintas:

- **Membresía:** relaciona una cuenta con una organización. Una cuenta puede tener varias pertenencias independientes.
- **Selección activa:** indica en cuál de sus organizaciones desea operar el usuario durante la petición.
- **Autorización:** determina si puede realizar la acción solicitada sobre el recurso de esa organización.

El valor `active_organization_id` de la sesión representa una selección. Se valida nuevamente en PostgreSQL antes de convertirlo en contexto confiable. Cambiar un UUID en una URL, un formulario o la sesión no acredita pertenencia.

Una membresía habilitada requiere `active = true`, `left_at IS NULL` y una organización activa que no esté anulada mediante soft delete. Desactivar una membresía no modifica la cuenta ni sus pertenencias a otras organizaciones.

La pertenencia por sí sola permite consultar la organización seleccionada; no concede edición institucional ni administración de membresías. Un usuario con membresías A y B debe cambiar explícitamente la selección antes de operar en B.

## Contrato de TenantContext

`App\Tenancy\TenantContext` está registrado mediante un binding `scoped` en `AppServiceProvider`. Contiene únicamente el identificador del actor y el UUID de la organización verificados para la ejecución actual. No es una variable estática y no persiste el tenant entre peticiones o trabajos.

| Método | Contrato |
| --- | --- |
| `activate(int $actorId, string $organizationId): void` | Método interno: solo debe llamarlo el servicio después de comprobar la membresía. |
| `actorId(): int` | Devuelve el actor verificado; sin contexto lanza `AuthorizationException`. |
| `organizationId(): string` | Devuelve el tenant verificado; sin contexto lanza `AuthorizationException`. |
| `isActive(): bool` | Indica si ambos identificadores están establecidos. |
| `clear(): void` | Elimina ambos identificadores. |

El contexto no realiza consultas ni decide permisos. El método interno de activación no es una API para datos recibidos del cliente.

`TenantContextService` resuelve y revalida la pertenencia:

| Método | Responsabilidad |
| --- | --- |
| `activate(User $actor, string $organizationId): Organization` | Limpia el contexto anterior, valida el UUID y consulta `activeFor`; solo entonces activa el contexto. |
| `assertCurrentMembership(User $actor, bool $lock = false): OrganizationMembership` | Comprueba que el actor coincida y reconsulta su pertenencia; si fue revocada, limpia el contexto y deniega la operación. |
| `currentOrganization(User $actor): Organization` | Devuelve la organización tras revalidar la membresía. |
| `run(User $actor, string $organizationId, Closure $callback): mixed` | Activa el contexto, ejecuta el callback y limpia siempre mediante `finally`. |

## Ciclo HTTP y trabajos de cola

Las rutas institucionales pasan primero por autenticación y por `LoadLocalAccessGrant`. Las rutas que operan sobre una organización o una membresía concreta incorporan además `ResolveTenantContext`.

`ResolveTenantContext` obtiene al actor de `Request::user()`, toma la selección de la sesión y llama al servicio de contexto. Limpia el estado antes de resolverlo y en un bloque `finally`, tanto si la petición finaliza correctamente como si lanza una excepción. `LoadLocalAccessGrant` también limpia los contextos tenant y de acceso técnico al terminar.

El listado de organizaciones propias y el cambio de selección no requieren tener previamente un tenant activo. El listado consulta solo las organizaciones activas con pertenencia activa del actor; el cambio de selección verifica la nueva pertenencia antes de guardarla en sesión.

Para jobs, `App\Jobs\Middleware\UseTenantContext` recibe el ID del actor y el UUID de la organización. Al ejecutar:

1. Limpia cualquier contexto previo.
2. Recupera la cuenta mediante `UserRepository`.
3. Revalida la pertenencia mediante `TenantContextService::run`.
4. Ejecuta el trabajo con ese contexto.
5. Limpia en `finally`, incluso si la cuenta no existe o el trabajo falla.

Los jobs futuros deben transportar identificadores y reconstruir el contexto al ejecutar; no serializar un contexto previamente autorizado. La limpieza scoped del worker complementa los bloques `finally`; no los reemplaza.

## Capas y consultas explícitas

El flujo es `Controller → Service → Repository → Model`. Los Form Requests validan entrada y autorización HTTP. Los Services repiten las comprobaciones relevantes para proteger también las llamadas fuera de HTTP. Blade recibe datos preparados y no consulta la base.

- `OrganizationService::show` revalida la pertenencia, exige que el ID solicitado coincida con el tenant y aplica la Policy.
- `OrganizationMembershipRepository::findForTenant` combina `organization_id` e ID de membresía en la misma consulta. Un recurso ajeno produce `ModelNotFoundException`, representada como HTTP 404.
- `OrganizationMembershipService` revalida actor y pertenencia antes de leer o modificar. Las mutaciones usan transacciones, bloquean las filas pertinentes y aplican la Policy.
- El repositorio de membresías limita las actualizaciones a `active`, `joined_at` y `left_at`. No permite cambiar la cuenta ni la organización de una membresía existente.
- Los identificadores tenant de las mutaciones se derivan del contexto. Los Requests prohíben campos que intenten reasignar organización, cuenta o identidad del registro.

No existe un global scope de tenant que altere implícitamente todas las consultas. Los repositorios contienen métodos explícitos para cada ámbito, y los Services autorizan su utilización. Un método general de repositorio no debe exponerse directamente desde un Controller como atajo a las comprobaciones del servicio.

### Lecturas administrativas

`OrganizationService::nationalRead` exige el Gate `organizations.view-national`. `departmentRead` exige `organizations.view-department` para el departamento solicitado y utiliza una consulta restringida a los municipios de ese departamento.

Ambos Gates están denegados por defecto en la aplicación. Las pruebas conceden permisos concretos para verificar sus límites. Una concesión de lectura administrativa no altera el contexto tenant ni autoriza escrituras en otra organización.

El filtro departamental utiliza la adscripción administrativa `organization → municipality → department`. No afirma que una geometría esté contenida en un límite territorial. No se han inventado polígonos oficiales.

## Policies y revocación

`OrganizationPolicy::view` comprueba actor y organización del contexto. `update` exige además `organizations.manage`; `create` exige `organizations.create`.

`OrganizationMembershipPolicy` exige siempre coincidencia con el contexto. La consulta de una membresía ajena a la propia cuenta requiere `memberships.manage`, y crear o actualizar membresías también requiere esa capacidad.

Las Policies consumen un contexto previamente verificado; los Services son responsables de reconsultar la membresía. Así, una revocación posterior a la activación del contexto se detecta antes de la operación siguiente. Las escrituras solicitan bloqueo de la membresía del actor dentro de su transacción y, cuando corresponde, de la membresía que modifican.

La anulación o desactivación de una organización también impide activarla como contexto. No se infieren permisos a partir de quién creó un registro ni del tipo institucional.

## Integridad en PostgreSQL

Las restricciones actuales incluyen:

- FK obligatoria `organizations.municipality_id → municipalities.id`.
- FK `organization_memberships.user_id → users.id` y FK `organization_id → organizations.id`, con eliminación restringida.
- `UNIQUE(user_id, organization_id)`: una sola relación por cuenta y organización; reactivar no crea otra membresía.
- `UNIQUE(organization_id, id)`, llamada `memberships_tenant_id_unique`: prepara referencias compuestas a una membresía.
- `memberships_dates_check`: una membresía activa no puede tener fecha de salida, y una salida no puede preceder al ingreso cuando ambas fechas están presentes.

### Patrón para recursos operativos futuros

Cada recurso operativo deberá incluir `organization_id NOT NULL`. Su tabla padre expondrá una clave única `(organization_id, id)`, y el hijo referenciará ambos valores juntos.

Ejemplo conceptual, todavía sin tablas forestales implementadas:

```sql
-- Tabla padre:
UNIQUE (organization_id, id)

-- Tabla hija:
FOREIGN KEY (organization_id, tree_id)
    REFERENCES trees (organization_id, id)
```

Una FK aislada sobre `tree_id` únicamente prueba que el árbol existe. La FK compuesta también exige que pertenezca a la organización del hijo. Las constraints protegen asociaciones; la lectura autorizada sigue dependiendo del contexto, los Services, las Policies y las consultas restringidas.

## Acceso técnico local temporal

La revisión de Fase 3 dispone de un acceso técnico explícito mediante `sysforest:local-access`. Su emisión está limitada a Artisan y al entorno `local`. Se requiere una cuenta existente con membresía activa; crear una cuenta o vincularla exige los flags explícitos `--create-user` y `--join`.

El enlace está firmado, caduca a los 10 minutos y contiene un token aleatorio de un solo uso. La caché lo identifica mediante hash; el consumo está protegido por un bloqueo y vuelve a verificar la membresía. La ruta también valida firma y aplica limitación de solicitudes.

Al consumirlo se invalida la sesión anterior, se establece la identidad del actor y se regenera el token CSRF. La capacidad técnica dura 30 minutos. `LocalAccessContext` es scoped y exige coincidencia de actor, entorno local y vigencia. Al caducar, el middleware cierra la sesión e invalida su contenido.

La capacidad permite crear organizaciones y administrar únicamente recursos del tenant activo autorizado. No concede lecturas nacionales ni departamentales. No sustituye la pertenencia y no introduce roles definitivos. Una cuenta con varias membresías conserva la obligación de seleccionar y validar cada organización.

No incluir enlaces técnicos emitidos, cookies o contenidos de sesión en documentación, capturas compartidas o registros de prueba.

## Amenazas cubiertas por pruebas

Las pruebas actuales verifican:

- Acceso sin contexto y ausencia de estado compartido entre instancias.
- Selección de un tenant sin membresía, incluida una sesión manipulada.
- Lectura o actualización por HTTP de organización B desde contexto A.
- Lectura y desactivación de membresía B desde contexto A, comprobando que B permanezca intacta incluso si A tiene capacidad administrativa.
- Rechazo de `organization_id` inyectado en una actualización.
- Revalidación después de revocar membresías y rechazo de organizaciones inactivas o anuladas.
- Rechazo de un actor distinto al verificado en el contexto.
- Limpieza del contexto cuando un job falla o su actor no existe.
- Cambio explícito de tenant para una misma cuenta con varias membresías.
- Ausencia de permisos administrativos por la sola pertenencia.
- Denegación administrativa por defecto, límite departamental y separación entre lectura nacional y escritura tenant.
- Enlace local de un solo uso, firma manipulada, membresía revocada, caducidad de la capacidad y prohibición de emitir acceso fuera del entorno local.
- Creación de cuentas y membresías únicamente cuando los flags de consola lo solicitan.

Las pruebas de integración de Fase 3 utilizan PostgreSQL/PostGIS real. Las geometrías artificiales pertenecen exclusivamente a pruebas. Las pruebas de contexto no reemplazan las comprobaciones HTTP ni las restricciones de base de datos.

Desde el proyecto definitivo se pueden ejecutar por separado:

```bash
docker compose exec app php artisan test --filter=TenantContextTest
docker compose exec app php artisan test --filter=TenantIsolationTest
docker compose exec app php artisan test --filter=LocalAccessTest
```

Consultar `tests/Feature/TerritorialFoundationTest.php` para las restricciones territoriales y de membresías, y `tests/Feature/TerritorySpatialTest.php` para geometrías y consultas PostGIS. El alcance y los comandos generales de la fase se describen en `docs/phase-3.md` y `README.md`.

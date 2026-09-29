<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\DepartmentRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\UserRepository;
use App\Tenancy\TenantContext;
use App\Validation\OrganizationRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public function __construct(
        private OrganizationRepository $organizations,
        private DepartmentRepository $departments,
        private TenantContext $context,
        private TenantContextService $tenants,
        private PermissionService $permissions,
        private RoleAssignmentRepository $assignments,
        private UserRepository $users,
        private VersionedWriteService $versions,
        private AuditSnapshotService $snapshots,
        private \App\Repositories\SecurityEventRepository $events,
    ) {
    }

    public function forActor(User $actor): LengthAwarePaginator
    {
        $this->requireEligible($actor);
        if ($this->permissions->allowsNational($actor, 'organizations.view')) {
            return $this->organizations->paginateNational();
        }
        $departments = $this->assignments->departmentsForUser((int) $actor->id)
            ->filter(fn ($assignment): bool => $this->permissions->allowsDepartment($actor, 'organizations.view', $assignment->department_id))
            ->pluck('department_id')->unique()->values()->all();

        return $this->organizations->paginateForActor((int) $actor->id, $departments);
    }

    public function show(User $actor, string $id): Organization
    {
        $this->tenants->assertCurrentAccess($actor);
        if ($id !== $this->context->organizationId()) {
            throw (new ModelNotFoundException())->setModel(Organization::class);
        }
        $organization = $this->organizations->find($id);
        Gate::forUser($actor)->authorize('view', $organization);

        return $organization;
    }

    public function create(User $actor, array $input): Organization
    {
        $this->authorizeNational($actor, 'organizations.create');
        Gate::forUser($actor)->authorize('create', Organization::class);
        $attributes = Validator::make($input, OrganizationRules::rules(), OrganizationRules::messages())->validate();

        try {
            return DB::transaction(function () use ($actor, $attributes): Organization {
                $current = $this->users->findForUpdate((int) $actor->id);
                $this->authorizeNational($current, 'organizations.create');

                // El administrador nacional opera por su alcance; no se crea una membresía artificial.
                $organization = $this->organizations->create($attributes);
                $this->events->recordChange(
                    'organization.created',
                    $current,
                    $organization->id,
                    'organization',
                    $organization->id,
                    [],
                    $this->snapshots->snapshot($organization),
                    ['resource_id' => $organization->id, 'resource_code' => $organization->code]
                );

                return $organization;
            }, 3);
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function update(User $actor, string $id, array $input): Organization
    {
        try {
            return DB::transaction(function () use ($actor, $id, $input): Organization {
                $this->users->findForUpdate((int) $actor->id);
                $this->tenants->assertCurrentAccess($actor, true);
                $organization = $this->show($actor, $id);
                Gate::forUser($actor)->authorize('update', $organization);
                $before = $this->snapshots->snapshot($organization);
                $attributes = Validator::make($input, OrganizationRules::rules($id), OrganizationRules::messages())->validate();
                $this->versions->advance($organization, $attributes);
                unset($attributes['lock_version']);

                $organization = $this->organizations->update($organization, $attributes);
                $this->events->recordChange(
                    'organization.updated',
                    $actor,
                    $organization->id,
                    'organization',
                    $organization->id,
                    $before,
                    $this->snapshots->snapshot($organization),
                    ['resource_id' => $organization->id, 'resource_code' => $organization->code]
                );

                return $organization;
            }, 3);
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    /** Edición nacional explícita: permite gestionar entidades inactivas sin seleccionarlas como tenant. */
    public function administrativeShow(User $actor, string $id): Organization
    {
        $this->authorizeNational($actor, 'organizations.manage');

        return $this->organizations->find($id);
    }

    public function administrativeUpdate(User $actor, string $id, array $input): Organization
    {
        $this->authorizeNational($actor, 'organizations.manage');
        try {
            return DB::transaction(function () use ($actor, $id, $input): Organization {
                $current = $this->users->findForUpdate((int) $actor->id);
                $this->authorizeNational($current, 'organizations.manage');
                $organization = $this->organizations->find($id, true);
                $before = $this->snapshots->snapshot($organization);
                $attributes = Validator::make($input, OrganizationRules::rules($id), OrganizationRules::messages())->validate();
                $this->versions->advance($organization, $attributes);
                unset($attributes['lock_version']);

                $organization = $this->organizations->update($organization, $attributes);
                $this->events->recordChange(
                    'organization.updated',
                    $current,
                    $organization->id,
                    'organization',
                    $organization->id,
                    $before,
                    $this->snapshots->snapshot($organization),
                    ['resource_id' => $organization->id, 'resource_code' => $organization->code]
                );

                return $organization;
            }, 3);
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function nationalRead(User $actor): LengthAwarePaginator
    {
        $this->authorizeNational($actor, 'organizations.view');

        return $this->organizations->paginateNational();
    }

    public function departmentRead(User $actor, string $departmentId): LengthAwarePaginator
    {
        if (!$this->permissions->allowsDepartment($actor, 'organizations.view', $departmentId)) {
            throw new AuthorizationException('No puedes consultar organizaciones de este departamento.');
        }
        $this->departments->find($departmentId);

        return $this->organizations->paginateForDepartment($departmentId);
    }

    private function requireEligible(User $actor): void
    {
        $current = $this->users->find((int) $actor->id);
        if (!$current->active || $current->email_verified_at === null) {
            throw new AuthorizationException('La cuenta debe estar activa y tener el correo verificado.');
        }
    }

    private function authorizeNational(User $actor, string $permission): void
    {
        if (!$this->permissions->allowsNational($actor, $permission)) {
            throw new AuthorizationException('Esta operación requiere autorización nacional.');
        }
    }

    private function translateUniqueConflict(QueryException $exception): never
    {
        if ($exception->getCode() === '23505') {
            throw ValidationException::withMessages(['code' => 'Otro proceso reservó el código o prefijo. Utiliza uno diferente.']);
        }

        throw $exception;
    }
}

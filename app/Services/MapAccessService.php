<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\TreeReadScope;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

class MapAccessService
{
    private const READ_PERMISSIONS = ['trees.view', 'inspections.view', 'maintenances.view'];

    public function __construct(
        private PermissionService $permissions,
        private RoleAssignmentRepository $assignments,
        private TenantContextService $tenants,
        private TenantContext $context,
    ) {
    }

    /** Cada solicitud obtiene concesiones actuales; el mapa amplio no activa un tenant. */
    public function resolve(User $actor, ?string $requestedKind = null): TreeReadScope
    {
        if ($requestedKind !== null && ! in_array($requestedKind, ['organization', 'department', 'national'], true)) {
            throw $this->denied();
        }

        if ($requestedKind === 'organization') {
            return $this->organization($actor);
        }

        if (($requestedKind === null || $requestedKind === 'national') && $this->national($actor, 'trees.view')) {
            return TreeReadScope::national();
        }

        if ($requestedKind === 'national') {
            throw $this->denied();
        }

        $departmentIds = [];
        foreach ($this->assignments->departmentsForUser((int) $actor->id) as $assignment) {
            $id = (string) $assignment->department_id;
            if ($this->department($actor, $id, 'trees.view')) {
                $departmentIds[] = $id;
            }
        }

        if ($departmentIds !== []) {
            return TreeReadScope::department($departmentIds);
        }

        if ($requestedKind === 'department') {
            throw $this->denied();
        }

        return $this->organization($actor);
    }

    public function canView(User $actor): bool
    {
        try {
            $this->resolve($actor);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Un DTO estructural recibido internamente tampoco conserva permisos revocados. */
    public function allows(User $actor, TreeReadScope $scope, string $permission): bool
    {
        if (! in_array($permission, self::READ_PERMISSIONS, true)) {
            return false;
        }

        if ($scope->kind === 'national') {
            return $this->national($actor, $permission);
        }

        if ($scope->kind === 'department') {
            foreach ($scope->departmentIds as $departmentId) {
                if (! $this->department($actor, $departmentId, $permission)) {
                    return false;
                }
            }

            return $scope->departmentIds !== [];
        }

        if ($scope->kind !== 'organization') {
            return false;
        }

        try {
            $organization = $this->tenants->assertCurrentAccess($actor);

            return $organization->id === $scope->organizationId
                && $this->organizationPermission($actor, $organization, 'trees.view')
                && ($permission === 'trees.view' || $this->organizationPermission($actor, $organization, $permission));
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function authorize(User $actor, TreeReadScope $scope, string $permission): void
    {
        if (! $this->allows($actor, $scope, $permission)) {
            throw $this->denied();
        }
    }

    private function organization(User $actor): TreeReadScope
    {
        $organization = $this->tenants->assertCurrentAccess($actor);
        if (! $this->organizationPermission($actor, $organization, 'trees.view')) {
            throw $this->denied();
        }

        return TreeReadScope::organization($organization->id);
    }

    /** Usa el contexto recién revalidado; PermissionService vuelve a leer el permiso actual, sin caché. */
    private function organizationPermission(User $actor, Organization $organization, string $permission): bool
    {
        if ($this->context->accessMode() === 'department_read') {
            return $this->permissions->allowsDepartment($actor, $permission, $organization->municipality->department_id);
        }

        return $this->permissions->allowsOrganization($actor, $permission, $organization->id);
    }

    private function national(User $actor, string $permission): bool
    {
        return $this->permissions->allowsNational($actor, 'organizations.view')
            && $this->permissions->allowsNational($actor, 'trees.view')
            && ($permission === 'trees.view' || $this->permissions->allowsNational($actor, $permission));
    }

    private function department(User $actor, string $departmentId, string $permission): bool
    {
        return $this->permissions->allowsDepartment($actor, 'organizations.view', $departmentId)
            && $this->permissions->allowsDepartment($actor, 'trees.view', $departmentId)
            && ($permission === 'trees.view' || $this->permissions->allowsDepartment($actor, $permission, $departmentId));
    }

    private function denied(): AuthorizationException
    {
        return new AuthorizationException('No tiene autorización para consultar el mapa en el alcance solicitado.');
    }
}

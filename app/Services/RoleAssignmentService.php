<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DepartmentRoleAssignment;
use App\Models\NationalRoleAssignment;
use App\Models\OrganizationMembership;
use App\Models\OrganizationRoleAssignment;
use App\Models\User;
use App\Repositories\DepartmentRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\UserRepository;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleAssignmentService
{
    public function __construct(
        private PermissionService $permissions,
        private RoleAssignmentRepository $assignments,
        private OrganizationMembershipRepository $memberships,
        private UserRepository $users,
        private DepartmentRepository $departments,
        private TenantContext $context,
        private TenantContextService $tenants,
    ) {
    }

    public function assignNational(User $actor, User $target, string $roleName = 'SUPER_ADMIN'): NationalRoleAssignment
    {
        return DB::transaction(function () use ($actor, $target, $roleName): NationalRoleAssignment {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $this->authorizeNational($actor, 'roles.assign-national');
            $this->users->find((int) $target->id);

            return $this->assignments->grantNational((int) $target->id, $roleName);
        });
    }

    public function assignDepartment(
        User $actor,
        User $target,
        string $departmentId,
        string $roleName = 'DEPARTMENT_ADMIN',
    ): DepartmentRoleAssignment {
        return DB::transaction(function () use ($actor, $target, $departmentId, $roleName): DepartmentRoleAssignment {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $this->authorizeNational($actor, 'roles.assign-department');

            if (!Str::isUuid($departmentId)) {
                throw ValidationException::withMessages(['department_id' => 'Selecciona un departamento válido.']);
            }

            $this->departments->find($departmentId);
            $this->users->find((int) $target->id);

            return $this->assignments->grantDepartment((int) $target->id, $departmentId, $roleName);
        });
    }

    public function assignOrganization(User $actor, OrganizationMembership $target, string $roleName): OrganizationRoleAssignment
    {
        return DB::transaction(function () use ($actor, $target, $roleName): OrganizationRoleAssignment {
            $organization = $this->tenants->assertCurrentAccess($actor, true);

            if ($this->context->accessMode() === 'department_read'
                || $organization->id !== $target->organization_id
                || !$this->permissions->allowsOrganization($actor, 'roles.assign-organization', $organization->id)) {
                throw new AuthorizationException('No puedes asignar roles en esta organización.');
            }

            // El objeto recibido puede estar desactualizado: se recupera y bloquea dentro del tenant.
            $membership = $this->memberships->findForTenant($organization->id, $target->id, true);

            if (!$membership->active || $membership->left_at !== null) {
                throw ValidationException::withMessages(['membership_id' => 'La membresía debe estar activa para asignarle un rol.']);
            }

            return $this->assignments->grantOrganization($membership, $roleName);
        });
    }

    private function authorizeNational(User $actor, string $permission): void
    {
        if (!$this->permissions->allowsNational($actor, $permission)) {
            throw new AuthorizationException('No tienes autorización nacional para esta operación.');
        }
    }
}

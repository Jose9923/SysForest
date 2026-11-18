<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Auth\DepartmentRoleAssignment;
use App\Models\Auth\NationalRoleAssignment;
use App\Models\Memberships\OrganizationMembership;
use App\Models\Auth\OrganizationRoleAssignment;
use App\Models\User;
use App\Repositories\Territories\DepartmentRepository;
use App\Repositories\Memberships\OrganizationMembershipRepository;
use App\Repositories\Auth\RoleAssignmentRepository;
use App\Repositories\Users\UserRepository;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditSnapshotService;
use App\Services\Tenancy\TenantContextService;

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
        private AuditSnapshotService $snapshots,
        private \App\Repositories\Auth\SecurityEventRepository $events,
    ) {
    }

    public function assignNational(User $actor, User $target, string $roleName = 'SUPER_ADMIN'): NationalRoleAssignment
    {
        return DB::transaction(function () use ($actor, $target, $roleName): NationalRoleAssignment {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $this->authorizeNational($actor, 'roles.assign-national');
            $this->users->find((int) $target->id);

            $previous = $this->assignments->nationalAssignment((int) $target->id, $roleName);
            $before = $previous === null ? [] : $this->snapshots->snapshot($previous);
            $assignment = $this->assignments->grantNational((int) $target->id, $roleName);
            $this->events->recordChange(
                'role.assigned',
                $actor,
                null,
                'national_role_assignment',
                $assignment->id,
                $before,
                $this->snapshots->snapshot($assignment),
                ['target_user_id' => $target->id, 'role_name' => $roleName]
            );

            return $assignment;
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

            if (!\App\Support\Identifiers\RecordIdentifier::isValid($departmentId)) {
                throw ValidationException::withMessages(['department_id' => 'Selecciona un departamento válido.']);
            }

            $this->departments->find($departmentId);
            $this->users->find((int) $target->id);

            $previous = $this->assignments->departmentAssignment((int) $target->id, $departmentId, $roleName);
            $before = $previous === null ? [] : $this->snapshots->snapshot($previous);
            $assignment = $this->assignments->grantDepartment((int) $target->id, $departmentId, $roleName);
            $this->events->recordChange(
                'role.assigned',
                $actor,
                null,
                'department_role_assignment',
                $assignment->id,
                $before,
                $this->snapshots->snapshot($assignment),
                ['target_user_id' => $target->id, 'role_name' => $roleName, 'department_id' => $departmentId]
            );

            return $assignment;
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

            $previous = $this->assignments->organizationRole($membership);
            $before = $previous === null ? [] : $this->snapshots->snapshot($previous);
            $assignment = $this->assignments->grantOrganization($membership, $roleName);
            $this->events->recordChange(
                'role.assigned',
                $actor,
                $organization->id,
                'organization_role_assignment',
                $assignment->id,
                $before,
                $this->snapshots->snapshot($assignment),
                ['target_user_id' => $membership->user_id, 'membership_id' => $membership->id, 'role_name' => $roleName]
            );

            return $assignment;
        });
    }

    private function authorizeNational(User $actor, string $permission): void
    {
        if (!$this->permissions->allowsNational($actor, $permission)) {
            throw new AuthorizationException('No tienes autorización nacional para esta operación.');
        }
    }
}

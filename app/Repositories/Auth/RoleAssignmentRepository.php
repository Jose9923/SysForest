<?php

declare(strict_types=1);

namespace App\Repositories\Auth;

use App\Models\Territories\Department;
use App\Models\Auth\DepartmentRoleAssignment;
use App\Models\Auth\NationalRoleAssignment;
use App\Models\Organizations\Organization;
use App\Models\Memberships\OrganizationMembership;
use App\Models\Auth\OrganizationRoleAssignment;
use App\Models\Auth\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RoleAssignmentRepository
{
    public function __construct(private RoleRepository $roles)
    {
    }

    public function hasNationalPermission(int $userId, string $permission): bool
    {
        return NationalRoleAssignment::query()->where('user_id', $userId)->where('active', true)
            ->whereNull('revoked_at')->whereHas('role.permissions', fn (Builder $query) => $query->where('code', $permission))
            ->exists();
    }

    /** La lectura compartida exige una asignación vigente y un alcance todavía activo. */
    public function hasCatalogReadPermission(int $userId): bool
    {
        $permission = 'catalogs.view';
        if ($this->hasNationalPermission($userId, $permission)) {
            return true;
        }

        $department = DepartmentRoleAssignment::query()->where('user_id', $userId)
            ->where('active', true)->whereNull('revoked_at')
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('code', $permission))
            ->whereHas('department', fn (Builder $query) => $query->where('active', true)
                ->whereHas('country', fn (Builder $country) => $country->where('active', true)))->exists();

        return $department || OrganizationRoleAssignment::query()->where('active', true)->whereNull('revoked_at')
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('code', $permission))
            ->whereHas('membership', fn (Builder $query) => $query->where('user_id', $userId)
                ->where('active', true)->whereNull('left_at'))
            ->whereHas('organization', fn (Builder $query) => $query->where('active', true))->exists();
    }

    public function hasDepartmentPermission(int $userId, string $permission, string $departmentId): bool
    {
        return DepartmentRoleAssignment::query()->where('user_id', $userId)->where('department_id', $departmentId)
            ->where('active', true)->whereNull('revoked_at')
            ->whereHas('department', fn (Builder $query) => $query->where('active', true)
                ->whereHas('country', fn (Builder $country) => $country->where('active', true)))
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('code', $permission))->exists();
    }

    public function hasOrganizationPermission(int $userId, string $permission, string $organizationId): bool
    {
        return $this->organizationAssignments($userId, $organizationId)
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('code', $permission))->exists();
    }

    public function hasOrganizationRole(int $userId, string $organizationId): bool
    {
        return $this->organizationAssignments($userId, $organizationId)->exists();
    }

    public function activeOrganization(string $organizationId, bool $lock = false): ?Organization
    {
        $query = Organization::query()->with('municipality.department.country')
            ->whereKey($organizationId)->where('active', true);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function activeDepartment(string $departmentId): bool
    {
        return Department::query()->whereKey($departmentId)->where('active', true)
            ->whereHas('country', fn (Builder $query) => $query->where('active', true))->exists();
    }

    /** Alcances para la interfaz; la operación concreta siempre vuelve a autorizarse. */
    public function departmentsForUser(int $userId): Collection
    {
        return DepartmentRoleAssignment::query()->with('department.country', 'role')->where('user_id', $userId)
            ->where('active', true)->whereNull('revoked_at')
            ->whereHas('department', fn (Builder $query) => $query->where('active', true)
                ->whereHas('country', fn (Builder $country) => $country->where('active', true)))->get();
    }

    public function nationalForUser(int $userId): Collection
    {
        return NationalRoleAssignment::query()->with('role')->where('user_id', $userId)
            ->where('active', true)->whereNull('revoked_at')->get();
    }

    public function organizationRole(OrganizationMembership $membership): ?OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::query()->with('role')
            ->where('organization_id', $membership->organization_id)->where('membership_id', $membership->id)->first();
    }

    public function nationalAssignment(int $userId, string $roleName, bool $lock = false): ?NationalRoleAssignment
    {
        $query = NationalRoleAssignment::query()->where('user_id', $userId)
            ->where('role_id', $this->roleOfScope($roleName, 'national')->id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function departmentAssignment(int $userId, string $departmentId, string $roleName): ?DepartmentRoleAssignment
    {
        return DepartmentRoleAssignment::query()->where('user_id', $userId)->where('department_id', $departmentId)
            ->where('role_id', $this->roleOfScope($roleName, 'department')->id)->first();
    }

    /** API de persistencia para seeders y flujos que ya verificaron la autorización. */
    public function grantNational(int $userId, string $roleName = 'SUPER_ADMIN'): NationalRoleAssignment
    {
        $role = $this->roleOfScope($roleName, 'national');

        return NationalRoleAssignment::query()->updateOrCreate(
            ['user_id' => $userId, 'role_id' => $role->id],
            ['scope' => 'national', 'active' => true, 'assigned_at' => now(), 'revoked_at' => null],
        );
    }

    /** API de persistencia; no sustituye la autorización del actor en RoleAssignmentService. */
    public function grantDepartment(int $userId, string $departmentId, string $roleName = 'DEPARTMENT_ADMIN'): DepartmentRoleAssignment
    {
        $role = $this->roleOfScope($roleName, 'department');

        return DepartmentRoleAssignment::query()->updateOrCreate(
            ['user_id' => $userId, 'department_id' => $departmentId, 'role_id' => $role->id],
            ['scope' => 'department', 'active' => true, 'assigned_at' => now(), 'revoked_at' => null],
        );
    }

    /** Un miembro tiene un rol organizacional actual; su tenant no se puede reasignar. */
    public function grantOrganization(OrganizationMembership $membership, string $roleName): OrganizationRoleAssignment
    {
        $role = $this->roleOfScope($roleName, 'organization');

        return OrganizationRoleAssignment::query()->updateOrCreate(
            ['membership_id' => $membership->id],
            [
                'organization_id' => $membership->organization_id, 'role_id' => $role->id,
                'scope' => 'organization', 'active' => true, 'assigned_at' => now(), 'revoked_at' => null,
            ],
        );
    }

    private function organizationAssignments(int $userId, string $organizationId): Builder
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $organizationId)
            ->where('active', true)->whereNull('revoked_at')
            ->whereHas('membership', fn (Builder $query) => $query->where('user_id', $userId)
                ->where('organization_id', $organizationId)->where('active', true)->whereNull('left_at'))
            ->whereHas('organization', fn (Builder $query) => $query->where('active', true));
    }

    private function roleOfScope(string $name, string $scope): Role
    {
        $role = $this->roles->findByName($name);

        if ($role->scope !== $scope) {
            throw ValidationException::withMessages(['role_name' => 'El rol no corresponde al ámbito permitido.']);
        }

        return $role;
    }
}

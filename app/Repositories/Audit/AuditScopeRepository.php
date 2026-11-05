<?php

declare(strict_types=1);

namespace App\Repositories\Audit;

use App\Models\Auth\DepartmentRoleAssignment;
use App\Models\Auth\NationalRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Lee concesiones actuales para la bandeja privada, sin caché de roles ni membresías. */
class AuditScopeRepository
{
    public function eligibleUser(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('active', true)->whereNotNull('email_verified_at')->exists();
    }

    public function hasNationalAccess(int $userId): bool
    {
        return NationalRoleAssignment::query()->where('user_id', $userId)
            ->where('active', true)->whereNull('revoked_at')
            ->whereHas('role', fn (Builder $query) => $query->where('name', 'SUPER_ADMIN')->where('scope', 'national')
                ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('code', 'audit.view')))
            ->exists();
    }

    /** @return array<string> */
    public function departmentIds(int $userId): array
    {
        $ids = DepartmentRoleAssignment::query()->where('user_id', $userId)
            ->where('active', true)->whereNull('revoked_at')
            ->whereHas('role', fn (Builder $query) => $query->where('name', 'DEPARTMENT_ADMIN')->where('scope', 'department')
                ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('code', 'audit.view')))
            ->whereHas('department', fn (Builder $query) => $query->where('active', true)
                ->whereHas('country', fn (Builder $country) => $country->where('active', true)))
            ->pluck('department_id')->map(fn (string $id): string => strtolower($id))->unique()->values()->all();
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** El historial de una institución inactiva o archivada sigue perteneciendo a su departamento.
     * @param array<string> $departmentIds
     * @return array<string>
     */
    public function organizationIds(array $departmentIds): array
    {
        return DB::table('organizations as o')->join('municipalities as m', 'm.id', '=', 'o.municipality_id')
            ->whereIn('m.department_id', $departmentIds)->orderBy('o.id')->pluck('o.id')->all();
    }
}

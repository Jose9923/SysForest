<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OrganizationRepository
{
    public function find(string $id, bool $lock = false): Organization
    {
        $query = Organization::query()->with('municipality.department.country');

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    public function findByCode(string $code): Organization
    {
        return Organization::query()->with('municipality.department.country')->where('code', $code)->firstOrFail();
    }

    /** Une alcances departamentales autorizados y membresías con permiso de consulta vigente. */
    public function paginateForActor(int $actorId, array $departmentIds = []): LengthAwarePaginator
    {
        return Organization::query()->with('municipality.department.country')
            ->where(function (Builder $query) use ($actorId, $departmentIds): void {
                $query->where(function (Builder $memberOrganizations) use ($actorId): void {
                    $memberOrganizations->where('active', true)->whereHas('memberships', function (Builder $memberships) use ($actorId): void {
                        $memberships->where('user_id', $actorId)->where('active', true)->whereNull('left_at')
                            ->whereHas('roleAssignment', fn (Builder $roles) => $roles->where('active', true)->whereNull('revoked_at')
                                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('code', 'organizations.view')));
                    });
                });
                if ($departmentIds !== []) {
                    $query->orWhereHas('municipality', fn (Builder $municipalities) => $municipalities->whereIn('department_id', $departmentIds));
                }
            })->orderBy('name')->orderBy('id')->paginate(15);
    }

    /** Candidatas activas para selección móvil; el Service revalida el acceso de cada entidad. */
    public function mobileCandidates(int $actorId, bool $national, array $departmentIds): Collection
    {
        return Organization::query()->where('active', true)
            ->when(!$national, function (Builder $query) use ($actorId, $departmentIds): void {
                $query->where(function (Builder $scope) use ($actorId, $departmentIds): void {
                    $scope->whereHas('memberships', function (Builder $memberships) use ($actorId): void {
                        $memberships->where('user_id', $actorId)->where('active', true)->whereNull('left_at')
                            ->whereHas('roleAssignment', fn (Builder $roles) => $roles->where('active', true)->whereNull('revoked_at')
                                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('code', 'organizations.view')));
                    });
                    if ($departmentIds !== []) {
                        $scope->orWhereHas('municipality', fn (Builder $municipalities) => $municipalities->whereIn('department_id', $departmentIds));
                    }
                });
            })->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
    }

    /** Incluye entidades inactivas, pero no registros anulados mediante soft delete. */
    public function paginateNational(): LengthAwarePaginator
    {
        return Organization::query()->with('municipality.department.country')->orderBy('name')->orderBy('id')->paginate(15);
    }

    public function paginateForDepartment(string $departmentId): LengthAwarePaginator
    {
        return Organization::query()->with('municipality.department.country')
            ->whereHas('municipality', fn (Builder $query) => $query->where('department_id', $departmentId))
            ->orderBy('name')->orderBy('id')->paginate(15);
    }

    public function valueExists(string $column, string $value, ?string $exceptId = null): bool
    {
        if (!in_array($column, ['code', 'tree_code_prefix'], true)) {
            throw new \InvalidArgumentException('Campo de unicidad no permitido.');
        }

        return Organization::withTrashed()->where($column, $value)
            ->when($exceptId, fn ($query) => $query->where('id', '<>', $exceptId))->exists();
    }

    public function create(array $attributes): Organization
    {
        return Organization::query()->create($attributes)->refresh();
    }

    public function update(Organization $organization, array $attributes): Organization
    {
        $organization->fill($attributes)->save();

        return $organization->refresh()->load('municipality.department.country');
    }

    public function upsertPilot(array $attributes): Organization
    {
        return Organization::withTrashed()->updateOrCreate(['code' => $attributes['code']], $attributes);
    }
}

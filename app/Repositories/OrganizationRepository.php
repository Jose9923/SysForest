<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrganizationRepository
{
    public function find(string $id): Organization
    {
        return Organization::query()->with('municipality.department.country')->findOrFail($id);
    }

    public function findByCode(string $code): Organization
    {
        return Organization::query()->with('municipality.department.country')->where('code', $code)->firstOrFail();
    }

    public function paginateForActor(int $actorId): LengthAwarePaginator
    {
        return Organization::query()->with('municipality.department.country')->where('active', true)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $actorId)->where('active', true)->whereNull('left_at'))
            ->orderBy('name')->paginate(15);
    }

    /** Estas consultas explícitas solo se invocan tras autorización administrativa en el Service. */
    public function paginateNational(): LengthAwarePaginator
    {
        return Organization::query()->with('municipality.department.country')->orderBy('name')->paginate(15);
    }

    public function paginateForDepartment(string $departmentId): LengthAwarePaginator
    {
        return Organization::query()->with('municipality.department.country')
            ->whereHas('municipality', fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('name')->paginate(15);
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
        return Organization::query()->create($attributes);
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

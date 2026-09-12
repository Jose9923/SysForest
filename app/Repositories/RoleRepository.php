<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository
{
    public function findByName(string $name): Role
    {
        return Role::query()->with('permissions')->where('name', $name)->firstOrFail();
    }

    public function forScope(string $scope): Collection
    {
        return Role::query()->with('permissions')->where('scope', $scope)->orderBy('name')->get();
    }

    public function upsert(string $name, string $scope): Role
    {
        return Role::query()->updateOrCreate(['name' => $name], ['scope' => $scope]);
    }

    /** @param array<int, string> $permissionIds */
    public function syncPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
    }
}

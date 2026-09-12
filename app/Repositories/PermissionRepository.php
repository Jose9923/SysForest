<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class PermissionRepository
{
    public function upsert(string $code, string $description): Permission
    {
        return Permission::query()->updateOrCreate(['code' => $code], ['description' => $description]);
    }

    public function all(): Collection
    {
        return Permission::query()->orderBy('code')->get();
    }
}

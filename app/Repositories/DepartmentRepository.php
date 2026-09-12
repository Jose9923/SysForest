<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Department;

class DepartmentRepository
{
    public function upsert(string $countryId, string $code, array $attributes): Department
    {
        return Department::query()->updateOrCreate(['country_id' => $countryId, 'code' => $code], $attributes);
    }

    public function find(string $id): Department
    {
        return Department::query()->with('country')->findOrFail($id);
    }
}

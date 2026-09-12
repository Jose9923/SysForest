<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Collection;

class DepartmentRepository
{
    public function upsert(string $countryId, string $code, array $attributes): Department
    {
        return Department::query()->updateOrCreate(['country_id' => $countryId, 'code' => $code], $attributes);
    }

    public function activeOptions(): Collection
    {
        return Department::query()->with('country')->where('active', true)
            ->whereHas('country', fn ($query) => $query->where('active', true))->orderBy('name')->get();
    }

    public function find(string $id): Department
    {
        return Department::query()->with('country')->findOrFail($id);
    }
}

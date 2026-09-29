<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Country;
use App\Models\Department;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Collection;

/** Acceso al catálogo nacional para altas idempotentes, sin actualizar registros existentes. */
class TerritorySeedRepository
{
    public function countryCandidates(array $attributes): Collection
    {
        return Country::query()->where('code', $attributes['code'])
            ->orWhere('iso_alpha_2', $attributes['iso_alpha_2'])
            ->orWhere('iso_alpha_3', $attributes['iso_alpha_3'])->get();
    }

    public function ensureCountry(array $attributes): Country
    {
        return Country::query()->firstOrCreate(['code' => $attributes['code']], $attributes + ['active' => true]);
    }

    /** La fila raíz serializa dos ejecuciones del seeder dentro de la misma transacción. */
    public function lockCountry(string $id): Country
    {
        return Country::query()->lockForUpdate()->findOrFail($id);
    }

    public function departments(string $countryId): Collection
    {
        return Department::query()->where('country_id', $countryId)->get();
    }

    public function municipalities(string $countryId): Collection
    {
        return Municipality::query()->whereHas('department', fn ($query) => $query->where('country_id', $countryId))->get();
    }

    public function ensureDepartment(string $countryId, array $attributes): Department
    {
        return Department::query()->firstOrCreate(
            ['country_id' => $countryId, 'code' => $attributes['code']],
            ['name' => $attributes['name'], 'active' => true],
        );
    }

    public function ensureMunicipality(string $departmentId, array $attributes): Municipality
    {
        return Municipality::query()->firstOrCreate(
            ['department_id' => $departmentId, 'code' => $attributes['code']],
            ['name' => $attributes['name'], 'active' => true],
        );
    }

    public function municipality(string $countryId, string $code): Municipality
    {
        return Municipality::query()->where('code', $code)
            ->whereHas('department', fn ($query) => $query->where('country_id', $countryId))->sole();
    }
}

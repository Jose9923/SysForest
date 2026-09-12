<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CountryRepository
{
    public function upsert(string $code, array $attributes): Country
    {
        return Country::query()->updateOrCreate(['code' => $code], $attributes);
    }

    /** Consulta completa para administración nacional y preparación controlada de catálogos. */
    public function overview(): Collection
    {
        return Country::query()->with(['departments' => fn ($query) => $query->orderBy('name'),
            'departments.municipalities' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get();
    }

    /** El eager loading aplica los mismos límites que la selección del país para no filtrar solo la raíz. */
    public function overviewForScopes(array $departmentIds, array $municipalityIds): Collection
    {
        $departmentScope = function (Builder $query) use ($departmentIds, $municipalityIds): void {
            $query->where(function (Builder $scope) use ($departmentIds, $municipalityIds): void {
                $scope->whereIn('id', $departmentIds)
                    ->orWhereHas('municipalities', fn (Builder $municipalities) => $municipalities->whereIn('id', $municipalityIds));
            });
        };
        $municipalityScope = function (Builder $query) use ($departmentIds, $municipalityIds): void {
            $query->where(fn (Builder $scope) => $scope->whereIn('department_id', $departmentIds)->orWhereIn('id', $municipalityIds));
        };

        return Country::query()->whereHas('departments', $departmentScope)->with([
            'departments' => function ($query) use ($departmentScope): void {
                $departmentScope($query->getQuery());
                $query->orderBy('name');
            },
            'departments.municipalities' => function ($query) use ($municipalityScope): void {
                $municipalityScope($query->getQuery());
                $query->orderBy('name');
            },
        ])->orderBy('name')->get();
    }
}

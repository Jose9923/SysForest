<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Municipality;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MunicipalityRepository
{
    public function upsert(string $departmentId, string $code, array $attributes): Municipality
    {
        return Municipality::query()->updateOrCreate(['department_id' => $departmentId, 'code' => $code], $attributes);
    }

    public function find(string $id): Municipality
    {
        return Municipality::query()->with('department.country')->findOrFail($id);
    }

    public function activeOptions(): Collection
    {
        return Municipality::query()->with('department.country')->where('active', true)
            ->whereHas('department', fn ($query) => $query->where('active', true)
                ->whereHas('country', fn ($query) => $query->where('active', true)))
            ->orderBy('name')->get();
    }

    /** SQL espacial parametrizado: longitud es X, latitud es Y y las unidades son grados. */
    public function containingPoint(float $longitude, float $latitude): Collection
    {
        return Municipality::query()->with('department.country')->where('active', true)
            ->whereRaw('ST_Covers(geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$longitude, $latitude])->get();
    }

    public function storeGeometry(Municipality $municipality, string $wkt): void
    {
        DB::update(
            'UPDATE municipalities SET geometry = ST_GeomFromText(?, 4326), updated_at = ? WHERE id = ?',
            [$wkt, now(), $municipality->id]
        );
    }

    public function geometry(string $id): ?object
    {
        return DB::selectOne('SELECT ST_AsText(geometry) AS wkt, ST_SRID(geometry) AS srid FROM municipalities WHERE id = ?', [$id]);
    }
}

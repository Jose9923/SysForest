<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Municipality;
use App\Models\User;
use App\Repositories\CountryRepository;
use App\Repositories\MunicipalityRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TerritoryService
{
    public function __construct(private CountryRepository $countries, private MunicipalityRepository $municipalities)
    {
    }

    public function overview(): Collection
    {
        return $this->countries->overview();
    }

    public function municipalityOptions(): Collection
    {
        return $this->municipalities->activeOptions();
    }

    public function requireActiveMunicipality(string $id): Municipality
    {
        $municipality = $this->municipalities->find($id);

        if (!$municipality->active || !$municipality->department->active || !$municipality->department->country->active) {
            throw ValidationException::withMessages(['municipality_id' => 'El municipio y su jerarquía territorial deben estar activos.']);
        }

        return $municipality;
    }

    public function containingPoint(float $longitude, float $latitude): Collection
    {
        if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90 || !is_finite($longitude) || !is_finite($latitude)) {
            throw ValidationException::withMessages(['coordinates' => 'Las coordenadas deben ser válidas en EPSG:4326.']);
        }

        return $this->municipalities->containingPoint($longitude, $latitude);
    }

    public function storeMunicipalityGeometry(User $actor, string $id, string $wkt): void
    {
        Gate::forUser($actor)->authorize('territories.manage');

        DB::transaction(fn () => $this->municipalities->storeGeometry($this->municipalities->find($id), $wkt));
    }
}

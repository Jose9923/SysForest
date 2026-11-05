<?php

declare(strict_types=1);

namespace App\Repositories\Imports;

use App\Catalogs\CatalogDefinition;
use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Models\Territories\Department;
use App\Models\Taxonomy\Family;
use App\Models\Inventories\Inventory;
use App\Models\Territories\Municipality;
use App\Models\Organizations\Organization;
use App\Models\Taxonomy\Species;
use App\Models\Taxonomy\SpeciesCommonName;
use Illuminate\Database\Eloquent\Collection;

/** Lecturas completas para detectar conflictos sin sobrescribir el catálogo nacional vigente. */
class RestrepoOrnatoSeedRepository
{
    public function department(string $countryCode, string $departmentCode): Department
    {
        return Department::query()->with('country')->where('code', $departmentCode)
            ->whereHas('country', fn ($query) => $query->where('code', $countryCode))->firstOrFail();
    }

    public function municipality(string $code): ?Municipality
    {
        return Municipality::query()->where('code', $code)->first();
    }

    public function createMunicipality(array $attributes): Municipality
    {
        return Municipality::query()->create($attributes)->refresh();
    }

    /** Incluye anuladas para impedir que una segunda siembra resucite una institución. */
    public function organization(string $code): ?Organization
    {
        return Organization::withTrashed()->where('code', $code)->first();
    }

    public function inventory(string $organizationId, string $code): ?Inventory
    {
        return Inventory::query()->where('organization_id', $organizationId)->where('code', $code)->first();
    }

    /** Incluye inactivos: una coincidencia desactivada requiere revisión, no otra alta equivalente. */
    public function taxonomy(TaxonomyCatalog $catalog): Collection
    {
        return match ($catalog) {
            TaxonomyCatalog::Families => Family::query()->get(),
            TaxonomyCatalog::Species => Species::query()->get(),
            TaxonomyCatalog::CommonNames => SpeciesCommonName::query()->get(),
        };
    }

    public function catalog(CatalogDefinition $catalog): Collection
    {
        return $catalog->modelClass::query()->get();
    }
}

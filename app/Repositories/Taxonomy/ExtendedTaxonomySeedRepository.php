<?php

declare(strict_types=1);

namespace App\Repositories\Taxonomy;

use App\Catalogs\CatalogDefinition;
use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Models\Taxonomy\Family;
use App\Models\Taxonomy\Species;
use App\Models\Taxonomy\SpeciesCommonName;
use Illuminate\Database\Eloquent\Collection;

/** Incluye inactivos para detectar conflictos; las altas usan los repositorios nacionales existentes. */
class ExtendedTaxonomySeedRepository
{
    public function taxonomy(TaxonomyCatalog $catalog): Collection
    {
        return match ($catalog) {
            TaxonomyCatalog::Families => Family::query()->orderBy('code')->lockForUpdate()->get(),
            TaxonomyCatalog::Species => Species::query()->orderBy('code')->lockForUpdate()->get(),
            TaxonomyCatalog::CommonNames => SpeciesCommonName::query()->orderBy('code')->lockForUpdate()->get(),
        };
    }

    public function catalog(CatalogDefinition $catalog): Collection
    {
        return $catalog->modelClass::query()->orderBy('code')->lockForUpdate()->get();
    }
}

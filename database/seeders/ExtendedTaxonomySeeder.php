<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Taxonomy\ExtendedTaxonomySeedService;
use Illuminate\Database\Seeder;

/** Catálogos nacionales ampliados; ejecutar después de ForestryCatalogSeeder y TaxonomySeeder. */
class ExtendedTaxonomySeeder extends Seeder
{
    public function run(ExtendedTaxonomySeedService $service): void
    {
        $created = $service->seed();
        $this->command?->info('Catálogos ampliados: '.json_encode($created, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

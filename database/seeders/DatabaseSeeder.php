<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TerritorySeeder::class, PilotOrganizationSeeder::class, AuthorizationSeeder::class,
            ForestryCatalogSeeder::class, TaxonomySeeder::class, ConditionCatalogSeeder::class,
            EvidenceTypeSeeder::class, MaintenanceCatalogSeeder::class, ClassificationRuleSeeder::class,
            PilotInventorySeeder::class,
        ]);
        // Las cuentas de desarrollo siempre requieren ejecutar su seeder de forma explícita.
    }
}

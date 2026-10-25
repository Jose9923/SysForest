<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\RestrepoOrnatoImportService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('local')) {
            app(RestrepoOrnatoImportService::class)->assertFiles();
        }
        $this->call([
            TerritorySeeder::class, PilotOrganizationSeeder::class, AuthorizationSeeder::class,
            ForestryCatalogSeeder::class, TaxonomySeeder::class, ConditionCatalogSeeder::class,
            EvidenceTypeSeeder::class, MaintenanceCatalogSeeder::class, ClassificationRuleSeeder::class,
            PilotInventorySeeder::class,
        ]);
        // El arranque local incluye las cuentas y la preparación de Ornato por solicitud expresa.
        if (app()->environment('local')) {
            $this->call([DevelopmentIdentitySeeder::class, RestrepoOrnatoSeeder::class, RestrepoOrnatoImportSeeder::class]);
        }
        $this->call([RiskMethodologySeeder::class, RiskHistoricalEvaluationSeeder::class, CarbonSpeciesReferenceSeeder::class, CarbonEstimationSeeder::class]);
    }
}

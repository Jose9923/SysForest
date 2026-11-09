<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Auth\BaseAdministratorService;
use App\Repositories\Auth\BaseSeedRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Base productiva explícita: administrador y referencias, sin datos operativos ni pilotos. */
class BaseSeeder extends Seeder
{
    public function run(BaseAdministratorService $administrator, BaseSeedRepository $bootstrap): void
    {
        if (!app()->runningInConsole()) {
            throw new LogicException('La preparación base solo está disponible desde consola.');
        }

        $result = DB::transaction(function () use ($administrator, $bootstrap): array {
            $bootstrap->lock();
            $this->call([
                TerritorySeeder::class,
                AuthorizationSeeder::class,
                ForestryCatalogSeeder::class,
                TaxonomySeeder::class,
                ConditionCatalogSeeder::class,
                EvidenceTypeSeeder::class,
                MaintenanceCatalogSeeder::class,
                ClassificationRuleSeeder::class,
                ExtendedTaxonomySeeder::class,
                RiskMethodologySeeder::class,
                CarbonSpeciesReferenceSeeder::class,
            ]);

            return $administrator->seed();
        });

        $this->command?->info('Catálogos base preparados. Administrador '.($result['created'] ? 'creado' : 'conservado').': '.$result['email']);
        $this->command?->info('No se importaron árboles, inspecciones ni inventarios piloto.');
    }
}

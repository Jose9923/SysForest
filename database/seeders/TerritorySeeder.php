<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Territories\TerritorySeedService;
use Illuminate\Database\Seeder;

/** Completa los departamentos y municipios de la copia oficial de DIVIPOLA incluida en el proyecto. */
class TerritorySeeder extends Seeder
{
    public function run(TerritorySeedService $service): void
    {
        $result = $service->seed();
        $this->command?->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

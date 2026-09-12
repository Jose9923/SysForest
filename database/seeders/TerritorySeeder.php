<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\PilotSeedService;
use Illuminate\Database\Seeder;

class TerritorySeeder extends Seeder
{
    public function run(PilotSeedService $service): void
    {
        $service->seedTerritories();
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\CarbonReferenceSeedService;
use Illuminate\Database\Seeder;

class CarbonSpeciesReferenceSeeder extends Seeder
{
    public function run(CarbonReferenceSeedService $service): void
    {
        $result = $service->seed();
        $this->command?->info('Referencias agregadas: '.$result['created'].'. Conservadas: '.$result['existing']
            .'. Sin coincidencia exacta: '.$result['mismatched'].'.');
    }
}

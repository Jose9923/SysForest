<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Imports\RestrepoOrnatoSeedService;
use Illuminate\Database\Seeder;

/** Preparación explícita del territorio, institución, campaña y catálogos; no crea árboles. */
class RestrepoOrnatoSeeder extends Seeder
{
    public function run(RestrepoOrnatoSeedService $service): void
    {
        $result = $service->seed('superadmin@sysforest.test');
        $this->command?->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

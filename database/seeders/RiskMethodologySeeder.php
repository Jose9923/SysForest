<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\RiskRepository;
use App\Services\RiskMethodologyService;
use Illuminate\Database\Seeder;

/** Inicializa copias independientes; repetir no cambia decisiones ni versiones publicadas. */
class RiskMethodologySeeder extends Seeder
{
    public function run(RiskRepository $risks, RiskMethodologyService $methods): void
    {
        foreach ($risks->organizations() as $organization) {
            $methods->initialize($organization->id);
        }
    }
}

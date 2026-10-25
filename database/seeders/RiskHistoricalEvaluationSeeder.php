<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\HistoricalRiskService;
use Illuminate\Database\Seeder;

/** Repetir agrega únicamente visitas aún no evaluadas, sin reescribir decisiones. */
class RiskHistoricalEvaluationSeeder extends Seeder
{
    public function run(HistoricalRiskService $service): void
    {
        $service->backfill();
    }
}

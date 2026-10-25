<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\CarbonEstimationService;
use Illuminate\Database\Seeder;

/** No inventa densidades ni valida por una entidad; registra pendientes de manera idempotente. */
class CarbonEstimationSeeder extends Seeder
{
    public function run(): void
    {
        app(CarbonEstimationService::class)->backfill();
    }
}

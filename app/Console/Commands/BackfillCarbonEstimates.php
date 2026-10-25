<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CarbonEstimationService;
use Illuminate\Console\Command;

class BackfillCarbonEstimates extends Command
{
    protected $signature = 'sysforest:carbon-backfill {--organization= : UUID de una entidad}';
    protected $description = 'Agrega estimaciones de carbono o motivos pendientes sin modificar visitas ni resultados anteriores.';

    public function handle(CarbonEstimationService $service): int
    {
        $organization = $this->option('organization');
        if ($organization !== null && !\Illuminate\Support\Str::isUuid($organization)) {
            $this->error('La institución debe ser un UUID.');

            return self::FAILURE;
        }
        $this->info('Estimaciones agregadas: '.$service->backfill($organization).'.');

        return self::SUCCESS;
    }
}

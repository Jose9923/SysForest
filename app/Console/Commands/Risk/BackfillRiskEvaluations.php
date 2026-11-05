<?php

declare(strict_types=1);

namespace App\Console\Commands\Risk;

use App\Services\Risk\HistoricalRiskService;
use Illuminate\Console\Command;

/** Solo agrega evaluaciones pendientes; nunca confirma por el profesional. */
class BackfillRiskEvaluations extends Command
{
    protected $signature = 'sysforest:risk-backfill {--organization= : UUID de una institución}';
    protected $description = 'Evalúa visitas históricas sin cambiar datos ni confirmar prioridades.';

    public function handle(HistoricalRiskService $service): int
    {
        $count = $service->backfill($this->option('organization'));
        $this->info('Evaluaciones históricas agregadas: '.$count.'. Todas conservan la decisión profesional pendiente.');

        return self::SUCCESS;
    }
}

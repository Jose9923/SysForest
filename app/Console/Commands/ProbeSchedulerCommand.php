<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemStatusService;
use Illuminate\Console\Command;

class ProbeSchedulerCommand extends Command
{
    protected $signature = 'sysforest:probe-scheduler';

    protected $description = 'Registra en Redis una ejecución de prueba del programador';

    public function handle(SystemStatusService $service): int
    {
        $service->recordSchedulerProbe();
        $this->info('Ejecución del programador registrada.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\InfrastructureProbeJob;
use Illuminate\Console\Command;

class ProbeQueueCommand extends Command
{
    protected $signature = 'sysforest:probe-queue';

    protected $description = 'Encola una prueba de infraestructura para verificar el worker';

    public function handle(): int
    {
        InfrastructureProbeJob::dispatch();

        $this->info('Prueba enviada a la conexión de cola configurada.');

        return self::SUCCESS;
    }
}

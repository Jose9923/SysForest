<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CarbonRefreshService;
use Illuminate\Console\Command;

class DispatchCarbonRefreshes extends Command
{
    protected $signature = 'sysforest:dispatch-carbon-refreshes';
    protected $description = 'Recupera actualizaciones de carbono pendientes de despacho o interrumpidas.';

    public function handle(CarbonRefreshService $service): int
    {
        $count = $service->dispatchPending();
        $this->info('Solicitudes revisadas: '.$count.'.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SystemStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InfrastructureProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    /** Solo el procesamiento del trabajo registra la marca de la cola. */
    public function handle(SystemStatusService $service): void
    {
        $service->recordQueueProbe();
    }
}

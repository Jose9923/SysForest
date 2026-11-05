<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Carbon\CarbonRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshCarbonEstimates implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 45;
    public bool $failOnTimeout = false;

    public function __construct(public string $runId)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(CarbonRefreshService $service): void
    {
        if ($service->processBatch($this->runId)) {
            $service->dispatch($this->runId, true);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(CarbonRefreshService::class)->failed($this->runId);
    }
}

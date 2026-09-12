<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\InfrastructureStatus;
use App\Repositories\InfrastructureRepository;
use Carbon\CarbonImmutable;
use Throwable;

class SystemStatusService
{
    public function __construct(private readonly InfrastructureRepository $repository)
    {
    }

    public function status(): InfrastructureStatus
    {
        return new InfrastructureStatus(
            checks: [
                'database' => $this->safelyCheck($this->repository->checkDatabase(...)),
                'postgis' => $this->safelyCheck($this->repository->checkPostgis(...)),
                'redis' => $this->safelyCheck($this->repository->checkRedis(...)),
            ],
            queueLastProcessedAt: $this->safelyRead($this->repository->latestQueueProbe(...)),
            schedulerLastRunAt: $this->safelyRead($this->repository->latestSchedulerProbe(...)),
        );
    }

    public function recordQueueProbe(): void
    {
        $this->repository->recordQueueProbe(CarbonImmutable::now('UTC')->toIso8601String());
    }

    public function recordSchedulerProbe(): void
    {
        $this->repository->recordSchedulerProbe(CarbonImmutable::now('UTC')->toIso8601String());
    }

    /** Una dependencia caída no impide comprobar las demás ni revela sus errores. */
    private function safelyCheck(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable) {
            return false;
        }
    }

    private function safelyRead(callable $read): ?string
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}

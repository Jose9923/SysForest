<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class InfrastructureStatus
{
    /**
     * Los estados contienen resultados técnicos, nunca mensajes de excepción.
     *
     * @param array{database: bool, postgis: bool, redis: bool} $checks
     */
    public function __construct(
        public array $checks,
        public ?string $queueLastProcessedAt,
        public ?string $schedulerLastRunAt,
    ) {
    }

    public function isReady(): bool
    {
        return ! in_array(false, $this->checks, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class InfrastructureRepository
{
    public const QUEUE_PROBE_KEY = 'sysforest:probe:queue';

    public const SCHEDULER_PROBE_KEY = 'sysforest:probe:scheduler';

    private const PROBE_TTL_SECONDS = 604800;

    /** Comprueba una consulta real sobre la conexión SQL configurada. */
    public function checkDatabase(): bool
    {
        $result = DB::selectOne('SELECT 1 AS reachable');

        return $result !== null && (int) $result->reachable === 1;
    }

    /** Verifica la extensión PostGIS y una operación espacial con SRID explícito. */
    public function checkPostgis(): bool
    {
        $result = DB::selectOne(
            'SELECT PostGIS_Version() AS version, '
            .'ST_SRID(ST_SetSRID(ST_MakePoint(0, 0), 4326)) AS srid',
        );

        return $result !== null
            && is_string($result->version)
            && $result->version !== ''
            && (int) $result->srid === 4326;
    }

    public function checkRedis(): bool
    {
        $response = Redis::connection()->ping();

        // PhpRedis y Predis representan PONG de manera diferente.
        return $response === true || strtoupper(ltrim((string) $response, '+')) === 'PONG';
    }

    public function recordQueueProbe(string $timestamp): void
    {
        Redis::connection()->setex(self::QUEUE_PROBE_KEY, self::PROBE_TTL_SECONDS, $timestamp);
    }

    public function recordSchedulerProbe(string $timestamp): void
    {
        Redis::connection()->setex(self::SCHEDULER_PROBE_KEY, self::PROBE_TTL_SECONDS, $timestamp);
    }

    public function latestQueueProbe(): ?string
    {
        return $this->readProbe(self::QUEUE_PROBE_KEY);
    }

    public function latestSchedulerProbe(): ?string
    {
        return $this->readProbe(self::SCHEDULER_PROBE_KEY);
    }

    private function readProbe(string $key): ?string
    {
        $value = Redis::connection()->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

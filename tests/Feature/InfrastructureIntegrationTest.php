<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\InfrastructureRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('infrastructure')]
class InfrastructureIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SYSFOREST_RUN_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requiere servicios locales y SYSFOREST_RUN_INTEGRATION=1.');
        }
    }

    public function test_configured_database_supports_real_postgis_queries(): void
    {
        $repository = $this->app->make(InfrastructureRepository::class);

        $this->assertSame('pgsql', DB::connection()->getDriverName(), 'La prueba requiere la conexión PostgreSQL local.');
        $this->assertTrue($repository->checkDatabase());
        $this->assertTrue($repository->checkPostgis());

        $point = DB::selectOne('SELECT ST_AsText(ST_SetSRID(ST_MakePoint(1, 2), 4326)) AS geometry');
        $this->assertSame('POINT(1 2)', $point->geometry);
    }

    public function test_redis_supports_a_real_write_read_and_delete_cycle(): void
    {
        $repository = $this->app->make(InfrastructureRepository::class);
        $this->assertTrue($repository->checkRedis());

        $key = 'sysforest:test:'.bin2hex(random_bytes(12));
        $value = bin2hex(random_bytes(16));

        try {
            Redis::connection()->setex($key, 60, $value);
            $this->assertSame($value, Redis::connection()->get($key));
        } finally {
            Redis::connection()->del($key);
        }

        $this->assertFalse((bool) Redis::connection()->exists($key));
    }
}

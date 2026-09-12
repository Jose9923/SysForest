<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\InfrastructureRepository;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    public function test_home_renders_the_infrastructure_view_with_real_status_contract(): void
    {
        $this->bindHealthyRepository();

        $this->get('/')
            ->assertOk()
            ->assertViewIs('system-status')
            ->assertSee('SYSFOREST')
            ->assertSee('PostgreSQL')
            ->assertSee('PostGIS')
            ->assertSee('Redis')
            ->assertSee('Sin ejecución registrada')
            ->assertSee('images/sysforest-logo.jpeg');
    }

    public function test_readiness_returns_only_a_generic_success_body(): void
    {
        $this->bindHealthyRepository();

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_returns_503_without_connection_details_on_failure(): void
    {
        config(['session.driver' => 'redis']);
        Redis::shouldReceive('connection')->never();

        $this->mock(InfrastructureRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('checkDatabase')->once()->andThrow(new RuntimeException('password=do-not-expose host=private-db'));
            $mock->shouldReceive('checkPostgis')->once()->andReturn(false);
            $mock->shouldReceive('checkRedis')->once()->andReturn(true);
            $mock->shouldReceive('latestQueueProbe')->once()->andReturn(null);
            $mock->shouldReceive('latestSchedulerProbe')->once()->andReturn(null);
        });

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error'])
            ->assertDontSee('do-not-expose')
            ->assertDontSee('private-db');
    }

    private function bindHealthyRepository(): void
    {
        $this->mock(InfrastructureRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('checkDatabase')->once()->andReturn(true);
            $mock->shouldReceive('checkPostgis')->once()->andReturn(true);
            $mock->shouldReceive('checkRedis')->once()->andReturn(true);
            $mock->shouldReceive('latestQueueProbe')->once()->andReturn(null);
            $mock->shouldReceive('latestSchedulerProbe')->once()->andReturn(null);
        });
    }
}

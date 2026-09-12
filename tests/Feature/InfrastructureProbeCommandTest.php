<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\InfrastructureProbeJob;
use App\Services\SystemStatusService;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class InfrastructureProbeCommandTest extends TestCase
{
    public function test_queue_probe_command_dispatches_a_job_for_the_worker(): void
    {
        Queue::fake();

        $this->artisan('sysforest:probe-queue')->assertSuccessful();

        Queue::assertPushed(InfrastructureProbeJob::class, 1);
    }

    public function test_scheduler_probe_command_records_execution_via_the_service(): void
    {
        $this->mock(SystemStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordSchedulerProbe')->once();
        });

        $this->artisan('sysforest:probe-scheduler')->assertSuccessful();
    }

    public function test_processing_the_probe_job_records_execution_via_the_service(): void
    {
        $service = $this->mock(SystemStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordQueueProbe')->once();
        });

        (new InfrastructureProbeJob())->handle($service);
    }
}

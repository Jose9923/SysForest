<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\InfrastructureRepository;
use App\Services\SystemStatusService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SystemStatusServiceTest extends TestCase
{
    public function test_all_dependencies_must_be_available_for_readiness(): void
    {
        $repository = $this->createMock(InfrastructureRepository::class);
        $repository->expects($this->once())->method('checkDatabase')->willReturn(true);
        $repository->expects($this->once())->method('checkPostgis')->willReturn(true);
        $repository->expects($this->once())->method('checkRedis')->willReturn(true);
        $repository->method('latestQueueProbe')->willReturn('2026-09-10T12:00:00+00:00');
        $repository->method('latestSchedulerProbe')->willReturn(null);

        $status = (new SystemStatusService($repository))->status();

        $this->assertTrue($status->isReady());
        $this->assertSame('2026-09-10T12:00:00+00:00', $status->queueLastProcessedAt);
        $this->assertNull($status->schedulerLastRunAt);
    }

    public function test_a_failed_dependency_does_not_hide_the_other_results(): void
    {
        $repository = $this->createMock(InfrastructureRepository::class);
        $repository->expects($this->once())->method('checkDatabase')->willThrowException(new RuntimeException('private connection details'));
        $repository->expects($this->once())->method('checkPostgis')->willReturn(false);
        $repository->expects($this->once())->method('checkRedis')->willReturn(true);
        $repository->method('latestQueueProbe')->willReturn(null);
        $repository->method('latestSchedulerProbe')->willReturn(null);

        $status = (new SystemStatusService($repository))->status();

        $this->assertFalse($status->isReady());
        $this->assertSame(['database' => false, 'postgis' => false, 'redis' => true], $status->checks);
    }

    public function test_a_redis_failure_also_degrades_optional_probe_reads(): void
    {
        $repository = $this->createMock(InfrastructureRepository::class);
        $repository->method('checkDatabase')->willReturn(true);
        $repository->method('checkPostgis')->willReturn(true);
        $repository->expects($this->once())->method('checkRedis')->willThrowException(new RuntimeException('redis secret'));
        $repository->method('latestQueueProbe')->willThrowException(new RuntimeException('redis secret'));
        $repository->method('latestSchedulerProbe')->willThrowException(new RuntimeException('redis secret'));

        $status = (new SystemStatusService($repository))->status();

        $this->assertFalse($status->isReady());
        $this->assertNull($status->queueLastProcessedAt);
        $this->assertNull($status->schedulerLastRunAt);
    }
}

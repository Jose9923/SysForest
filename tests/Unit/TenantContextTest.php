<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\TestCase;

class TenantContextTest extends TestCase
{
    public function test_access_without_context_fails_closed(): void
    {
        $this->expectException(AuthorizationException::class);
        (new TenantContext())->organizationId();
    }

    public function test_context_can_be_cleared_and_instances_do_not_share_state(): void
    {
        $first = new TenantContext();
        $second = new TenantContext();
        $first->activate(5, '01950000-0000-7000-8000-000000000001');
        $this->assertSame(5, $first->actorId());
        $this->assertSame('01950000-0000-7000-8000-000000000001', $first->organizationId());
        $this->assertFalse($second->isActive());
        $first->clear();
        $this->assertFalse($first->isActive());
    }
}

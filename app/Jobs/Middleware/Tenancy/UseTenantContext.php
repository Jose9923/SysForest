<?php

declare(strict_types=1);

namespace App\Jobs\Middleware\Tenancy;

use App\Repositories\Users\UserRepository;
use App\Services\Tenancy\TenantContextService;
use Closure;

class UseTenantContext
{
    public function __construct(private int $actorId, private string $organizationId)
    {
    }

    public function handle(object $job, Closure $next): mixed
    {
        $context = app(\App\Tenancy\TenantContext::class);
        $context->clear();

        try {
            $actor = app(UserRepository::class)->find($this->actorId);

            return app(TenantContextService::class)->run($actor, $this->organizationId, fn () => $next($job));
        } finally {
            $context->clear();
        }
    }
}

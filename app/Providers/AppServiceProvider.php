<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\OrganizationMembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Tenancy\LocalAccessContext;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(LocalAccessContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationMembership::class, OrganizationMembershipPolicy::class);

        // Capacidades técnicas locales, independientes de las membresías y de futuros roles.
        Gate::define('organizations.create', fn (User $actor): bool => app(LocalAccessContext::class)->isGrantedTo($actor));
        Gate::define('organizations.manage', function (User $actor, Organization $organization): bool {
            $tenant = app(TenantContext::class);

            return app(LocalAccessContext::class)->isGrantedTo($actor) && $tenant->isActive()
                && $tenant->actorId() === (int) $actor->id && $tenant->organizationId() === $organization->id;
        });
        Gate::define('memberships.manage', function (User $actor): bool {
            $tenant = app(TenantContext::class);

            return app(LocalAccessContext::class)->isGrantedTo($actor) && $tenant->isActive()
                && $tenant->actorId() === (int) $actor->id;
        });

        // Las consultas administrativas son explícitas, pero no existe todavía una concesión de Fase 4.
        Gate::define('organizations.view-national', fn (User $actor): bool => false);
        Gate::define('organizations.view-department', fn (User $actor, string $departmentId): bool => false);
        Gate::define('territories.manage', fn (User $actor): bool => false);
    }
}

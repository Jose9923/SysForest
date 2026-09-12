<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\OrganizationMembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // El guard conserva identidad solo durante la petición validada por JWT.
        Auth::viaRequest('jwt-context', fn (Request $request) => null);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationMembership::class, OrganizationMembershipPolicy::class);
        $permissions = fn () => app(PermissionService::class);
        Gate::define('organizations.create', fn (User $actor) => $permissions()->allowsNational($actor, 'organizations.create'));
        Gate::define('organizations.manage', fn (User $actor, Organization $organization) => $permissions()->allowsOrganization($actor, 'organizations.manage', $organization->id));
        Gate::define('organizations.manage-national', fn (User $actor) => $permissions()->allowsNational($actor, 'organizations.manage'));
        Gate::define('organizations.view-national', fn (User $actor) => $permissions()->allowsNational($actor, 'organizations.view'));
        Gate::define('organizations.view-department', fn (User $actor, string $department) => $permissions()->allowsDepartment($actor, 'organizations.view', $department));
        foreach (['territories.manage', 'users.view', 'users.manage', 'roles.assign-national', 'roles.assign-department'] as $permission) {
            Gate::define($permission, fn (User $actor) => $permissions()->allowsNational($actor, $permission));
        }
        foreach (['memberships.view', 'memberships.create', 'memberships.update', 'memberships.activate', 'memberships.deactivate', 'users.invite', 'roles.assign-organization'] as $permission) {
            Gate::define($permission, function (User $actor) use ($permission, $permissions): bool {
                $tenant = app(TenantContext::class);

                return $tenant->isActive() && $tenant->actorId() === (int) $actor->id
                    && $tenant->accessMode() !== 'department_read'
                    && $permissions()->allowsOrganization($actor, $permission, $tenant->organizationId());
            });
        }
        Gate::define('memberships.manage', fn (User $actor) => Gate::forUser($actor)->allows('memberships.update'));

        foreach (['login' => 10, 'refresh' => 30, 'password' => 5, 'invitation' => 20] as $name => $default) {
            RateLimiter::for('identity.'.$name, function (Request $request) use ($name, $default): array {
                $limit = (int) config('security.'.$name.'_rate_limit', $default);
                $key = hash('sha256', $request->ip().'|'.mb_strtolower((string) $request->input('email', '')));

                return [
                    Limit::perMinute($limit)->by($name.':'.$key),
                    Limit::perMinute($limit * 3)->by($name.':ip:'.$request->ip()),
                ];
            });
        }
    }
}

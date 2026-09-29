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
        Gate::define('audit.view', fn (User $actor) => app(\App\Services\AuditAccessService::class)->canView($actor)
            || app(\App\Services\AuditAccessService::class)->canViewNational($actor));
        RateLimiter::for('audit.read', fn (Request $request) => Limit::perMinute(90)
            ->by('audit:user:'.($request->user()?->id ?? $request->ip())));
        Gate::define('maps.view', fn (User $actor) => app(\App\Services\MapAccessService::class)->canView($actor));
        foreach (['imports.view', 'imports.create'] as $permission) {
            Gate::define($permission, fn (User $actor) => app(\App\Services\ImportAccessService::class)->allows($actor, $permission));
        }
        RateLimiter::for('imports.write', fn (Request $request) => Limit::perMinute(max(1, (int) config('imports.rate_limit', 6)))
            ->by('imports:user:'.($request->user()?->id ?? hash('sha256', (string) $request->ip()))));
        Gate::define('reports.view', fn (User $actor) => app(\App\Services\ReportAccessService::class)->canView($actor));
        RateLimiter::for('reports.download', fn (Request $request) => Limit::perMinute(max(1, (int) config('reports.download_rate_private', 12)))
            ->by('reports:user:'.($request->user()?->id ?? hash('sha256', (string) $request->ip()))));
        RateLimiter::for('public.exports', fn (Request $request) => Limit::perMinute(max(1, (int) config('reports.download_rate_public', 6)))
            ->by('public:exports:'.hash('sha256', (string) $request->ip())));
        Gate::define('publication.manage', fn (User $actor) => app(\App\Services\PublicationAccessService::class)->canManage($actor));
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(\App\Models\Tree::class, \App\Policies\TreePolicy::class);
        Gate::policy(\App\Models\TreeInspection::class, \App\Policies\TreeInspectionPolicy::class);
        Gate::policy(\App\Models\Evidence::class, \App\Policies\EvidencePolicy::class);
        Gate::policy(\App\Models\Maintenance::class, \App\Policies\MaintenancePolicy::class);
        Gate::policy(\App\Models\Inventory::class, \App\Policies\InventoryPolicy::class);
        foreach (['inventories.view', 'inventories.create', 'inventories.update', 'trees.view', 'trees.create', 'trees.update', 'trees.void', 'trees.restore', 'inspections.view', 'inspections.create', 'inspections.update', 'evidences.view', 'evidences.create', 'evidences.update', 'evidences.delete', 'evidences.restore', 'maintenances.view', 'maintenances.create', 'maintenances.update', 'maintenances.transition', 'maintenances.verify', 'maintenances.void', 'maintenances.restore'] as $permission) {
            Gate::define($permission, fn (User $actor) => app(\App\Services\ForestryAccessService::class)->allows($actor, $permission));
        }
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
        Gate::define('catalogs.view', fn (User $actor) => $permissions()->allowsCatalogView($actor));
        Gate::define('catalogs.manage', fn (User $actor) => $permissions()->allowsNational($actor, 'catalogs.manage'));

        foreach (config('public-portal.rate_limits') as $surface => $limit) {
            RateLimiter::for('public.'.$surface, fn (Request $request) => Limit::perMinute((int) $limit)
                ->by('public:'.$surface.':'.hash('sha256', (string) $request->ip())));
        }

        RateLimiter::for('maps.read', fn (Request $request) => Limit::perMinute((int) config('maps.rate_limit', 180))
            ->by('map:'.($request->user()?->id ?? $request->ip())));

        foreach (['login' => 10, 'refresh' => 30, 'password' => 5, 'invitation' => 20] as $name => $default) {
            RateLimiter::for('identity.'.$name, function (Request $request) use ($name, $default): array {
                $limit = (int) config('security.'.$name.'_rate_limit', $default);
                // El límite se aplica antes del FormRequest: una entrada compuesta no debe producir un error 500.
                $email = $request->input('email');
                $email = is_string($email) ? mb_strtolower(trim($email)) : '';
                $key = hash('sha256', $request->ip().'|'.$email);

                return [
                    Limit::perMinute($limit)->by($name.':'.$key),
                    Limit::perMinute($limit * 3)->by($name.':ip:'.$request->ip()),
                ];
            });
        }
    }
}

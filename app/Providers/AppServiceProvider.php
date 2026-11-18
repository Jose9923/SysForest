<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Organizations\Organization;
use App\Models\Memberships\OrganizationMembership;
use App\Models\User;
use App\Policies\Memberships\OrganizationMembershipPolicy;
use App\Policies\Organizations\OrganizationPolicy;
use App\Services\Auth\PermissionService;
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
        $this->app->scoped(\App\Repositories\Identifiers\IdentifierRepository::class);
        $this->app->scoped(\App\Services\Risk\HistoricalRiskImportScope::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Validator::extend(
            'record_id',
            static fn ($attribute, $value): bool => \App\Support\Identifiers\RecordIdentifier::isValid($value),
            'La referencia seleccionada no es válida.'
        );

        // El guard conserva identidad solo durante la petición validada por JWT.
        Auth::viaRequest('jwt-context', fn (Request $request) => null);
        Gate::define('audit.view', fn (User $actor) => app(\App\Services\Audit\AuditAccessService::class)->canView($actor)
            || app(\App\Services\Audit\AuditAccessService::class)->canViewNational($actor));
        RateLimiter::for('audit.read', fn (Request $request) => Limit::perMinute(90)
            ->by('audit:user:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('audit.export', fn (Request $request) => Limit::perMinute(max(1, (int) config('audit.export_rate_limit', 6)))
            ->by('audit:export:user:'.($request->user()?->id ?? hash('sha256', (string) $request->ip()))));
        Gate::define('maps.view', fn (User $actor) => app(\App\Services\Maps\MapAccessService::class)->canView($actor));
        foreach (['imports.view', 'imports.create'] as $permission) {
            Gate::define($permission, fn (User $actor) => app(\App\Services\Imports\ImportAccessService::class)->allows($actor, $permission));
        }
        RateLimiter::for('imports.write', fn (Request $request) => Limit::perMinute(max(1, (int) config('imports.rate_limit', 6)))
            ->by('imports:user:'.($request->user()?->id ?? hash('sha256', (string) $request->ip()))));
        Gate::define('reports.view', fn (User $actor) => app(\App\Services\Reports\ReportAccessService::class)->canView($actor));
        RateLimiter::for('reports.download', fn (Request $request) => Limit::perMinute(max(1, (int) config('reports.download_rate_private', 12)))
            ->by('reports:user:'.($request->user()?->id ?? hash('sha256', (string) $request->ip()))));
        RateLimiter::for('public.exports', fn (Request $request) => Limit::perMinute(max(1, (int) config('reports.download_rate_public', 6)))
            ->by('public:exports:'.hash('sha256', (string) $request->ip())));
        Gate::define('publication.manage', fn (User $actor) => app(\App\Services\Publication\PublicationAccessService::class)->canManage($actor));
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(\App\Models\Trees\Tree::class, \App\Policies\Trees\TreePolicy::class);
        Gate::policy(\App\Models\Inspections\TreeInspection::class, \App\Policies\Inspections\TreeInspectionPolicy::class);
        Gate::policy(\App\Models\Evidences\Evidence::class, \App\Policies\Evidences\EvidencePolicy::class);
        Gate::policy(\App\Models\Maintenances\Maintenance::class, \App\Policies\Maintenances\MaintenancePolicy::class);
        Gate::policy(\App\Models\Inventories\Inventory::class, \App\Policies\Inventories\InventoryPolicy::class);
        foreach (['carbon.manage', 'risk.view', 'risk.manage', 'inventories.view', 'inventories.create', 'inventories.update', 'trees.view', 'trees.create', 'trees.update', 'trees.void', 'trees.restore', 'inspections.view', 'inspections.create', 'inspections.update', 'evidences.view', 'evidences.create', 'evidences.update', 'evidences.delete', 'evidences.restore', 'maintenances.view', 'maintenances.create', 'maintenances.update', 'maintenances.transition', 'maintenances.verify', 'maintenances.void', 'maintenances.restore'] as $permission) {
            Gate::define($permission, fn (User $actor) => app(\App\Services\Forestry\ForestryAccessService::class)->allows($actor, $permission));
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

        RateLimiter::for('mobile.read', fn (Request $request) => Limit::perMinute(90)
            ->by('mobile:read:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('mobile.write', fn (Request $request) => Limit::perMinute(30)
            ->by('mobile:write:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('mobile.sync', fn (Request $request) => Limit::perMinute(20)
            ->by('mobile:sync:'.($request->user()?->id ?? $request->ip())));

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

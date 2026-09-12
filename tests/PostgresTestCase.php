<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Repositories\AuthSessionRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\PilotSeedService;
use App\Services\TokenService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;

abstract class PostgresTestCase extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as private startDatabaseTransaction;
    }

    private bool $authorizationCatalogReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('sysforest-phpunit-key-', 4)]);
    }

    /** Nunca se permite ejecutar fixtures mutables sobre la base operativa. */
    public function beginDatabaseTransaction(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== env('DB_TEST_DATABASE', 'sysforest_testing')) {
            throw new LogicException(sprintf('Base de pruebas rechazada: entorno=%s, driver=%s, base=%s, esperada=%s.', app()->environment(), config('database.default'), config('database.connections.pgsql.database'), env('DB_TEST_DATABASE', 'sysforest_testing')));
        }

        $this->startDatabaseTransaction();
    }

    /** Credencial real de fixture: HTTP debe verificar firma, sesión, cuenta y permisos. */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        if (! $user instanceof User || ! $user->exists || ($guard !== null && $guard !== 'web')) {
            throw new LogicException('El fixture HTTP requiere una cuenta SYSFOREST persistida y el guard web.');
        }

        $current = $user->fresh();
        $session = app(AuthSessionRepository::class)->create([
            'user_id' => $current->id,
            'expires_at' => now()->addMinutes((int) config('security.session_ttl_minutes')),
            'last_used_at' => now(),
        ]);
        $token = app(TokenService::class)->issue($current, $session);
        Auth::guard('web')->forgetUser();
        $this->withCookie((string) config('security.access_cookie'), $token)->withCredentials();
        $this->withSession(['auth_session_id' => $session->id, 'active_organization_id' => null]);

        return $this;
    }

    protected function pilot(): Organization
    {
        return app(PilotSeedService::class)->seedOrganization()->load('municipality.department.country');
    }

    protected function anotherOrganization(?Organization $reference = null): Organization
    {
        $reference ??= $this->pilot();
        $suffix = strtoupper(Str::random(10));

        return app(OrganizationRepository::class)->create([
            'municipality_id' => $reference->municipality_id, 'type' => 'other',
            'name' => 'Organización de prueba '.$suffix, 'code' => 'TEST-'.$suffix,
            'tree_code_prefix' => 'TEST-PREFIX-'.$suffix, 'active' => true,
        ]);
    }

    protected function member(User $user, Organization $organization): OrganizationMembership
    {
        $this->ensureAuthorizationCatalog();
        $membership = app(OrganizationMembershipRepository::class)->upsert(
            (int) $user->id,
            $organization->id,
            ['active' => true, 'joined_at' => now(), 'left_at' => null],
        );
        $assignments = app(RoleAssignmentRepository::class);
        if ($assignments->organizationRole($membership) === null) {
            $assignments->grantOrganization($membership, 'OFFICIAL');
        }

        return $membership;
    }

    /** Solo administra las membresías institucionales existentes de este fixture. */
    protected function allowManagement(User $actor): void
    {
        $this->ensureAuthorizationCatalog();

        foreach (app(OrganizationMembershipRepository::class)->forUser((int) $actor->id) as $membership) {
            if ($membership->active && $membership->left_at === null) {
                app(RoleAssignmentRepository::class)->grantOrganization($membership, 'ORGANIZATION_ADMIN');
            }
        }
    }

    /** La administración nacional se concede explícitamente, sin fabricar membresías. */
    protected function allowNationalManagement(User $actor): void
    {
        $this->ensureAuthorizationCatalog();
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);
    }

    protected function ensureAuthorizationCatalog(): void
    {
        if (! $this->authorizationCatalogReady) {
            $this->seed(AuthorizationSeeder::class);
            $this->authorizationCatalogReady = true;
        }
    }

    protected function actingTenant(User $actor, Organization $organization): static
    {
        $this->actingAs($actor);
        $this->withSession(['active_organization_id' => $organization->id]);

        return $this;
    }

    protected function organizationInput(Organization $reference): array
    {
        $suffix = strtoupper(Str::random(10));

        return ['name' => 'Institución de prueba', 'short_name' => 'Prueba', 'municipality_id' => $reference->municipality_id,
            'type' => 'school', 'code' => 'ORG-'.$suffix, 'tree_code_prefix' => 'PREFIX-'.$suffix,
            'email' => 'prueba@sysforest.test', 'phone' => null, 'active' => true];
    }
}

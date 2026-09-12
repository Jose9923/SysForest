<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\PilotSeedService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

abstract class PostgresTestCase extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as private startDatabaseTransaction;
    }

    /** Nunca se permite ejecutar fixtures mutables sobre la base operativa. */
    public function beginDatabaseTransaction(): void
    {
        if (!app()->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== env('DB_TEST_DATABASE', 'sysforest_testing')) {
            throw new LogicException(sprintf('Base de pruebas rechazada: entorno=%s, driver=%s, base=%s, esperada=%s.', app()->environment(), config('database.default'), config('database.connections.pgsql.database'), env('DB_TEST_DATABASE', 'sysforest_testing')));
        }

        $this->startDatabaseTransaction();
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

    protected function member(User $user, Organization $organization): \App\Models\OrganizationMembership
    {
        return app(OrganizationMembershipRepository::class)->upsert(
            (int) $user->id,
            $organization->id,
            ['active' => true, 'joined_at' => now(), 'left_at' => null]
        );
    }

    protected function allowManagement(User $actor): void
    {
        // Concesión explícita exclusiva del test; no convierte pertenencia en permiso en producción.
        Gate::define('organizations.create', fn (User $user): bool => $user->id === $actor->id);
        Gate::define('organizations.manage', fn (User $user, Organization $organization): bool => $user->id === $actor->id);
        Gate::define('memberships.manage', fn (User $user): bool => $user->id === $actor->id);
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

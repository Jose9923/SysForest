<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\AuthSessionRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Services\SessionService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Artisan;
use Tests\PostgresTestCase;

class LocalAccessTest extends PostgresTestCase
{
    public function test_legacy_access_routes_are_removed_in_local_and_production(): void
    {
        $environment = app()->environment();

        try {
            foreach (['local', 'production'] as $value) {
                app()->instance('env', $value);
                $this->get('/phase-3/access')->assertNotFound();
                $this->get('/phase-3/local-access/'.str_repeat('a', 64))->assertNotFound();
            }
        } finally {
            app()->instance('env', $environment);
        }
    }

    public function test_legacy_console_command_is_no_longer_registered(): void
    {
        $this->assertArrayNotHasKey('sysforest:local-access', Artisan::all());
    }

    public function test_legacy_links_cannot_create_accounts_or_memberships(): void
    {
        $this->pilot();
        $beforeUsers = User::query()->count();
        $beforeMemberships = \App\Models\OrganizationMembership::query()->count();
        $this->get('/phase-3/local-access/'.str_repeat('b', 64).'?expires='.(time() + 600).'&signature='.str_repeat('c', 64))
            ->assertNotFound();
        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeMemberships, \App\Models\OrganizationMembership::query()->count());
        $this->assertGuest();
    }

    public function test_a_forged_local_grant_cannot_elevate_an_official(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->actingTenant($actor, $organization)->withSession([
            'phase3_local_access' => ['actor_id' => $actor->id, 'expires_at' => time() + 1800],
        ])->get('/organizations/'.$organization->id.'/edit')->assertForbidden();
        $this->get('/organizations/create')->assertForbidden();
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_a_legacy_session_identifier_does_not_replace_the_jwt_cookie(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->allowManagement($actor);
        $this->withSession([
            'login_web_'.sha1(SessionGuard::class) => $actor->id,
            'active_organization_id' => $organization->id,
            'phase3_local_access' => ['actor_id' => $actor->id, 'expires_at' => time() + 1800],
        ])->getJson('/organizations')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_a_legacy_grant_cannot_restore_a_revoked_jwt_session(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->actingTenant($actor, $organization);
        $session = app(AuthSessionRepository::class)->find(session('auth_session_id'));
        app(SessionService::class)->revoke($session, 'logout');
        $this->withSession([
            'phase3_local_access' => ['actor_id' => $actor->id, 'expires_at' => time() + 1800],
        ])->getJson('/organizations')->assertUnauthorized();
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_a_legacy_grant_cannot_restore_a_revoked_membership(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $membership = $this->member($actor, $organization);
        $this->actingTenant($actor, $organization);
        app(OrganizationMembershipRepository::class)->update($membership, ['active' => false, 'left_at' => now()]);
        $this->withSession([
            'phase3_local_access' => ['actor_id' => $actor->id, 'expires_at' => time() + 1800],
        ])->getJson('/organizations/'.$organization->id)->assertForbidden();
        $this->assertFalse(app(TenantContext::class)->isActive());
    }
}

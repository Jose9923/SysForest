<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\OrganizationMembershipRepository;
use App\Services\LocalAccessService;
use App\Tenancy\LocalAccessContext;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\PostgresTestCase;

class LocalAccessTest extends PostgresTestCase
{
    public function test_local_access_is_disabled_outside_local_environment(): void
    {
        $this->expectException(AuthorizationException::class);
        app(LocalAccessService::class)->issue('local@sysforest.test', 'INEM-VVC', true, true);
    }

    public function test_issuance_does_not_create_accounts_or_memberships_implicitly(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->app->instance('env', 'local');

        try {
            app(LocalAccessService::class)->issue($actor->email, $organization->code);
            $this->fail('Una cuenta sin membresía no puede emitir un enlace.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        }

        try {
            app(LocalAccessService::class)->issue('missing@sysforest.test', $organization->code);
            $this->fail('La emisión no debe crear una cuenta implícitamente.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('users', ['email' => 'missing@sysforest.test']);
        }
    }

    public function test_explicit_console_flags_create_only_one_account_and_membership(): void
    {
        $organization = $this->pilot();
        $this->app->instance('env', 'local');
        $service = app(LocalAccessService::class);
        $service->issue('explicit@sysforest.test', $organization->code, true, true);
        $service->issue('explicit@sysforest.test', $organization->code, true, true);
        $this->assertSame(1, User::query()->where('email', 'explicit@sysforest.test')->count());
        $actor = User::query()->where('email', 'explicit@sysforest.test')->firstOrFail();
        $this->assertSame(1, $actor->memberships()->count());
    }

    public function test_signed_link_is_single_use_and_cleans_request_context(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->app->instance('env', 'local');
        $url = app(LocalAccessService::class)->issue($actor->email, $organization->code);

        $this->get($url)->assertRedirect(route('organizations.index'));
        $this->assertAuthenticatedAs($actor);
        $this->get(route('organizations.create'))->assertOk();
        $this->get(route('organizations.show', $organization->id))->assertOk();
        $this->assertFalse(app(TenantContext::class)->isActive());
        $this->assertFalse(app(LocalAccessContext::class)->isGrantedTo($actor));
        $this->get($url)->assertForbidden();
    }

    public function test_tampered_signature_is_rejected_without_consuming_valid_link(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->app->instance('env', 'local');
        $url = app(LocalAccessService::class)->issue($actor->email, $organization->code);
        $this->get($url.'&tampered=1')->assertForbidden();
        $this->assertGuest();
        $this->get($url)->assertRedirect(route('organizations.index'));
    }

    public function test_revoked_membership_invalidates_previously_issued_link(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $membership = $this->member($actor, $organization);
        $this->app->instance('env', 'local');
        $url = app(LocalAccessService::class)->issue($actor->email, $organization->code);
        app(OrganizationMembershipRepository::class)->update($membership, ['active' => false, 'left_at' => now()]);
        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_expired_local_grant_logs_out_and_clears_context(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->app->instance('env', 'local');
        $this->actingTenant($actor, $organization)->withSession([
            'phase3_local_access' => ['actor_id' => $actor->id, 'expires_at' => time() - 1],
        ])->get(route('organizations.create'))->assertRedirect(route('phase3.access'));
        $this->assertGuest();
        $this->assertFalse(app(TenantContext::class)->isActive());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\AuthService;
use Illuminate\Support\Facades\Queue;
use Tests\PostgresTestCase;

class IdentityAdministrationHttpTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_official_has_no_member_navigation_or_backend_access(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $member = $this->member($actor, $organization);
        $this->actingTenant($actor, $organization);
        $this->get('/organizations/'.$organization->id)->assertOk()->assertDontSee('Usuarios de organización')->assertDontSee('Usuarios globales');
        $this->get('/memberships')->assertForbidden();
        $this->get('/invitations/create')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->patchJson('/memberships/'.$member->id, ['active' => false])->assertForbidden();
    }

    public function test_organization_admin_can_render_members_and_invitation_form(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->allowManagement($actor);
        $this->actingTenant($actor, $organization);
        $this->get('/organizations/'.$organization->id)->assertOk()->assertSee('Usuarios de organización')->assertDontSee('Usuarios globales');
        $this->get('/memberships')->assertOk()->assertSee($actor->email);
        $this->get('/invitations/create')->assertOk()->assertSee('OFFICIAL')->assertDontSee('SUPER_ADMIN');
    }

    public function test_member_of_a_can_remain_active_in_b_after_admin_disables_a(): void
    {
        $a = $this->pilot();
        $b = $this->anotherOrganization($a);
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->member($admin, $a);
        $this->allowManagement($admin);
        $memberA = $this->member($target, $a);
        $this->member($target, $b);
        $this->actingTenant($admin, $a)->patchJson('/memberships/'.$memberA->id, ['active' => false, 'role' => 'OFFICIAL'])->assertOk();
        $this->assertTrue($target->fresh()->active);
        $this->actingAs($target)->post('/active-organization', ['organization_id' => $a->id])->assertForbidden();
        $this->post('/active-organization', ['organization_id' => $b->id])->assertRedirect(route('organizations.show', $b->id));
        $this->get('/organizations/'.$b->id)->assertOk();
        $this->assertDatabaseHas('security_events', ['event' => 'membership.deactivated', 'organization_id' => $a->id]);
    }

    public function test_known_membership_uuid_does_not_escape_the_selected_organization(): void
    {
        $a = $this->pilot();
        $b = $this->anotherOrganization($a);
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->member($admin, $a);
        $this->allowManagement($admin);
        $memberB = $this->member($target, $b);
        $this->actingTenant($admin, $a)->patchJson('/memberships/'.$memberB->id, ['active' => false])->assertNotFound();
        $this->assertTrue($memberB->fresh()->active);
    }

    public function test_admin_cannot_promote_member_to_global_role_or_modify_global_state(): void
    {
        $organization = $this->pilot();
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->member($admin, $organization);
        $this->allowManagement($admin);
        $member = $this->member($target, $organization);
        $this->actingTenant($admin, $organization);
        $this->patchJson('/memberships/'.$member->id, ['active' => true, 'role' => 'SUPER_ADMIN'])->assertUnprocessable();
        $this->putJson('/users/'.$target->id, ['active' => false])->assertForbidden();
        $this->postJson('/users/'.$target->id.'/roles', ['scope' => 'national', 'role' => 'SUPER_ADMIN'])->assertForbidden();
        $this->assertTrue($target->fresh()->active);
        $this->assertDatabaseMissing('national_role_assignments', ['user_id' => $target->id]);
    }

    public function test_national_admin_disables_global_identity_and_revokes_live_sessions(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->allowNationalManagement($admin);
        $pair = app(AuthService::class)->login($target->email, 'password');
        $this->actingAs($admin)->put('/users/'.$target->id, ['active' => false])->assertRedirect();
        $this->assertFalse($target->fresh()->active);
        $this->assertNotNull($pair->session->fresh()->revoked_at);
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)->getJson('/profile')->assertUnauthorized();
    }

    public function test_national_admin_lists_users_and_assigns_a_department_without_membership(): void
    {
        $organization = $this->pilot();
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->allowNationalManagement($admin);
        $this->actingAs($admin);
        $this->get('/users')->assertOk()->assertSee($target->email);
        $this->get('/users/'.$target->id)->assertOk()->assertSee('Meta');
        $this->post('/users/'.$target->id.'/roles', [
            'scope' => 'department', 'role' => 'DEPARTMENT_ADMIN', 'department_id' => $organization->municipality->department_id,
        ])->assertRedirect();
        $this->assertDatabaseHas('department_role_assignments', ['user_id' => $target->id, 'department_id' => $organization->municipality->department_id]);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $target->id]);
    }

    public function test_department_admin_can_read_but_cannot_write_or_list_members(): void
    {
        $organization = $this->pilot();
        $admin = User::factory()->create();
        $this->ensureAuthorizationCatalog();
        app(RoleAssignmentRepository::class)->grantDepartment((int) $admin->id, $organization->municipality->department_id);
        $this->actingAs($admin)->post('/active-organization', ['organization_id' => $organization->id])->assertRedirect();
        $this->get('/organizations/'.$organization->id)->assertOk()->assertSee('Consulta departamental')->assertDontSee('Editar organización');
        $this->get('/memberships')->assertForbidden();
        $this->putJson('/organizations/'.$organization->id, $this->organizationInput($organization))->assertForbidden();
    }

    public function test_national_admin_can_reactivate_organization_without_selecting_it(): void
    {
        $organization = $this->pilot();
        $organization->update(['active' => false]);
        $admin = User::factory()->create();
        $this->allowNationalManagement($admin);
        $this->actingAs($admin)->get('/administration/organizations/'.$organization->id.'/edit')->assertOk();
        $this->put('/administration/organizations/'.$organization->id, ['lock_version' => $organization->fresh()->lock_version] + $this->organizationInput($organization))->assertRedirect(route('organizations.index'));
        $this->assertTrue($organization->fresh()->active);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $admin->id]);
    }

    public function test_profile_escapes_name_and_does_not_allow_identity_mass_assignment(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get('/profile')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->putJson('/profile', ['name' => 'Nuevo nombre', 'active' => false, 'role' => 'SUPER_ADMIN'])->assertUnprocessable();
        $this->put('/profile', ['name' => 'Nuevo nombre'])->assertRedirect();
        $this->assertSame('Nuevo nombre', $user->fresh()->name);
        $this->assertTrue($user->fresh()->active);
    }

    public function test_profile_cannot_revoke_another_users_session(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $pair = app(AuthService::class)->login($other->email, 'password');
        $this->actingAs($actor)->delete('/profile/sessions/'.$pair->session->id)->assertNotFound();
        $this->assertNull($pair->session->fresh()->revoked_at);
    }

    public function test_inviting_an_existing_user_does_not_create_a_membership_before_acceptance(): void
    {
        $organization = $this->pilot();
        $admin = User::factory()->create();
        $recipient = User::factory()->create();
        $this->member($admin, $organization);
        $this->allowManagement($admin);
        $this->actingTenant($admin, $organization);
        $this->post('/invitations', ['email' => $recipient->email, 'role' => 'OFFICIAL'])->assertRedirect(route('memberships.index'));
        $this->assertDatabaseHas('invitations', ['email' => $recipient->email, 'organization_id' => $organization->id]);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $recipient->id]);
    }

    public function test_invitation_rejects_forged_tenant_before_persistence(): void
    {
        $a = $this->pilot();
        $b = $this->anotherOrganization($a);
        $admin = User::factory()->create();
        $this->member($admin, $a);
        $this->allowManagement($admin);
        $this->actingTenant($admin, $a)->postJson('/invitations', ['email' => 'nuevo@sysforest.test', 'role' => 'OFFICIAL', 'organization_id' => $b->id])->assertUnprocessable();
        $this->assertDatabaseMissing('invitations', ['email' => 'nuevo@sysforest.test']);
    }

    public function test_invalid_action_links_render_without_leaking_supplied_values(): void
    {
        foreach (['/invitations/accept', '/reset-password', '/verify-email'] as $path) {
            $this->get($path.'?token=contenido-no-valido')->assertOk()->assertDontSee('contenido-no-valido');
        }
    }
}

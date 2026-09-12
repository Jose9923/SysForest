<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSecurityMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;

class InvitationTest extends PostgresTestCase
{
    private User $admin;
    private Organization $organization;
    private InvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Mail::fake();
        Bus::fake([SendSecurityMail::class]);
        $this->organization = $this->pilot();
        $this->admin = User::factory()->create(['active' => true]);
        $membership = $this->member($this->admin, $this->organization);
        app(RoleAssignmentRepository::class)->grantOrganization($membership, 'ORGANIZATION_ADMIN');
        app(TenantContext::class)->activate((int) $this->admin->id, $this->organization->id);
        $this->service = app(InvitationService::class);
    }

    public function test_new_invitation_normalizes_email_and_persists_only_a_hash(): void
    {
        $invitation = $this->invite(' Nuevo@Sysforest.test ');
        $token = $this->sentToken();
        $this->assertSame('nuevo@sysforest.test', $invitation->email);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertStringNotContainsString($token, $invitation->toJson());
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $this->assertSame($this->organization->id, $invitation->organization_id);
        $this->assertSame('organization', $invitation->scope);
    }

    public function test_new_account_acceptance_creates_verified_user_and_one_membership_with_role(): void
    {
        $invitation = $this->invite();
        $user = $this->service->accept($this->sentToken(), $this->newAccountInput());
        $this->assertSame('nuevo@sysforest.test', $user->email);
        $this->assertTrue($user->active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('BosqueSeguro!2026', $user->password));
        $membership = OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $this->organization->id)->sole();
        $this->assertTrue($membership->active);
        $this->assertSame('OFFICIAL', app(RoleAssignmentRepository::class)->organizationRole($membership)->role->name);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        Bus::assertDispatched(SendSecurityMail::class, fn (SendSecurityMail $job): bool => $job->kind === 'welcome');
    }

    public function test_existing_user_cannot_be_taken_over_by_presenting_an_invitation(): void
    {
        $user = User::factory()->create(['email' => 'existente@sysforest.test', 'active' => true]);
        $originalHash = $user->password;
        $invitation = $this->invite($user->email);
        try {
            $this->service->accept($this->sentToken(), $this->newAccountInput());
            $this->fail('Una cuenta existente requería autenticación previa.');
        } catch (ValidationException) {
            $this->assertSame($originalHash, $user->fresh()->password);
            $this->assertNull($invitation->fresh()->accepted_at);
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $user->id, 'organization_id' => $this->organization->id]);
        }
    }

    public function test_authenticated_existing_user_keeps_name_password_and_other_memberships(): void
    {
        $user = User::factory()->create(['email' => 'existente@sysforest.test', 'active' => true]);
        $other = $this->anotherOrganization($this->organization);
        $this->member($user, $other);
        $originalHash = $user->password;
        $originalName = $user->name;
        $this->invite($user->email);
        $accepted = $this->service->accept($this->sentToken(), $this->newAccountInput(), $user);
        $this->assertSame((int) $user->id, (int) $accepted->id);
        $this->assertSame($originalHash, $accepted->password);
        $this->assertSame($originalName, $accepted->name);
        $this->assertSame(2, OrganizationMembership::query()->where('user_id', $user->id)->count());
    }

    public function test_different_authenticated_account_cannot_accept_the_link(): void
    {
        $this->invite();
        $this->expectException(ValidationException::class);
        $this->service->accept($this->sentToken(), $this->newAccountInput(), $this->admin);
    }

    public function test_preview_does_not_consume_and_acceptance_is_single_use(): void
    {
        $invitation = $this->invite();
        $token = $this->sentToken();
        $this->assertSame($invitation->id, $this->service->preview($token)->id);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->service->accept($token, $this->newAccountInput());
        $this->expectException(ValidationException::class);
        $this->service->accept($token, $this->newAccountInput());
    }

    public function test_expired_invitation_cannot_create_a_user(): void
    {
        $invitation = $this->invite();
        $token = $this->sentToken();
        $this->travel(49)->hours();
        try {
            $this->service->accept($token, $this->newAccountInput());
            $this->fail('Se aceptó una invitación vencida.');
        } catch (ValidationException) {
            $this->assertNull($invitation->fresh()->accepted_at);
            $this->assertDatabaseMissing('users', ['email' => 'nuevo@sysforest.test']);
        }
    }

    public function test_national_and_department_roles_cannot_be_invited(): void
    {
        foreach (['SUPER_ADMIN', 'DEPARTMENT_ADMIN'] as $role) {
            try {
                $this->service->create($this->admin, ['email' => 'nuevo@sysforest.test', 'role' => $role]);
                $this->fail('Se aceptó un rol privilegiado fuera del ámbito organizacional.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('invitations', ['email' => 'nuevo@sysforest.test']);
            }
        }
    }

    public function test_official_has_no_invitation_permission_even_with_active_context(): void
    {
        $official = User::factory()->create(['active' => true]);
        app(RoleAssignmentRepository::class)->grantOrganization($this->member($official, $this->organization), 'OFFICIAL');
        app(TenantContext::class)->activate((int) $official->id, $this->organization->id);
        $this->expectException(AuthorizationException::class);
        $this->service->create($official, ['email' => 'nuevo@sysforest.test', 'role' => 'OFFICIAL']);
    }

    public function test_invitation_cannot_cross_a_forged_tenant_context(): void
    {
        $other = $this->anotherOrganization($this->organization);
        app(TenantContext::class)->activate((int) $this->admin->id, $other->id);
        $this->expectException(AuthorizationException::class);
        $this->invite();
    }

    public function test_author_permission_is_revalidated_at_acceptance(): void
    {
        $this->invite();
        $token = $this->sentToken();
        $membership = OrganizationMembership::query()->where('user_id', $this->admin->id)->where('organization_id', $this->organization->id)->sole();
        $membership->update(['active' => false, 'left_at' => now()]);
        $this->expectException(ValidationException::class);
        $this->service->accept($token, $this->newAccountInput());
    }

    public function test_disabled_organization_invalidates_pending_invitation(): void
    {
        $this->invite();
        $token = $this->sentToken();
        $this->organization->update(['active' => false]);
        $this->expectException(ValidationException::class);
        $this->service->accept($token, $this->newAccountInput());
    }

    public function test_old_invitation_cannot_reactivate_a_membership_added_then_disabled(): void
    {
        $user = User::factory()->create(['email' => 'existente@sysforest.test', 'active' => true]);
        $invitation = $this->invite($user->email);
        $membership = $this->member($user, $this->organization);
        app(RoleAssignmentRepository::class)->grantOrganization($membership, 'ORGANIZATION_ADMIN');
        $membership->update(['active' => false, 'left_at' => now()]);
        try {
            $this->service->accept($this->sentToken(), [], $user);
            $this->fail('La invitación reactivó una membresía desactivada.');
        } catch (ValidationException) {
            $this->assertFalse($membership->fresh()->active);
            $this->assertSame('ORGANIZATION_ADMIN', app(RoleAssignmentRepository::class)->organizationRole($membership)->role->name);
            $this->assertNull($invitation->fresh()->accepted_at);
        }
    }

    public function test_existing_membership_cannot_be_replaced_by_creating_an_invitation(): void
    {
        $this->expectException(ValidationException::class);
        $this->invite($this->admin->email);
    }

    public function test_weak_password_rejects_new_account_without_consuming_invitation(): void
    {
        $invitation = $this->invite();
        try {
            $this->service->accept($this->sentToken(), ['name' => 'Usuario', 'password' => 'corta', 'password_confirmation' => 'corta']);
            $this->fail('Se aceptó una contraseña débil.');
        } catch (ValidationException) {
            $this->assertNull($invitation->fresh()->accepted_at);
            $this->assertDatabaseMissing('users', ['email' => 'nuevo@sysforest.test']);
        }
    }

    private function invite(string $email = 'nuevo@sysforest.test'): Invitation
    {
        return $this->service->create($this->admin, ['email' => $email, 'role' => 'OFFICIAL']);
    }

    private function sentToken(): string
    {
        $token = '';
        Bus::assertDispatched(SendSecurityMail::class, function (SendSecurityMail $job) use (&$token): bool {
            if ($job->kind !== 'invitation') {
                return false;
            }
            parse_str((string) parse_url($job->data['action_url'], PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }

    private function newAccountInput(): array
    {
        return ['name' => 'Nueva Funcionaria', 'password' => 'BosqueSeguro!2026', 'password_confirmation' => 'BosqueSeguro!2026'];
    }
}

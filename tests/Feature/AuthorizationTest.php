<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DepartmentRoleAssignment;
use App\Models\NationalRoleAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\OrganizationMembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Repositories\DepartmentRepository;
use App\Repositories\MunicipalityRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\RoleRepository;
use App\Services\PermissionService;
use App\Services\RoleAssignmentService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;

class AuthorizationTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_authorization_catalog_is_idempotent_and_new_permissions_are_not_implicitly_granted(): void
    {
        $roles = Role::query()->orderBy('name')->pluck('id', 'name')->all();
        $permissions = Permission::query()->orderBy('code')->pluck('id', 'code')->all();
        $this->seed(AuthorizationSeeder::class);
        $this->assertSame($roles, Role::query()->orderBy('name')->pluck('id', 'name')->all());
        $this->assertSame($permissions, Permission::query()->orderBy('code')->pluck('id', 'code')->all());

        $actor = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);
        app(PermissionRepository::class)->upsert('future.ungranted', 'Permiso de prueba no concedido.');
        $this->assertFalse(app(PermissionService::class)->allowsNational($actor, 'future.ungranted'));
    }

    public function test_database_rejects_a_role_with_an_incompatible_scope(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        Role::query()->where('name', 'OFFICIAL')->update(['scope' => 'national']);
    }

    public function test_database_rejects_a_national_role_on_an_organization_assignment(): void
    {
        $organization = $this->pilot();
        $membership = $this->plainMembership($this->eligibleUser(), $organization);
        $role = app(RoleRepository::class)->findByName('SUPER_ADMIN');

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        OrganizationRoleAssignment::query()->create([
            'organization_id' => $organization->id, 'membership_id' => $membership->id,
            'role_id' => $role->id, 'scope' => 'organization', 'assigned_at' => now(),
        ]);
    }

    public function test_database_rejects_a_department_role_on_a_national_assignment(): void
    {
        $actor = $this->eligibleUser();
        $role = app(RoleRepository::class)->findByName('DEPARTMENT_ADMIN');

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        NationalRoleAssignment::query()->create([
            'user_id' => $actor->id, 'role_id' => $role->id, 'scope' => 'national', 'assigned_at' => now(),
        ]);
    }

    public function test_database_rejects_a_forged_assignment_scope_even_when_the_role_matches_it(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $role = app(RoleRepository::class)->findByName('SUPER_ADMIN');

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DepartmentRoleAssignment::query()->create([
            'user_id' => $actor->id, 'department_id' => $organization->municipality->department_id,
            'role_id' => $role->id, 'scope' => 'national', 'assigned_at' => now(),
        ]);
    }

    public function test_database_rejects_a_membership_from_a_different_organization(): void
    {
        $organizationA = $this->pilot();
        $organizationB = $this->anotherOrganization($organizationA);
        $membership = $this->plainMembership($this->eligibleUser(), $organizationB);
        $role = app(RoleRepository::class)->findByName('OFFICIAL');

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        OrganizationRoleAssignment::query()->create([
            'organization_id' => $organizationA->id, 'membership_id' => $membership->id,
            'role_id' => $role->id, 'scope' => 'organization', 'assigned_at' => now(),
        ]);
    }

    public function test_database_allows_only_one_current_role_per_membership(): void
    {
        $organization = $this->pilot();
        $membership = $this->plainMembership($this->eligibleUser(), $organization);
        app(RoleAssignmentRepository::class)->grantOrganization($membership, 'OFFICIAL');
        $role = app(RoleRepository::class)->findByName('ORGANIZATION_ADMIN');

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        OrganizationRoleAssignment::query()->create([
            'organization_id' => $organization->id, 'membership_id' => $membership->id,
            'role_id' => $role->id, 'scope' => 'organization', 'assigned_at' => now(),
        ]);
    }

    public function test_database_rejects_an_active_assignment_with_a_revocation_date(): void
    {
        $actor = $this->eligibleUser();
        $assignment = app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $assignment->update(['active' => true, 'revoked_at' => now()]);
    }

    public function test_superadmin_accesses_organizations_without_fabricated_memberships(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);

        $this->assertTrue(app(PermissionService::class)->allowsOrganization($actor, 'organizations.manage', $organization->id));
        $tenants = app(TenantContextService::class);
        $tenants->activate($actor, $organization->id);
        $this->assertSame('national', app(TenantContext::class)->accessMode());
        $this->assertSame($organization->id, $tenants->currentOrganization($actor)->id);
        $this->assertTrue(app(OrganizationPolicy::class)->update($actor, $organization));
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    public function test_national_context_does_not_claim_to_have_a_membership(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);
        app(TenantContextService::class)->activate($actor, $organization->id);

        try {
            app(TenantContextService::class)->assertCurrentMembership($actor);
            $this->fail('Una concesión nacional no es una membresía.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        }
    }

    public function test_department_access_is_read_only_and_cannot_cross_its_department(): void
    {
        $organizationA = $this->pilot();
        $country = $organizationA->municipality->department->country;
        $departmentB = app(DepartmentRepository::class)->upsert($country->id, 'RBAC-DEP', ['name' => 'Departamento RBAC']);
        $municipalityB = app(MunicipalityRepository::class)->upsert($departmentB->id, 'RBAC-MUN', ['name' => 'Municipio RBAC']);
        $organizationB = $this->anotherOrganization($organizationA);
        $organizationB->update(['municipality_id' => $municipalityB->id]);
        $actor = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $organizationA->municipality->department_id);
        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->allowsDepartment($actor, 'organizations.view', $organizationA->municipality->department_id));
        $this->assertFalse($permissions->allowsDepartment($actor, 'organizations.view', $departmentB->id));
        $this->assertFalse($permissions->allowsOrganization($actor, 'organizations.manage', $organizationA->id));
        app(TenantContextService::class)->activate($actor, $organizationA->id);
        $this->assertSame('department_read', app(TenantContext::class)->accessMode());
        $this->assertTrue(app(OrganizationPolicy::class)->view($actor, $organizationA));
        $this->assertFalse(app(OrganizationPolicy::class)->update($actor, $organizationA));

        try {
            app(TenantContextService::class)->activate($actor, $organizationB->id);
            $this->fail('El departamento no autoriza otra jurisdicción.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }
    }

    public function test_different_organization_roles_do_not_mix_permissions(): void
    {
        $organizationA = $this->pilot();
        $organizationB = $this->anotherOrganization($organizationA);
        $actor = $this->eligibleUser();
        $assignments = app(RoleAssignmentRepository::class);
        $assignments->grantOrganization($this->plainMembership($actor, $organizationA), 'ORGANIZATION_ADMIN');
        $assignments->grantOrganization($this->plainMembership($actor, $organizationB), 'OFFICIAL');
        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->allowsOrganization($actor, 'organizations.manage', $organizationA->id));
        $this->assertTrue($permissions->allowsOrganization($actor, 'organizations.view', $organizationB->id));
        $this->assertFalse($permissions->allowsOrganization($actor, 'organizations.manage', $organizationB->id));
        $this->assertFalse($permissions->allowsOrganization($actor, 'memberships.view', $organizationB->id));
        $this->assertFalse($permissions->allowsNational($actor, 'users.manage'));
    }

    public function test_official_cannot_open_membership_administration_even_for_their_own_membership(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $membership = $this->plainMembership($actor, $organization);
        app(RoleAssignmentRepository::class)->grantOrganization($membership, 'OFFICIAL');
        app(TenantContextService::class)->activate($actor, $organization->id);

        $policy = app(OrganizationMembershipPolicy::class);
        $this->assertFalse($policy->view($actor, $membership));
        $this->assertFalse($policy->update($actor, $membership));
        $this->assertFalse($policy->deactivate($actor, $membership));
    }

    public function test_membership_without_a_role_does_not_activate_a_workspace(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $this->plainMembership($actor, $organization);

        $this->expectException(AuthorizationException::class);
        app(TenantContextService::class)->activate($actor, $organization->id);
    }

    public function test_global_account_state_is_read_fresh_for_every_permission_decision(): void
    {
        $actor = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);
        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->allowsNational($actor, 'users.manage'));

        User::query()->whereKey($actor->id)->update(['active' => false]);
        $this->assertFalse($permissions->allowsNational($actor, 'users.manage'));
        User::query()->whereKey($actor->id)->update(['active' => true, 'email_verified_at' => null]);
        $this->assertFalse($permissions->allowsNational($actor, 'users.manage'));
    }

    public function test_role_revocation_invalidates_an_existing_context(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $membership = $this->plainMembership($actor, $organization);
        $assignment = app(RoleAssignmentRepository::class)->grantOrganization($membership, 'OFFICIAL');
        app(TenantContextService::class)->activate($actor, $organization->id);
        $assignment->update(['active' => false, 'revoked_at' => now()]);

        try {
            app(TenantContextService::class)->assertCurrentAccess($actor);
            $this->fail('Una asignación revocada no debe conservar el contexto.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }
    }

    public function test_disabling_membership_a_preserves_account_and_membership_b_access(): void
    {
        $organizationA = $this->pilot();
        $organizationB = $this->anotherOrganization($organizationA);
        $actor = $this->eligibleUser();
        $membershipA = $this->plainMembership($actor, $organizationA);
        $membershipB = $this->plainMembership($actor, $organizationB);
        $assignments = app(RoleAssignmentRepository::class);
        $assignments->grantOrganization($membershipA, 'ORGANIZATION_ADMIN');
        $assignments->grantOrganization($membershipB, 'OFFICIAL');
        app(OrganizationMembershipRepository::class)->update($membershipA, ['active' => false, 'left_at' => now()]);

        $this->assertTrue((bool) $actor->fresh()->active);
        $this->assertFalse(app(PermissionService::class)->allowsOrganization($actor, 'organizations.view', $organizationA->id));
        $this->assertTrue(app(PermissionService::class)->allowsOrganization($actor, 'organizations.view', $organizationB->id));
        $this->assertSame($organizationB->id, app(TenantContextService::class)->activate($actor, $organizationB->id)->id);
    }

    public function test_organization_admin_cannot_assign_a_national_role(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $target = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantOrganization($this->plainMembership($actor, $organization), 'ORGANIZATION_ADMIN');

        $this->expectException(AuthorizationException::class);
        app(RoleAssignmentService::class)->assignNational($actor, $target);
    }

    public function test_organization_admin_cannot_assign_a_department_role(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $target = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantOrganization($this->plainMembership($actor, $organization), 'ORGANIZATION_ADMIN');

        $this->expectException(AuthorizationException::class);
        app(RoleAssignmentService::class)->assignDepartment($actor, $target, $organization->municipality->department_id);
    }

    public function test_organization_assignment_rejects_a_known_membership_from_another_tenant(): void
    {
        $organizationA = $this->pilot();
        $organizationB = $this->anotherOrganization($organizationA);
        $actor = $this->eligibleUser();
        $target = $this->plainMembership($this->eligibleUser(), $organizationB);
        app(RoleAssignmentRepository::class)->grantOrganization($this->plainMembership($actor, $organizationA), 'ORGANIZATION_ADMIN');
        app(TenantContextService::class)->activate($actor, $organizationA->id);

        $this->expectException(AuthorizationException::class);
        app(RoleAssignmentService::class)->assignOrganization($actor, $target, 'ORGANIZATION_ADMIN');
    }

    public function test_a_national_role_cannot_be_smuggled_through_organization_assignment(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $target = $this->plainMembership($this->eligibleUser(), $organization);
        $assignments = app(RoleAssignmentRepository::class);
        $assignments->grantOrganization($this->plainMembership($actor, $organization), 'ORGANIZATION_ADMIN');
        $assignments->grantOrganization($target, 'OFFICIAL');
        app(TenantContextService::class)->activate($actor, $organization->id);

        try {
            app(RoleAssignmentService::class)->assignOrganization($actor, $target, 'SUPER_ADMIN');
            $this->fail('El rol nacional debía rechazarse.');
        } catch (ValidationException) {
            $this->assertSame('OFFICIAL', $assignments->organizationRole($target)->role->name);
        }
    }

    public function test_organization_admin_can_assign_an_allowed_role_within_the_same_tenant(): void
    {
        $organization = $this->pilot();
        $actor = $this->eligibleUser();
        $targetUser = $this->eligibleUser();
        $target = $this->plainMembership($targetUser, $organization);
        $assignments = app(RoleAssignmentRepository::class);
        $assignments->grantOrganization($this->plainMembership($actor, $organization), 'ORGANIZATION_ADMIN');
        $original = $assignments->grantOrganization($target, 'OFFICIAL');
        app(TenantContextService::class)->activate($actor, $organization->id);
        $updated = app(RoleAssignmentService::class)->assignOrganization($actor, $target, 'ORGANIZATION_ADMIN');

        $this->assertSame($original->id, $updated->id);
        $this->assertTrue(app(PermissionService::class)->allowsOrganization($targetUser, 'memberships.view', $organization->id));
        $this->assertFalse(app(PermissionService::class)->allowsNational($targetUser, 'users.manage'));
    }

    public function test_context_still_rejects_a_different_target_even_for_national_permissions(): void
    {
        $organizationA = $this->pilot();
        $organizationB = $this->anotherOrganization($organizationA);
        $actor = $this->eligibleUser();
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);
        app(TenantContextService::class)->activate($actor, $organizationA->id);

        $this->assertTrue(app(OrganizationPolicy::class)->update($actor, $organizationA));
        $this->assertFalse(app(OrganizationPolicy::class)->view($actor, $organizationB));
        $this->assertFalse(app(OrganizationPolicy::class)->update($actor, $organizationB));
    }

    private function eligibleUser(): User
    {
        return User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    }

    /** Se evita que un helper compartido conceda roles por defecto a estos casos de autorización. */
    private function plainMembership(User $actor, Organization $organization): OrganizationMembership
    {
        return app(OrganizationMembershipRepository::class)->upsert(
            (int) $actor->id,
            $organization->id,
            ['active' => true, 'joined_at' => now(), 'left_at' => null],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Middleware\UseTenantContext;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationMembershipRepository;
use App\Services\OrganizationMembershipService;
use App\Services\OrganizationService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\RoleRepository;
use App\Services\PermissionService;
use RuntimeException;
use Tests\PostgresTestCase;

class TenantIsolationTest extends PostgresTestCase
{
    private Organization $organizationA;
    private Organization $organizationB;
    private User $actorA;
    private User $actorB;
    private \App\Models\OrganizationMembership $membershipB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizationA = $this->pilot();
        $this->organizationB = $this->anotherOrganization($this->organizationA);
        $this->actorA = User::factory()->create();
        $this->actorB = User::factory()->create();
        $this->member($this->actorA, $this->organizationA);
        $this->membershipB = $this->member($this->actorB, $this->organizationB);
        $this->allowManagement($this->actorA);
    }

    public function test_context_cannot_activate_an_unrelated_organization(): void
    {
        $service = app(TenantContextService::class);
        $service->activate($this->actorA, $this->organizationA->id);
        try {
            $service->activate($this->actorA, $this->organizationB->id);
            $this->fail('El tenant ajeno fue aceptado.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }
    }

    public function test_repository_cannot_resolve_a_membership_from_another_tenant(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(OrganizationMembershipRepository::class)->findForTenant($this->organizationA->id, $this->membershipB->id);
    }

    public function test_service_cannot_modify_another_tenant_even_with_management_permission(): void
    {
        app(TenantContextService::class)->activate($this->actorA, $this->organizationA->id);
        try {
            app(OrganizationMembershipService::class)->setActive($this->actorA, $this->membershipB->id, false);
            $this->fail('Se modificó una membresía ajena.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($this->membershipB->refresh()->active);
            $this->assertNull($this->membershipB->left_at);
        }
    }

    public function test_http_cannot_read_or_update_organization_b_from_context_a(): void
    {
        $this->actingTenant($this->actorA, $this->organizationA);
        $this->get('/organizations/'.$this->organizationB->id)->assertNotFound();
        $this->put('/organizations/'.$this->organizationB->id, $this->organizationInput($this->organizationA))->assertNotFound();
        $this->assertSame($this->organizationB->name, $this->organizationB->fresh()->name);
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_http_membership_idor_leaves_other_tenant_unchanged(): void
    {
        $this->actingTenant($this->actorA, $this->organizationA);
        $this->getJson('/memberships/'.$this->membershipB->id)->assertNotFound();
        $this->patchJson('/memberships/'.$this->membershipB->id, ['active' => false])->assertNotFound();
        $this->assertTrue($this->membershipB->refresh()->active);
        $this->assertSame($this->organizationB->id, $this->membershipB->organization_id);
    }

    public function test_forged_session_tenant_and_forged_selection_are_rejected(): void
    {
        $this->actingTenant($this->actorA, $this->organizationB);
        $this->get('/organizations/'.$this->organizationB->id)->assertForbidden();
        $this->post('/active-organization', ['organization_id' => $this->organizationB->id])->assertForbidden();
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_request_organization_id_is_not_authority_for_updates(): void
    {
        $this->actingTenant($this->actorA, $this->organizationA);
        $input = $this->organizationInput($this->organizationA);
        $input['organization_id'] = $this->organizationB->id;
        $this->putJson('/organizations/'.$this->organizationA->id, $input)
            ->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->assertSame('INEM-VVC', $this->organizationA->fresh()->code);
    }

    public function test_missing_context_fails_closed(): void
    {
        $this->member($this->actorA, $this->organizationB);
        $this->actingAs($this->actorA)->get('/organizations/'.$this->organizationA->id)->assertForbidden();
    }

    public function test_revoked_membership_is_rechecked_before_service_operation(): void
    {
        $tenants = app(TenantContextService::class);
        $tenants->activate($this->actorA, $this->organizationA->id);
        $membership = app(OrganizationMembershipRepository::class)->activeFor((int) $this->actorA->id, $this->organizationA->id);
        app(OrganizationMembershipRepository::class)->update($membership, ['active' => false, 'left_at' => now()]);
        $this->expectException(AuthorizationException::class);
        app(OrganizationService::class)->update($this->actorA, $this->organizationA->id, $this->organizationInput($this->organizationA));
    }

    public function test_inactive_and_soft_deleted_organizations_cannot_be_active_contexts(): void
    {
        $this->organizationA->update(['active' => false]);
        $this->actingTenant($this->actorA, $this->organizationA)->get('/organizations/'.$this->organizationA->id)->assertForbidden();
        $this->organizationA->update(['active' => true]);
        $this->organizationA->delete();
        $this->get('/organizations/'.$this->organizationA->id)->assertForbidden();
    }

    public function test_context_cannot_be_reused_by_a_different_actor(): void
    {
        app(TenantContextService::class)->activate($this->actorA, $this->organizationA->id);
        $this->expectException(AuthorizationException::class);
        app(OrganizationService::class)->show($this->actorB, $this->organizationA->id);
    }

    public function test_tenant_job_middleware_revalidates_and_clears_on_exception(): void
    {
        $middleware = new UseTenantContext((int) $this->actorA->id, $this->organizationA->id);
        try {
            $middleware->handle(new \stdClass(), function (): void {
                $this->assertSame($this->organizationA->id, app(TenantContext::class)->organizationId());
                throw new RuntimeException('Fallo simulado del job.');
            });
            $this->fail('El job debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado del job.', $exception->getMessage());
        }
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_missing_job_actor_does_not_leave_a_previous_context(): void
    {
        app(TenantContextService::class)->activate($this->actorA, $this->organizationA->id);
        try {
            (new UseTenantContext(PHP_INT_MAX, $this->organizationA->id))->handle(new \stdClass(), fn () => true);
            $this->fail('El actor inexistente debía rechazarse.');
        } catch (ModelNotFoundException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }
    }

    public function test_national_reads_require_explicit_permission_and_do_not_grant_writes(): void
    {
        $service = app(OrganizationService::class);
        try {
            $service->nationalRead($this->actorA);
            $this->fail('La consulta nacional debía estar denegada.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $reader = User::factory()->create();
        $this->allowNationalManagement($reader);
        $role = app(RoleRepository::class)->findByName('SUPER_ADMIN');
        // Se reduce el permiso real del fixture, sin sustituir Gates ni alterar producción.
        app(RoleRepository::class)->syncPermissions($role, [
            $role->permissions->firstWhere('code', 'organizations.view')->id,
        ]);
        $ids = $service->nationalRead($reader)->getCollection()->modelKeys();
        $this->assertContains($this->organizationA->id, $ids);
        $this->assertContains($this->organizationB->id, $ids);
        $this->assertFalse(app(PermissionService::class)->allowsOrganization($reader, 'organizations.manage', $this->organizationA->id));
        $this->assertSame(0, $reader->memberships()->count());
        app(TenantContextService::class)->activate($reader, $this->organizationA->id);
        $this->expectException(AuthorizationException::class);
        $service->update($reader, $this->organizationA->id, $this->organizationInput($this->organizationA));
    }

    public function test_department_reads_are_explicit_and_restricted_to_the_requested_department(): void
    {
        $department = $this->organizationA->municipality->department;
        $country = $department->country;
        $otherDepartment = app(\App\Repositories\DepartmentRepository::class)->upsert($country->id, 'TEST', ['name' => 'Departamento de prueba']);
        $otherMunicipality = app(\App\Repositories\MunicipalityRepository::class)->upsert($otherDepartment->id, 'TEST', ['name' => 'Municipio de prueba']);
        $other = $this->anotherOrganization($this->organizationA);
        $other->update(['municipality_id' => $otherMunicipality->id]);
        $service = app(OrganizationService::class);
        try {
            $service->departmentRead($this->actorA, $department->id);
            $this->fail('La consulta departamental debía estar denegada.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $reader = User::factory()->create();
        app(RoleAssignmentRepository::class)->grantDepartment((int) $reader->id, $department->id);
        $ids = $service->departmentRead($reader, $department->id)->getCollection()->modelKeys();
        $this->assertContains($this->organizationA->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertFalse(app(PermissionService::class)->allowsOrganization($reader, 'organizations.manage', $this->organizationA->id));
        $this->assertSame(0, $reader->memberships()->count());
        $this->expectException(AuthorizationException::class);
        $service->departmentRead($reader, $otherDepartment->id);
    }

    public function test_multi_organization_user_must_switch_context_explicitly(): void
    {
        $this->member($this->actorA, $this->organizationB);
        $this->actingTenant($this->actorA, $this->organizationA);
        $this->get('/organizations/'.$this->organizationB->id)->assertNotFound();
        $this->post('/active-organization', ['organization_id' => $this->organizationB->id])
            ->assertRedirect(route('organizations.show', $this->organizationB->id));
        $this->get('/organizations/'.$this->organizationB->id)->assertOk();
    }

    public function test_membership_alone_does_not_grant_administration(): void
    {
        $this->actingTenant($this->actorB, $this->organizationB);
        $this->get('/organizations/'.$this->organizationB->id.'/edit')->assertForbidden();
        $this->patchJson('/memberships/'.$this->membershipB->id, ['active' => false])->assertForbidden();
        $this->assertTrue($this->membershipB->refresh()->active);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Department;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\EvidenceService;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Services\TreeLifecycleService;
use App\Services\TreeService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\EvidenceTestCase;

class EvidenceAuthorizationTest extends EvidenceTestCase
{
    #[DataProvider('roles')]
    public function test_four_roles_have_only_their_approved_http_operations(string $role, bool $write, bool $restore): void
    {
        $evidence = $this->attachEvidence();
        $actor = $this->actorFor($role);
        $this->actingTenant($actor, $this->organization);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        $upload = $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput());
        $upload->assertStatus($write ? 201 : 403);
        $update = $this->putJson(route('evidences.update', $evidence->id), [
            'lock_version' => 1, 'evidence_type_id' => $this->evidenceType('EVD_CROWN')->id, 'caption' => 'Corrección institucional',
        ]);
        $update->assertStatus($write ? 302 : 403);
        $void = $this->deleteJson(route('evidences.void', $evidence->id), ['lock_version' => $write ? 2 : 1]);
        $void->assertStatus($write ? 302 : 403);
        $restoration = $this->postJson(route('evidences.restore', $evidence->id), ['lock_version' => $write ? 3 : 1]);
        $restoration->assertStatus($restore ? 302 : ($write ? 404 : 403));
        $current = Evidence::withTrashed()->findOrFail($evidence->id);
        $this->assertSame($this->admin->id, $current->uploaded_by);
        $this->assertSame($write && !$restore, $current->trashed());
        Storage::disk('evidences')->assertExists($current->path);
    }

    public static function roles(): array
    {
        return [
            ['SUPER_ADMIN', true, true], ['DEPARTMENT_ADMIN', false, false],
            ['ORGANIZATION_ADMIN', true, true], ['OFFICIAL', true, false],
        ];
    }

    public function test_account_without_membership_cannot_forge_the_selected_organization(): void
    {
        $evidence = $this->attachEvidence();
        $actor = User::factory()->create();
        $this->actingTenant($actor, $this->organization);
        $this->getJson(route('evidences.view', $evidence->id))->assertForbidden();
        $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput())->assertForbidden();
        $this->assertDatabaseCount('evidences', 1);
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_superadmin_still_requires_explicit_tenant_selection(): void
    {
        $evidence = $this->attachEvidence();
        $actor = $this->actorFor('SUPER_ADMIN');
        $this->actingAs($actor);
        $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput())->assertForbidden();
        $this->putJson(route('evidences.update', $evidence->id), [
            'lock_version' => 1, 'evidence_type_id' => $evidence->evidence_type_id, 'caption' => 'Sin contexto',
        ])->assertForbidden();
        $this->assertSame(1, $evidence->fresh()->lock_version);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        $this->assertDatabaseCount('evidences', 1);
    }

    public function test_department_admin_cannot_reach_a_foreign_department_by_uuid_or_session(): void
    {
        $other = $this->organizationInAnotherDepartment();
        $this->member($this->admin, $other);
        $this->allowManagement($this->admin);
        app(TenantContextService::class)->activate($this->admin, $other->id);
        $inventory = app(InventoryService::class)->create($this->admin, [
            'code' => 'EVIDENCE-FOREIGN', 'name' => 'Campaña ajena', 'status' => 'active', 'active' => true,
        ]);
        $tree = app(TreeService::class)->create($this->admin, ['inventory_ids' => [$inventory->id], 'current_status' => 'active']);
        $foreign = app(EvidenceService::class)->upload($this->admin, $tree->id, null, $this->evidenceInput())->sole();
        $actor = $this->actorFor('DEPARTMENT_ADMIN');

        $this->actingTenant($actor, $this->organization)
            ->getJson(route('evidences.view', $foreign->id))->assertNotFound();
        $this->actingTenant($actor, $other)
            ->getJson(route('evidences.view', $foreign->id))->assertForbidden();
        $this->postJson(route('trees.evidences.store', $tree->id), $this->evidenceInput())->assertForbidden();
        $this->assertFalse(app(TenantContext::class)->isActive());
        Storage::disk('evidences')->assertExists($foreign->path);
    }

    #[DataProvider('ownerKinds')]
    public function test_annulled_root_tree_rejects_upload_even_through_an_inspection(string $kind): void
    {
        $inspection = $this->capture();
        $evidence = $this->attachEvidence(inspectionId: $kind === 'inspection' ? $inspection->id : null);
        $this->annulTree();
        $this->actingTenant($this->admin, $this->organization);
        $route = $kind === 'tree'
            ? route('trees.evidences.store', $this->tree->id)
            : route('inspections.evidences.store', $inspection->id);
        $this->postJson($route, $this->evidenceInput())->assertForbidden();
        $this->assertDatabaseCount('evidences', 1);
        $this->assertDatabaseCount('evidence_revisions', 1);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    public static function ownerKinds(): array
    {
        return [['tree'], ['inspection']];
    }

    public function test_restoring_tree_keeps_active_evidence_and_does_not_restore_independently_voided_evidence(): void
    {
        $active = $this->attachEvidence(['caption' => 'ACTIVA-SIN-ANULAR-FORESTAL']);
        $voided = $this->attachEvidence(['caption' => 'ANULADA-INDEPENDIENTE-FORESTAL']);
        app(EvidenceService::class)->void($this->admin, $voided->id, ['lock_version' => 1]);
        $this->annulTree();
        $this->actingTenant($this->admin, $this->organization);
        $this->postJson(route('trees.restore', $this->tree->id), [
            'lock_version' => DB::table('trees')->where('id', $this->tree->id)->value('lock_version'),
        ])->assertRedirect();
        $this->assertFalse(Evidence::withTrashed()->findOrFail($active->id)->trashed());
        $this->assertTrue(Evidence::withTrashed()->findOrFail($voided->id)->trashed());
        $this->get(route('trees.evidences.index', $this->tree->id))->assertOk()
            ->assertSee('ACTIVA-SIN-ANULAR-FORESTAL')->assertDontSee('ANULADA-INDEPENDIENTE-FORESTAL');
        Storage::disk('evidences')->assertExists([$active->path, $voided->path]);
    }

    public function test_officials_collaborate_on_evidence_without_an_active_campaign(): void
    {
        app(InventoryService::class)->update($this->admin, $this->inventory->id, [
            'code' => $this->inventory->code, 'name' => $this->inventory->name,
            'status' => 'closed', 'active' => false, 'lock_version' => 1,
        ]);
        $author = $this->actorFor('OFFICIAL');
        $colleague = $this->actorFor('OFFICIAL');
        app(TenantContextService::class)->activate($author, $this->organization->id);
        $evidence = $this->attachEvidence(actor: $author);
        $this->actingTenant($colleague, $this->organization);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        $this->putJson(route('evidences.update', $evidence->id), [
            'lock_version' => 1, 'evidence_type_id' => $this->evidenceType('EVD_CROWN')->id,
            'caption' => 'Corrección colaborativa fuera de campaña',
        ])->assertRedirect();
        $this->assertSame($author->id, $evidence->fresh()->uploaded_by);
        $this->assertSame($colleague->id, $evidence->fresh()->updated_by);
        $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput())->assertCreated();
        $this->deleteJson(route('evidences.void', $evidence->id), ['lock_version' => 2])->assertRedirect();
        $this->assertTrue(Evidence::withTrashed()->findOrFail($evidence->id)->trashed());
    }

    #[DataProvider('ownerKinds')]
    public function test_evidence_permission_does_not_bypass_revoked_permission_to_its_owner(string $kind): void
    {
        $inspection = $this->capture();
        $evidence = $this->attachEvidence(inspectionId: $kind === 'inspection' ? $inspection->id : null);
        $actor = $this->actorFor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        $role = Role::query()->where('name', 'OFFICIAL')->sole();
        $role->permissions()->detach(Permission::query()->where('code', 'trees.view')->value('id'));
        $this->assertTrue($role->permissions()->where('code', 'evidences.view')->exists());
        $this->getJson(route('evidences.view', $evidence->id))->assertForbidden();
        $this->getJson(route('evidences.download', $evidence->id))->assertForbidden();
        $this->putJson(route('evidences.update', $evidence->id), [
            'lock_version' => 1, 'evidence_type_id' => $evidence->evidence_type_id,
        ])->assertForbidden();
        $this->assertSame(1, $evidence->fresh()->lock_version);
    }

    #[DataProvider('roles')]
    public function test_voided_original_is_delivered_only_to_roles_allowed_to_restore(string $role, bool $write, bool $restore): void
    {
        $evidence = $this->attachEvidence();
        app(EvidenceService::class)->void($this->admin, $evidence->id, ['lock_version' => 1]);
        $actor = $this->actorFor($role);
        $this->actingTenant($actor, $this->organization);
        foreach (['view', 'download'] as $route) {
            $response = $this->getJson(route('evidences.'.$route, $evidence->id));
            $response->assertStatus($restore ? 200 : 404);
            if ($restore) {
                $this->assertSame($evidence->checksum_sha256, hash('sha256', $response->streamedContent()));
            }
        }
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    public function test_membership_revocation_blocks_a_previously_authenticated_file_request(): void
    {
        $evidence = $this->attachEvidence();
        $actor = User::factory()->create();
        $membership = $this->member($actor, $this->organization);
        $this->actingTenant($actor, $this->organization);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        $membership->forceFill(['active' => false, 'left_at' => now()])->save();
        $this->getJson(route('evidences.view', $evidence->id))->assertForbidden();
        $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput())->assertForbidden();
        $this->assertDatabaseCount('evidences', 1);
    }

    private function actorFor(string $role): User
    {
        $actor = User::factory()->create();
        if ($role === 'SUPER_ADMIN') {
            $this->allowNationalManagement($actor);
        } elseif ($role === 'DEPARTMENT_ADMIN') {
            app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $this->organization->municipality->department_id);
        } else {
            $membership = $this->member($actor, $this->organization);
            app(RoleAssignmentRepository::class)->grantOrganization($membership, $role);
        }

        return $actor;
    }

    private function annulTree(): void
    {
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => DB::table('trees')->where('id', $this->tree->id)->value('lock_version'),
            'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
    }

    private function organizationInAnotherDepartment(): Organization
    {
        $department = Department::query()->create([
            'country_id' => $this->organization->municipality->department->country_id,
            'code' => 'EVD-ALT', 'name' => 'Departamento de evidencia ajena', 'active' => true,
        ]);
        $municipality = Municipality::query()->create([
            'department_id' => $department->id, 'code' => 'EVD-ALT', 'name' => 'Municipio ajeno', 'active' => true,
        ]);

        return app(OrganizationRepository::class)->create([
            'municipality_id' => $municipality->id, 'type' => 'other', 'name' => 'Organización de evidencia ajena',
            'code' => 'EVIDENCE-DEPARTMENT', 'tree_code_prefix' => 'EVIDENCE-DEPARTMENT', 'active' => true,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FaunaCategory;
use App\Models\OrganizationMembership;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\TreeInspectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\InspectionTestCase;

class TreeInspectionHttpSecurityTest extends InspectionTestCase
{
    public function test_client_derived_values_are_ignored_and_backend_persists_real_calculations(): void
    {
        $input = $this->inspectionInput() + ['dap_m' => '999', 'radius_m' => '999', 'basal_area_m2' => '999',
            'total_volume_m3' => '999', 'commercial_volume_m3' => '999', 'diameter_class_number' => 999,
            'height_class_number' => 999, 'maturity_state_id' => (string) Str::uuid(),
            'classification_rule_version_id' => (string) Str::uuid(), 'calculation_rule_version' => 'FALSE'];
        $this->actingTenant($this->admin, $this->organization)
            ->post(route('trees.inspections.store', $this->tree->id), $input)->assertRedirect();
        $inspection = TreeInspection::query()->sole();
        $this->assertEqualsWithDelta(0.490197224723, (float) $inspection->dap_m, 0.000000000001);
        $this->assertEqualsWithDelta(0.188726, (float) $inspection->basal_area_m2, 0.000001);
        $this->assertEqualsWithDelta(1.132356, (float) $inspection->total_volume_m3, 0.000001);
        $this->assertSame(10, $inspection->diameter_class_number);
        $this->assertSame(2, $inspection->height_class_number);
        $this->assertSame('DENDROMETRY_V1', $inspection->calculation_rule_version);
        $this->assertSame($this->admin->id, $inspection->created_by);
        $this->assertDatabaseCount('inspection_revisions', 1);
    }

    #[DataProvider('foreignActions')]
    public function test_official_b_cannot_read_or_write_inspection_or_tree_a(string $action): void
    {
        $inspection = $this->capture();
        $other = $this->anotherOrganization();
        $outsider = User::factory()->create();
        $this->member($outsider, $other);
        $this->actingTenant($outsider, $other);
        $response = match ($action) {
            'create_form' => $this->get(route('trees.inspections.create', $this->tree->id)),
            'create' => $this->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput()),
            'show' => $this->get(route('inspections.show', $inspection->id)),
            'edit' => $this->get(route('inspections.edit', $inspection->id)),
            'update' => $this->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, 'cap_cm' => '200']),
            'preview' => $this->postJson(route('dendrometry.preview'), ['inspection_id' => $inspection->id, 'cap_cm' => '200']),
        };
        $response->assertNotFound();
        $this->assertDatabaseCount('tree_inspections', 1);
        $this->assertSame(1, $inspection->fresh()->lock_version);
    }

    public static function foreignActions(): array
    {
        return [['create_form'], ['create'], ['show'], ['edit'], ['update'], ['preview']];
    }

    #[DataProvider('departmentWrites')]
    public function test_department_admin_is_read_only_in_own_department(string $action): void
    {
        $inspection = $this->capture();
        $department = User::factory()->create();
        app(RoleAssignmentRepository::class)->grantDepartment((int) $department->id, $this->organization->municipality->department_id);
        $this->actingTenant($department, $this->organization);
        $response = match ($action) {
            'create_form' => $this->get(route('trees.inspections.create', $this->tree->id)),
            'create' => $this->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput()),
            'edit' => $this->get(route('inspections.edit', $inspection->id)),
            'update' => $this->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, 'cap_cm' => '200']),
            'preview' => $this->postJson(route('dendrometry.preview'), ['cap_cm' => '154']),
        };
        $response->assertForbidden();
        $this->assertSame(1, $inspection->fresh()->lock_version);
    }

    public static function departmentWrites(): array
    {
        return [['create_form'], ['create'], ['edit'], ['update'], ['preview']];
    }

    public function test_super_admin_can_capture_in_selected_tenant_without_fake_membership(): void
    {
        $national = User::factory()->create();
        $this->allowNationalManagement($national);
        $this->actingTenant($national, $this->organization)
            ->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput())->assertRedirect();
        $this->assertFalse(OrganizationMembership::query()->where('user_id', $national->id)->exists());
        $this->assertDatabaseHas('tree_inspections', ['organization_id' => $this->organization->id, 'created_by' => $national->id]);
    }

    public function test_official_can_create_and_correct_through_real_jwt_http(): void
    {
        $official = User::factory()->create();
        $this->member($official, $this->organization);
        $this->actingTenant($official, $this->organization)
            ->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput())->assertRedirect();
        $inspection = TreeInspection::query()->sole();
        $this->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, 'cap_cm' => '180'])->assertRedirect();
        $this->assertSame('180.000000', $inspection->fresh()->cap_cm);
        $this->assertSame($official->id, $inspection->fresh()->updated_by);
    }

    public function test_clearing_all_checkboxes_removes_relations_and_keeps_an_audit_revision(): void
    {
        $inspection = $this->capture(['physical_condition_ids' => [$this->condition('PHY_STEM_STRAIGHT')->id],
            'phytosanitary_condition_ids' => [$this->condition('PHT_FOLIAGE_CHLOROSIS')->id],
            'fauna_category_ids' => [FaunaCategory::query()->firstOrFail()->id]]);
        $this->actingTenant($this->admin, $this->organization)
            ->put(route('inspections.update', $inspection->id), $this->inspectionInput() + ['lock_version' => 1])->assertRedirect();
        $this->assertDatabaseCount('inspection_conditions', 0);
        $this->assertDatabaseCount('inspection_fauna', 0);
        $revision = DB::table('inspection_revisions')->where('version', 2)->first();
        $this->assertCount(2, json_decode($revision->old_values, true)['conditions']);
        $this->assertSame([], json_decode($revision->new_values, true)['conditions']);
    }

    public function test_raw_phenology_and_observations_survive_http_without_invented_states(): void
    {
        $data = $this->inspectionInput() + ['flowering_development' => "  Sin escala: botón\n  observado.  ",
            'fruiting_date' => '2026-07-01', 'fauna_observations' => '  Registro abierto.  ', 'general_observations' => '  Texto CP.  '];
        $this->actingTenant($this->admin, $this->organization)
            ->post(route('trees.inspections.store', $this->tree->id), $data)->assertRedirect();
        $inspection = TreeInspection::query()->sole();
        foreach (['flowering_development', 'fauna_observations', 'general_observations'] as $field) {
            $this->assertSame($data[$field], $inspection->$field);
        }
        $this->assertNull($inspection->flowering_date);
        $this->assertSame('2026-07-01', $inspection->fruiting_date->format('Y-m-d'));
    }

    public function test_stale_http_correction_returns_specific_conflict_message(): void
    {
        $inspection = $this->capture();
        app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'cap_cm' => '200']);
        $this->actingTenant($this->admin, $this->organization)
            ->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, 'cap_cm' => '300'])
            ->assertStatus(409)->assertJsonPath('message', 'Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.');
        $this->assertSame('200.000000', $inspection->fresh()->cap_cm);
    }

    public function test_revoked_membership_is_checked_again_at_request_time(): void
    {
        $official = User::factory()->create();
        $membership = $this->member($official, $this->organization);
        $this->actingTenant($official, $this->organization);
        $membership->forceFill(['active' => false])->save();
        $this->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput())->assertForbidden();
        $this->assertDatabaseCount('tree_inspections', 0);
    }

    #[DataProvider('internalFields')]
    public function test_internal_identity_cannot_be_reassigned_from_http(string $field, mixed $value): void
    {
        $inspection = $this->capture();
        $this->actingTenant($this->admin, $this->organization)
            ->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, $field => $value])->assertUnprocessable();
        $this->assertSame(1, $inspection->fresh()->lock_version);
        $this->assertSame($this->organization->id, $inspection->fresh()->organization_id);
        $this->assertSame($this->tree->id, $inspection->fresh()->tree_id);
    }

    public static function internalFields(): array
    {
        return [['organization_id', '11111111-1111-4111-8111-111111111111'], ['tree_id', '11111111-1111-4111-8111-111111111111'],
            ['created_by', 999], ['updated_by', 999], ['created_at', '2020-01-01'], ['deleted_at', '2020-01-01']];
    }

    public function test_unauthenticated_private_inspection_is_not_disclosed(): void
    {
        $inspection = $this->capture();
        $this->get(route('inspections.show', $inspection->id))->assertRedirect(route('login'));
        $this->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput())->assertUnauthorized();
    }
}

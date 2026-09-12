<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StaleInspectionException;
use App\Models\FaunaCategory;
use App\Models\InspectionRevision;
use App\Models\User;
use App\Repositories\InspectionFaunaRepository;
use App\Repositories\InspectionRevisionRepository;
use App\Repositories\TreeInspectionRepository;
use App\Services\InventoryService;
use App\Services\TreeInspectionReadService;
use App\Services\TreeInspectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\InspectionTestCase;

class TreeInspectionDomainTest extends InspectionTestCase
{
    public function test_minimal_visit_does_not_invent_measurements_or_campaign(): void
    {
        $inspection = app(TreeInspectionService::class)->create($this->admin, $this->tree->id, ['inspection_date' => '2026-09-12']);
        $this->assertTrue(Str::isUuid($inspection->id));
        foreach (['cap_cm', 'dap_m', 'radius_m', 'basal_area_m2', 'total_volume_m3', 'maturity_state_id', 'inventory_tree_id', 'number_of_stems'] as $field) {
            $this->assertNull($inspection->$field);
        }
        $this->assertSame('DENDROMETRY_V1', $inspection->calculation_rule_version);
        $this->assertSame('EXCEL_V1', $inspection->classificationRuleVersion->code);
        $this->assertSame(1, $inspection->lock_version);
        $this->assertDatabaseCount('inspection_revisions', 1);
    }

    public function test_complete_visit_preserves_distinct_observations_and_structured_audit(): void
    {
        $physical = $this->condition('PHY_STEM_STRAIGHT');
        $healthy = $this->condition('PHT_FOLIAGE_HEALTHY');
        $chlorosis = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $fauna = FaunaCategory::query()->take(2)->get();
        $inspection = $this->capture(['inventory_tree_id' => $this->tree->inventories->sole()->pivot->id,
            'physical_condition_ids' => [$physical->id], 'phytosanitary_condition_ids' => [$healthy->id, $chlorosis->id],
            'fauna_category_ids' => $fauna->modelKeys(), 'fauna_observations' => '  Aves en la copa.  ',
            'flowering_date' => '2026-08-10', 'flowering_development' => '  Desarrollo observado sin escala.  ',
            'fruiting_date' => null, 'fruiting_development' => 'Frutos visibles', 'general_observations' => 'Texto del instrumento.']);
        $this->assertCount(3, $inspection->conditions);
        $this->assertCount(2, $inspection->faunaCategories);
        $this->assertSame('  Desarrollo observado sin escala.  ', $inspection->flowering_development);
        $this->assertSame('  Aves en la copa.  ', $inspection->fauna_observations);
        $this->assertNull($inspection->fruiting_date);
        $this->assertSame('Texto del instrumento.', $inspection->general_observations);
        $this->assertNull($this->tree->fresh()->location_description);
        $this->assertSame('active', $this->tree->fresh()->current_status->value);
        $revision = InspectionRevision::query()->sole();
        $this->assertSame([], $revision->old_values);
        $this->assertSame('154.000000', $revision->new_values['measurements']['cap_cm']);
        $this->assertCount(3, $revision->new_values['conditions']);
        $this->assertCount(2, $revision->new_values['fauna']['category_ids']);
        $this->assertDatabaseHas('security_events', ['event' => 'inspection.created', 'organization_id' => $this->organization->id]);
    }

    public function test_three_historical_visits_survive_correction_without_changing_the_first_two(): void
    {
        $first = $this->capture(['inspection_date' => '2025-03-01']);
        $second = $this->capture(['inspection_date' => '2026-03-01']);
        $third = $this->capture(['inspection_date' => '2027-03-01']);
        $originalFirst = $first->getRawOriginal();
        $originalSecond = $second->getRawOriginal();
        $changed = app(TreeInspectionService::class)->update($this->admin, $third->id, ['lock_version' => 1, 'cap_cm' => '200']);
        $history = app(TreeInspectionRepository::class)->timelineForTree($this->organization->id, $this->tree->id);
        $this->assertSame([$third->id, $second->id, $first->id], $history->modelKeys());
        $this->assertSame($third->id, app(TreeInspectionRepository::class)->latestForTree($this->organization->id, $this->tree->id)->id);
        $this->assertSame($originalFirst, $first->fresh()->getRawOriginal());
        $this->assertSame($originalSecond, $second->fresh()->getRawOriginal());
        $this->assertSame('200.000000', $changed->cap_cm);
        $this->assertSame(2, $changed->lock_version);
        $this->assertDatabaseCount('tree_inspections', 3);
        $this->assertDatabaseCount('inspection_revisions', 4);
    }

    public function test_officials_can_correct_colleagues_without_an_active_campaign(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->member($first, $this->organization);
        $this->member($second, $this->organization);
        app(InventoryService::class)->update(
            $this->admin,
            $this->inventory->id,
            ['code' => $this->inventory->code, 'name' => $this->inventory->name, 'status' => 'closed', 'active' => true, 'lock_version' => 1]
        );
        $this->activateInspectionTenant($first);
        $inspection = $this->capture([], $first);
        $this->activateInspectionTenant($second);
        $changed = app(TreeInspectionService::class)->update($second, $inspection->id, ['lock_version' => 1, 'general_observations' => 'Corrección colaborativa']);
        $this->assertSame($first->id, $changed->created_by);
        $this->assertSame($second->id, $changed->updated_by);
        $this->assertSame(2, $changed->lock_version);
    }

    public function test_closed_campaign_is_valid_existing_context_for_retrospective_capture(): void
    {
        app(InventoryService::class)->update(
            $this->admin,
            $this->inventory->id,
            ['code' => $this->inventory->code, 'name' => $this->inventory->name, 'status' => 'closed', 'active' => false, 'lock_version' => 1]
        );
        $participation = $this->tree->inventories->sole()->pivot->id;
        $inspection = $this->capture(['inventory_tree_id' => $participation, 'inspection_date' => '2020-01-01']);
        $this->assertSame($participation, $inspection->inventory_tree_id);
        $this->assertSame($this->inventory->id, $inspection->inventoryTree->inventory_id);
    }

    public function test_stale_correction_leaves_measurements_children_and_audit_unchanged(): void
    {
        $inspection = $this->capture();
        app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'cap_cm' => '200']);
        try {
            app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'cap_cm' => '300']);
            $this->fail('Se sobrescribió una versión obsoleta.');
        } catch (StaleInspectionException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.', $exception->getMessage());
        }
        $this->assertSame('200.000000', $inspection->fresh()->cap_cm);
        $this->assertDatabaseCount('inspection_revisions', 2);
    }

    #[DataProvider('badVersions')]
    public function test_direct_service_rejects_malformed_version_before_casting(mixed $version): void
    {
        $inspection = $this->capture();
        try {
            app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => $version, 'cap_cm' => '200']);
            $this->fail('Se aceptó una versión malformada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }
        $this->assertSame(1, $inspection->fresh()->lock_version);
        $this->assertDatabaseCount('inspection_revisions', 1);
    }

    public function test_optional_filter_endpoints_accept_blank_other_end_without_numeric_errors(): void
    {
        $this->capture();
        $reader = app(TreeInspectionReadService::class);
        $this->assertSame(1, $reader->index($this->admin, ['date_to' => '2026-09-12', 'date_from' => '',
            'cap_cm_min' => ' ', 'cap_cm_max' => '154'])['inspections']->total());
        $this->assertSame(1, $reader->latestTrees($this->admin, ['dap_m_min' => '', 'dap_m_max' => '1'])->total());
        $this->expectException(ValidationException::class);
        $reader->index($this->admin, ['date_from' => '2026-09-13', 'date_to' => '2026-09-12']);
    }

    public function test_unsupported_historical_calculation_version_is_not_silently_reinterpreted(): void
    {
        $original = $this->capture();
        $historical = app(TreeInspectionRepository::class)->create(array_replace($original->getAttributes(), [
            'id' => (string) Str::uuid(), 'calculation_rule_version' => 'LEGACY_UNSUPPORTED',
        ]));
        try {
            app(TreeInspectionService::class)->update($this->admin, $historical->id, ['lock_version' => 1, 'cap_cm' => '200']);
            $this->fail('La visita cambió de interpretación sin su algoritmo original.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('calculation_rule_version', $exception->errors());
        }
        $this->assertSame('154.000000', $historical->fresh()->cap_cm);
        $this->assertSame(1, $historical->fresh()->lock_version);
    }

    public static function badVersions(): array
    {
        return [[null], [true], [1.9], ['1abc'], ['1.0'], [0], [-1], [[]], ['999999999999999999999']];
    }

    #[DataProvider('failingStages')]
    public function test_creation_rolls_back_every_part_when_a_late_stage_fails(string $repository, string $method): void
    {
        $this->mock($repository, fn ($mock) => $mock->makePartial()->shouldReceive($method)->once()->andThrow(new RuntimeException('Fallo de prueba')));
        $beforeEvents = DB::table('security_events')->count();
        try {
            $this->capture(['physical_condition_ids' => [$this->condition('PHY_STEM_STRAIGHT')->id]]);
            $this->fail('Debió fallar la transacción.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de prueba', $exception->getMessage());
        }
        foreach (['tree_inspections', 'inspection_conditions', 'inspection_fauna', 'inspection_revisions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($beforeEvents, DB::table('security_events')->count());
    }

    public static function failingStages(): array
    {
        return [[InspectionFaunaRepository::class, 'sync'], [InspectionRevisionRepository::class, 'record']];
    }

    public function test_failed_correction_rolls_back_measurements_relations_and_version(): void
    {
        $condition = $this->condition('PHY_STEM_STRAIGHT');
        $inspection = $this->capture(['physical_condition_ids' => [$condition->id]]);
        $this->mock(InspectionRevisionRepository::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Fallo de auditoría')));
        try {
            app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'cap_cm' => '200', 'physical_condition_ids' => []]);
            $this->fail('Debió revertir.');
        } catch (RuntimeException) {
            $this->assertSame('154.000000', $inspection->fresh()->cap_cm);
        }
        $this->assertSame(1, $inspection->fresh()->lock_version);
        $this->assertDatabaseCount('inspection_conditions', 1);
        $this->assertDatabaseCount('inspection_revisions', 1);
    }

    public function test_inactive_historical_condition_and_fauna_remain_visible_and_preservable(): void
    {
        $condition = $this->condition('PHY_STEM_STRAIGHT');
        $fauna = FaunaCategory::query()->firstOrFail();
        $inspection = $this->capture(['physical_condition_ids' => [$condition->id], 'fauna_category_ids' => [$fauna->id]]);
        $condition->update(['active' => false]);
        $fauna->update(['active' => false]);
        $edited = app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'general_observations' => 'Historia conservada']);
        $this->assertSame([$condition->id], $edited->conditions->modelKeys());
        $this->assertSame([$fauna->id], $edited->faunaCategories->modelKeys());
        $this->assertFalse($edited->conditions->sole()->active);
        $view = app(TreeInspectionReadService::class)->form($this->admin, $this->tree->id, $inspection->id);
        $this->assertContains($condition->id, $view['selectedPhysicalConditionIds']);
        $this->expectException(ValidationException::class);
        $this->capture(['physical_condition_ids' => [$condition->id]]);
    }

    #[DataProvider('badSelectionTypes')]
    public function test_selection_cannot_duplicate_or_cross_assessment_types(string $kind): void
    {
        $physical = $this->condition('PHY_STEM_STRAIGHT')->id;
        $phyto = $this->condition('PHT_FOLIAGE_CHLOROSIS')->id;
        $data = match ($kind) {
            'duplicate' => ['physical_condition_ids' => [$physical, $physical]],
            'wrong_type' => ['physical_condition_ids' => [$phyto]],
            'unknown' => ['phytosanitary_condition_ids' => [(string) Str::uuid()]],
            'invalid_array' => ['physical_condition_ids' => $physical],
        };
        try {
            $this->capture($data);
            $this->fail('Se aceptó una selección inválida.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('tree_inspections', 0);
        }
    }

    public static function badSelectionTypes(): array
    {
        return [['duplicate'], ['wrong_type'], ['unknown'], ['invalid_array']];
    }
}

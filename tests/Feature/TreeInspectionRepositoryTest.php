<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClassificationRuleVersion;
use App\Models\Condition;
use App\Models\FaunaCategory;
use App\Models\InventoryTree;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\InspectionConditionRepository;
use App\Repositories\InspectionFaunaRepository;
use App\Repositories\InspectionRevisionRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\TreeInspectionRepository;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\ConditionCatalogSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class TreeInspectionRepositoryTest extends PostgresTestCase
{
    private Organization $organization;
    private User $actor;
    private Tree $tree;
    private string $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ForestryCatalogSeeder::class, ConditionCatalogSeeder::class, ClassificationRuleSeeder::class, MaintenanceCatalogSeeder::class]);
        $this->organization = $this->pilot();
        $this->actor = User::factory()->create();
        $this->tree = $this->treeFor($this->organization);
        $this->ruleId = ClassificationRuleVersion::query()->where('code', 'EXCEL_V1')->value('id');
    }

    public function test_optional_measurements_and_campaign_keep_unknown_data_null(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12', [
            'fauna_observations' => 'Se observó actividad sin identificar categoría.',
            'flowering_development' => "  Estimación libre\nsin catálogo  ",
        ]);
        $this->assertNull($inspection->inventory_tree_id);
        $this->assertNull($inspection->cap_cm);
        $this->assertNull($inspection->dap_m);
        $this->assertNull($inspection->maturity_state_id);
        $this->assertNull($inspection->flowering_date);
        $this->assertSame("  Estimación libre\nsin catálogo  ", $inspection->flowering_development);
        $this->assertCount(0, $inspection->faunaCategories);
        $this->assertSame($this->ruleId, $inspection->classification_rule_version_id);
        $this->assertSame('DENDROMETRY_V1', $inspection->calculation_rule_version);
    }

    public function test_inspection_fk_rejects_a_tree_of_another_organization(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $this->sqlFailure(fn () => $this->visit($this->tree, '2026-09-12', ['organization_id' => $other->id]), '23503');
    }

    public function test_campaign_fk_rejects_another_tree_even_within_the_same_organization(): void
    {
        $otherTree = $this->treeFor($this->organization);
        $participation = $this->participation($otherTree);
        $this->sqlFailure(fn () => $this->visit($this->tree, '2026-09-12', ['inventory_tree_id' => $participation->id]), '23503');
        $this->assertNull(app(TreeInspectionRepository::class)->validateInventoryTree($this->organization->id, $this->tree->id, $participation->id));
    }

    public function test_campaign_fk_rejects_another_organization(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $participation = $this->participation($this->treeFor($other));
        $this->sqlFailure(fn () => $this->visit($this->tree, '2026-09-12', ['inventory_tree_id' => $participation->id]), '23503');
    }

    public function test_existing_closed_campaign_is_valid_for_a_historical_visit(): void
    {
        $participation = $this->participation($this->tree);
        $participation->inventory->update(['status' => 'closed', 'active' => false]);
        $inspection = $this->visit($this->tree, '2025-01-01', ['inventory_tree_id' => $participation->id]);
        $repository = app(TreeInspectionRepository::class);

        $this->assertSame($participation->id, $repository->validateInventoryTree($this->organization->id, $this->tree->id, $participation->id)->id);
        $this->assertSame([$participation->id], $repository->inventoryOptionsForTree($this->organization->id, $this->tree->id)->modelKeys());
        $this->assertSame($inspection->id, $this->tree->inspections()->sole()->id);
        $this->assertSame($inspection->id, $participation->inspections()->sole()->id);
    }

    public function test_child_tables_reject_another_tenant(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $other = $this->anotherOrganization($this->organization);
        foreach ([
            ['inspection_conditions', ['condition_id' => $this->condition('PHT_FOLIAGE_CHLOROSIS')]],
            ['inspection_fauna', ['fauna_category_id' => FaunaCategory::query()->firstOrFail()->id]],
        ] as [$table, $fields]) {
            $this->sqlFailure(fn () => DB::table($table)->insert(array_merge([
                'organization_id' => $other->id, 'inspection_id' => $inspection->id, 'created_at' => now(),
            ], $fields)), '23503');
        }
        $this->sqlFailure(fn () => DB::table('inspection_revisions')->insert([
            'id' => Str::uuid(), 'organization_id' => $other->id, 'inspection_id' => $inspection->id,
            'version' => 1, 'changed_by' => $this->actor->id, 'changed_at' => now(), 'event' => 'inspection.created',
            'old_values' => '{}', 'new_values' => '{}',
        ]), '23503');
    }

    public function test_condition_and_fauna_duplicates_and_unknown_ids_are_rejected_by_database(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $condition = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $fauna = FaunaCategory::query()->firstOrFail()->id;
        app(InspectionConditionRepository::class)->sync($this->organization->id, $inspection->id, [$condition]);
        app(InspectionFaunaRepository::class)->sync($this->organization->id, $inspection->id, [$fauna]);
        foreach ([['inspection_conditions', 'condition_id', $condition], ['inspection_fauna', 'fauna_category_id', $fauna]] as [$table, $field, $id]) {
            $base = ['organization_id' => $this->organization->id, 'inspection_id' => $inspection->id, 'created_at' => now()];
            $this->sqlFailure(fn () => DB::table($table)->insert($base + [$field => $id]), '23505');
            $this->sqlFailure(fn () => DB::table($table)->insert($base + [$field => (string) Str::uuid()]), '23503');
        }
    }

    public function test_original_identity_and_rules_cannot_be_reassigned_even_with_sql(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $other = $this->anotherOrganization($this->organization);
        $changes = [
            ['id' => (string) Str::uuid()], ['organization_id' => $other->id],
            ['tree_id' => $this->treeFor($this->organization)->id], ['created_by' => User::factory()->create()->id],
            ['created_at' => '2025-01-01 00:00:00'], ['classification_rule_version_id' => (string) Str::uuid()],
            ['calculation_rule_version' => 'DENDROMETRY_V2'],
        ];
        foreach ($changes as $change) {
            $this->sqlFailure(fn () => DB::table('tree_inspections')->where('id', $inspection->id)->update($change), '23514');
        }
        $this->sqlFailure(fn () => DB::table('tree_inspections')->where('id', $inspection->id)->delete(), '23514');
        $this->assertDatabaseHas('tree_inspections', ['id' => $inspection->id, 'created_by' => $this->actor->id]);
    }

    public function test_classification_references_are_real_foreign_keys(): void
    {
        $this->sqlFailure(fn () => $this->visit($this->tree, '2026-09-12', ['classification_rule_version_id' => (string) Str::uuid()]), '23503');
        $this->sqlFailure(fn () => $this->visit($this->tree, '2026-09-12', ['maturity_state_id' => (string) Str::uuid()]), '23503');
    }

    #[DataProvider('invalidNumbers')]
    public function test_database_rejects_unsafe_numeric_values(array $values): void
    {
        $this->sqlFailure(fn () => $this->visit($this->tree, '2026-09-12', $values), '23514');
    }

    public static function invalidNumbers(): array
    {
        return [
            [['cap_cm' => '0']], [['cap_cm' => '-1']], [['cap_cm' => 'NaN']],
            [['total_height_m' => '0']], [['commercial_height_m' => '-0.1']],
            [['total_height_m' => '10', 'commercial_height_m' => '10.000001']],
            [['form_factor' => '0.449999']], [['form_factor' => '0.700001']],
            [['number_of_stems' => 0]], [['number_of_stems' => -1]],
            [['crown_x_m' => '-0.000001']], [['crown_y_m' => 'NaN']],
            [['dap_m' => '-1']], [['radius_m' => 'NaN']], [['basal_area_m2' => '-1']],
            [['commercial_volume_m3' => '-1']], [['total_volume_m3' => 'NaN']],
            [['diameter_class_number' => 0]], [['height_class_number' => 0]], [['lock_version' => 0]],
        ];
    }

    public function test_precision_and_instrument_factor_limits_are_explicit_without_biological_maxima(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12', [
            'cap_cm' => '999999999999.999999', 'crown_x_m' => '0', 'crown_y_m' => '0',
            'form_factor' => '0.45', 'commercial_height_m' => '0', 'dap_m' => '0.490197224723',
            'basal_area_m2' => '0.188725931518', 'total_volume_m3' => '1.132355589110',
        ]);
        $this->assertSame('999999999999.999999', $inspection->cap_cm);
        $this->assertSame('0.490197224723', $inspection->dap_m);
        $this->assertSame('0.188725931518', $inspection->basal_area_m2);
        $this->assertSame('1.132355589110', $inspection->total_volume_m3);
        $this->assertSame('0.700000', $this->visit($this->tree, '2026-09-13', ['form_factor' => '0.7'])->form_factor);
        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'tree_inspections')->get()->keyBy('column_name');
        foreach (['cap_cm' => [18, 6], 'form_factor' => [7, 6], 'dap_m' => [24, 12],
            'basal_area_m2' => [36, 12], 'commercial_volume_m3' => [48, 12]] as $field => [$precision, $scale]) {
            $this->assertSame($precision, (int) $columns[$field]->numeric_precision);
            $this->assertSame($scale, (int) $columns[$field]->numeric_scale);
        }
    }

    public function test_revision_snapshots_are_structured_unique_and_append_only(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $repository = app(InspectionRevisionRepository::class);
        $values = ['measurements' => ['cap_cm' => '154.000000'], 'conditions' => [], 'fauna' => []];
        $revision = $repository->record($this->organization->id, $inspection->id, (int) $this->actor->id, 1, [], $values, 'inspection.created');
        $this->assertEquals($values, $revision->fresh()->new_values);
        $this->assertSame('154.000000', $revision->fresh()->new_values['measurements']['cap_cm']);
        $this->assertSame('object', DB::table('inspection_revisions')->where('id', $revision->id)->selectRaw('jsonb_typeof(old_values) as kind')->value('kind'));
        $this->sqlFailure(fn () => $repository->record($this->organization->id, $inspection->id, (int) $this->actor->id, 1, [], [], 'inspection.created'), '23505');
        $this->sqlFailure(fn () => DB::table('inspection_revisions')->where('id', $revision->id)->update(['new_values' => '{}']), '23514');
        $this->sqlFailure(fn () => DB::table('inspection_revisions')->where('id', $revision->id)->delete(), '23514');
        $this->assertSame([$revision->id], $repository->forInspection($this->organization->id, $inspection->id)->modelKeys());
        $this->assertCount(0, $repository->forInspection($this->anotherOrganization($this->organization)->id, $inspection->id));
    }

    public function test_catalog_options_keep_23_physical_and_42_phytosanitary_conditions(): void
    {
        $categories = app(InspectionConditionRepository::class)->options();
        $conditions = $categories->flatMap->conditions;
        $this->assertCount(23, $conditions->where('assessment_type', 'physical_mechanical'));
        $this->assertCount(42, $conditions->where('assessment_type', 'phytosanitary'));
        $this->assertCount(6, app(InspectionFaunaRepository::class)->options());
        $repository = app(InspectionConditionRepository::class);
        $chlorosis = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $this->assertFalse($repository->validate([$chlorosis], 'physical_mechanical'));
        $this->assertTrue($repository->validate([$chlorosis], 'phytosanitary'));
        $this->assertFalse($repository->validate([$chlorosis, $chlorosis], 'phytosanitary'));
        $this->assertFalse($repository->validate([(string) Str::uuid()], 'phytosanitary'));
    }

    public function test_inactive_condition_and_category_remain_historical_but_not_new_selections(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $id = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $repository = app(InspectionConditionRepository::class);
        $repository->sync($this->organization->id, $inspection->id, [$id]);
        $condition = Condition::query()->findOrFail($id);
        $condition->update(['active' => false]);
        $condition->category->update(['active' => false]);
        $repository->sync($this->organization->id, $inspection->id, [$id]);

        $this->assertFalse($repository->validate([$id], 'phytosanitary'));
        $this->assertTrue($repository->validate([$id], 'phytosanitary', $inspection));
        $this->assertTrue($repository->validateForFilter([$id], 'phytosanitary'));
        $filterCategories = $repository->optionsForFilter();
        $this->assertTrue($filterCategories->contains('id', $condition->condition_category_id));
        $this->assertTrue($filterCategories->flatMap->conditions->contains('id', $id));
        $this->assertFalse($filterCategories->flatMap->conditions->firstWhere('id', $id)->active);
        $this->assertTrue($repository->options($inspection)->flatMap->conditions->contains('id', $id));
        $this->assertFalse($repository->options()->flatMap->conditions->contains('id', $id));
        $this->assertSame([$inspection->id], app(TreeInspectionRepository::class)->paginate($this->organization->id, ['phytosanitary_condition_ids' => [$id]])->getCollection()->modelKeys());
    }

    public function test_same_label_conditions_keep_distinct_ids_and_do_not_infer_exclusions(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $fungi = Condition::query()->where('name', 'Hongos')->where('assessment_type', 'phytosanitary')->pluck('id')->all();
        $this->assertGreaterThanOrEqual(2, count($fungi));
        $repository = app(InspectionConditionRepository::class);
        $this->assertTrue($repository->validate($fungi, 'phytosanitary'));
        $repository->sync($this->organization->id, $inspection->id, $fungi);
        $this->assertCount(count($fungi), $inspection->fresh()->conditions);
    }

    public function test_sync_preserves_original_selection_timestamp_and_removes_only_requested_changes(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $a = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $b = $this->condition('PHT_STEM_FUNGI');
        $repository = app(InspectionConditionRepository::class);
        $repository->sync($this->organization->id, $inspection->id, [$a, $b]);
        $initial = DB::table('inspection_conditions')->where('inspection_id', $inspection->id)->where('condition_id', $a)->value('created_at');
        $repository->sync($this->organization->id, $inspection->id, [$a]);
        $repository->sync($this->organization->id, $inspection->id, [$a]);
        $this->assertSame([$a], $inspection->fresh()->conditions->modelKeys());
        $this->assertSame($initial, DB::table('inspection_conditions')->where('inspection_id', $inspection->id)->value('created_at'));

        $category = FaunaCategory::query()->firstOrFail();
        $fauna = app(InspectionFaunaRepository::class);
        $fauna->sync($this->organization->id, $inspection->id, [$category->id]);
        $category->update(['active' => false]);
        $this->assertFalse($fauna->validate([$category->id]));
        $this->assertTrue($fauna->validate([$category->id], $inspection));
        $this->assertTrue($fauna->options($inspection)->contains('id', $category->id));
        $fauna->sync($this->organization->id, $inspection->id, [$category->id]);
        $this->assertSame([$category->id], $inspection->fresh()->faunaCategories->modelKeys());
        $fauna->sync($this->organization->id, $inspection->id, []);
        $this->assertCount(0, $inspection->fresh()->faunaCategories);
    }

    public function test_latest_uses_visit_date_then_original_timestamp_then_uuid_and_not_update_time(): void
    {
        $old = $this->visit($this->tree, '2025-01-01');
        $early = $this->visit($this->tree, '2026-09-12', ['created_at' => '2026-09-12 12:00:00.100000']);
        $low = $this->visit($this->tree, '2026-09-12', ['id' => '00000000-0000-4000-8000-000000000010', 'created_at' => '2026-09-12 12:00:00.200000']);
        $high = $this->visit($this->tree, '2026-09-12', ['id' => '00000000-0000-4000-8000-000000000020', 'created_at' => '2026-09-12 12:00:00.200000']);
        $repository = app(TreeInspectionRepository::class);
        $repository->update($old, ['general_observations' => 'Corrección posterior', 'lock_version' => 2, 'updated_by' => $this->actor->id]);
        $this->assertSame($high->id, $repository->latestForTree($this->organization->id, $this->tree->id)->id);
        $this->assertSame([$high->id, $low->id, $early->id, $old->id], $repository->timelineForTree($this->organization->id, $this->tree->id)->modelKeys());
        $this->assertSame('200000', $high->fresh()->created_at->format('u'));
        $this->assertSame(1, $early->fresh()->lock_version);
        $this->assertSame(4, TreeInspection::query()->count());
    }

    public function test_any_and_all_apply_to_one_latest_inspection_and_never_combine_visits(): void
    {
        $otherTree = $this->treeFor($this->organization);
        $a = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $b = $this->condition('PHT_STEM_FUNGI');
        $old = $this->visit($this->tree, '2025-01-01');
        $latest = $this->visit($this->tree, '2026-09-12');
        $both = $this->visit($otherTree, '2026-09-12');
        $conditions = app(InspectionConditionRepository::class);
        $conditions->sync($this->organization->id, $old->id, [$a]);
        $conditions->sync($this->organization->id, $latest->id, [$b]);
        $conditions->sync($this->organization->id, $both->id, [$a, $b]);
        $repository = app(TreeInspectionRepository::class);
        $all = ['phytosanitary_condition_ids' => [$a, $b], 'condition_mode' => 'all'];
        $this->assertSame([$otherTree->id], $repository->latestTrees($this->organization->id, $all)->getCollection()->modelKeys());
        $this->assertSame(2, $repository->latestTrees($this->organization->id, array_replace($all, ['condition_mode' => 'any']))->total());
        $this->assertSame([$both->id], $repository->paginate($this->organization->id, $all)->getCollection()->modelKeys());
        $this->assertSame(0, $repository->latestTrees($this->organization->id, ['phytosanitary_condition_ids' => [$a], 'date_to' => '2025-12-31'])->total());
        $this->assertSame(2, $repository->latestTrees($this->organization->id, ['condition_mode' => 'all'])->total());
    }

    public function test_numeric_filters_do_not_choose_an_older_matching_visit(): void
    {
        $this->visit($this->tree, '2025-01-01', ['cap_cm' => '200', 'total_height_m' => '20']);
        $this->visit($this->tree, '2026-09-12', ['cap_cm' => '20', 'total_height_m' => '2']);
        $repository = app(TreeInspectionRepository::class);
        $this->assertSame(0, $repository->latestTrees($this->organization->id, ['cap_cm_min' => '100'])->total());
        $this->assertSame(1, $repository->latestTrees($this->organization->id, ['cap_cm_max' => '20', 'total_height_m_min' => '2'])->total());
        $this->assertSame(1, $repository->paginate($this->organization->id, ['cap_cm_min' => '100'])->total());
    }

    public function test_campaign_filter_has_explicit_distinct_semantics_for_visits_and_tree_participation(): void
    {
        $first = $this->participation($this->tree);
        $second = $this->participation($this->tree);
        $old = $this->visit($this->tree, '2025-01-01', ['inventory_tree_id' => $first->id]);
        $latest = $this->visit($this->tree, '2026-09-12', ['inventory_tree_id' => $second->id]);
        $repository = app(TreeInspectionRepository::class);
        $this->assertSame([$old->id], $repository->paginate($this->organization->id, ['inventory_id' => $first->inventory_id])->getCollection()->modelKeys());
        $trees = $repository->latestTrees($this->organization->id, ['inventory_id' => $first->inventory_id]);
        $this->assertSame([$this->tree->id], $trees->getCollection()->modelKeys());
        $this->assertSame($latest->id, $trees->getCollection()->first()->latestInspection->id);
    }

    public function test_repository_reads_remain_scoped_even_when_foreign_ids_are_known(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $foreignTree = $this->treeFor($other);
        $foreign = $this->visit($foreignTree, '2026-09-12');
        $repository = app(TreeInspectionRepository::class);
        $this->assertCount(0, $repository->timelineForTree($this->organization->id, $foreignTree->id));
        $this->assertNull($repository->latestForTree($this->organization->id, $foreignTree->id));
        $this->assertCount(0, $repository->latestForTrees($this->organization->id, [$foreignTree->id]));
        $this->assertSame(0, $repository->paginate($this->organization->id, ['tree_id' => $foreignTree->id])->total());
        $this->expectException(ModelNotFoundException::class);
        $repository->findForTenant($this->organization->id, $foreign->id);
    }

    public function test_trees_without_visits_remain_visible_but_do_not_satisfy_inspection_filters(): void
    {
        $repository = app(TreeInspectionRepository::class);
        $page = $repository->latestTrees($this->organization->id);
        $this->assertSame(1, $page->total());
        $this->assertTrue($page->getCollection()->first()->relationLoaded('latestInspection'));
        $this->assertNull($page->getCollection()->first()->latestInspection);
        $this->assertSame(0, $repository->latestTrees($this->organization->id, ['date_from' => '2020-01-01'])->total());
    }

    public function test_latest_batch_query_count_does_not_grow_per_tree(): void
    {
        $trees = [$this->tree];
        for ($i = 0; $i < 3; $i++) {
            $trees[] = $this->treeFor($this->organization);
        }
        foreach ($trees as $tree) {
            $this->visit($tree, '2025-01-01');
            $this->visit($tree, '2026-09-12');
        }
        $repository = app(TreeInspectionRepository::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $repository->latestForTrees($this->organization->id, [$this->tree->id]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $visits = $repository->latestForTrees($this->organization->id, array_map(fn (Tree $tree) => $tree->id, $trees));
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(4, $visits);
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(15, $many);
        foreach ($visits as $visit) {
            $this->assertTrue($visit->relationLoaded('conditions'));
            $this->assertTrue($visit->relationLoaded('faunaCategories'));
        }
    }

    public function test_annulled_tree_keeps_its_inspection_history_but_is_not_in_operational_lists(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $this->tree->forceFill(['deleted_at' => now(), 'deleted_by' => $this->actor->id,
            'deletion_reason_id' => DB::table('deletion_reasons')->first()->id])->save();
        $repository = app(TreeInspectionRepository::class);
        $this->assertSame(0, $repository->paginate($this->organization->id)->total());
        $this->assertSame(0, $repository->latestTrees($this->organization->id)->total());
        $this->assertTrue($repository->findForTenant($this->organization->id, $inspection->id)->tree->trashed());
        $this->assertSame(1, $repository->paginate($this->organization->id, ['trashed' => 'only'])->total());
        $this->assertSame(1, $repository->latestTrees($this->organization->id, ['trashed' => 'only'])->total());
    }

    public function test_database_rejects_new_disabled_conditions_categories_and_fauna(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $condition = Condition::query()->findOrFail($this->condition('PHT_FOLIAGE_CHLOROSIS'));
        $base = ['organization_id' => $this->organization->id, 'inspection_id' => $inspection->id, 'created_at' => now()];
        $condition->update(['active' => false]);
        $this->sqlFailure(fn () => DB::table('inspection_conditions')->insert($base + ['condition_id' => $condition->id]), '23514');
        $condition->update(['active' => true]);
        $condition->category->update(['active' => false]);
        $this->sqlFailure(fn () => DB::table('inspection_conditions')->insert($base + ['condition_id' => $condition->id]), '23514');
        $fauna = FaunaCategory::query()->firstOrFail();
        $fauna->update(['active' => false]);
        $this->sqlFailure(fn () => DB::table('inspection_fauna')->insert($base + ['fauna_category_id' => $fauna->id]), '23514');
        $this->assertDatabaseCount('inspection_conditions', 0);
        $this->assertDatabaseCount('inspection_fauna', 0);
    }

    public function test_pivot_identity_cannot_be_moved_to_another_inspection_or_catalog_entry(): void
    {
        $inspection = $this->visit($this->tree, '2026-09-12');
        $other = $this->visit($this->tree, '2026-09-13');
        $condition = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $fauna = FaunaCategory::query()->firstOrFail()->id;
        app(InspectionConditionRepository::class)->sync($this->organization->id, $inspection->id, [$condition]);
        app(InspectionFaunaRepository::class)->sync($this->organization->id, $inspection->id, [$fauna]);
        foreach ([
            ['inspection_conditions', ['inspection_id' => $other->id]],
            ['inspection_conditions', ['condition_id' => $this->condition('PHT_STEM_FUNGI')]],
            ['inspection_fauna', ['inspection_id' => $other->id]],
            ['inspection_fauna', ['fauna_category_id' => FaunaCategory::query()->whereKeyNot($fauna)->firstOrFail()->id]],
        ] as [$table, $attributes]) {
            $this->sqlFailure(fn () => DB::table($table)->where('inspection_id', $inspection->id)->update($attributes), '23514');
        }
    }

    private function visit(Tree $tree, string $date, array $attributes = []): TreeInspection
    {
        return app(TreeInspectionRepository::class)->create(array_replace([
            'organization_id' => $tree->organization_id, 'tree_id' => $tree->id,
            'inspection_date' => $date, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
            'classification_rule_version_id' => $this->ruleId, 'calculation_rule_version' => 'DENDROMETRY_V1', 'lock_version' => 1,
        ], $attributes));
    }

    private function treeFor(Organization $organization): Tree
    {
        $tree = new Tree();
        $tree->forceFill(['organization_id' => $organization->id, 'code' => 'INSPECTION-'.strtoupper(Str::random(12)),
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id])->save();

        return $tree;
    }

    private function participation(Tree $tree): InventoryTree
    {
        $repository = app(InventoryRepository::class);
        $inventory = $repository->create(['organization_id' => $tree->organization_id, 'code' => 'VISIT-'.strtoupper(Str::random(12)),
            'name' => 'Campaña de referencia', 'status' => 'active', 'active' => true,
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);

        return $repository->attach($tree->organization_id, $inventory->id, $tree->id, (int) $this->actor->id);
    }

    private function condition(string $code): string
    {
        return Condition::query()->where('code', $code)->sole()->id;
    }

    private function sqlFailure(callable $operation, string $state): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL debía rechazar esta referencia o valor.');
        } catch (QueryException $exception) {
            $this->assertSame($state, (string) $exception->getCode());
        }
    }
}

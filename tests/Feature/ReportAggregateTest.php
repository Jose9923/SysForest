<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\ReportSelectionDTO;
use App\Models\Condition;
use App\Models\FaunaCategory;
use App\Models\Species;
use App\Repositories\ReportRepository;
use App\Repositories\TreeQueryRepository;
use App\Reports\ReportCatalog;
use App\Services\ReportFilterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\PublicPortalTestCase;

class ReportAggregateTest extends PublicPortalTestCase
{
    public function test_all_available_report_definitions_have_a_bounded_explicit_projection(): void
    {
        $selection = $this->selection();
        foreach (app(ReportCatalog::class)->definitions() as $definition) {
            if (!$definition['available']) {
                continue;
            }
            $result = $this->aggregate($selection, $definition['key']);
            $this->assertSame(1, $result['totalTrees'], $definition['key']);
            $this->assertSame(count($result['rows']), $result['totalRows']);
            $keys = array_column($result['columns'], 'key');
            foreach ($result['rows'] as $row) {
                $this->assertEqualsCanonicalizing($keys, array_keys($row));
                $this->assertArrayNotHasKey('id', $row);
                $this->assertArrayNotHasKey('created_by', $row);
            }
            foreach ($result['columns'] as $column) {
                $this->assertContains($column['type'], ['text', 'integer', 'decimal', 'boolean']);
            }
        }
    }

    public function test_species_and_families_include_unidentified_individuals(): void
    {
        $species = Species::query()->with('family')->firstOrFail();
        $this->point(-73.5, 4, ['species_id' => $species->id]);
        $selection = $this->selection();
        $speciesResult = $this->aggregate($selection, 'species');
        $familyResult = $this->aggregate($selection, 'families');

        $this->assertSame(2, $speciesResult['totalTrees']);
        $this->assertSame(1, $this->groupCount($speciesResult, 'Sin dato'));
        $this->assertSame(1, $this->groupCount($speciesResult, $species->scientific_name));
        $this->assertSame(1, $this->groupCount($familyResult, $species->family->scientific_name));
    }

    public function test_inspection_summary_counts_latest_per_tree_and_explicit_missing_data(): void
    {
        $this->capture(['inspection_date' => '2026-09-01']);
        $this->capture(['inspection_date' => '2026-09-02']);
        $this->point(-73.5, 4);
        $result = $this->aggregate($this->selection(), 'inspections');

        $this->assertSame(2, $result['totalTrees']);
        $this->assertSame(1, $this->groupCount($result, 'Con última inspección'));
        $this->assertSame(1, $this->groupCount($result, 'Sin inspección'));
    }

    public function test_classes_use_latest_measurements_and_keep_rule_version(): void
    {
        $this->capture(['inspection_date' => '2026-09-01', 'total_height_m' => '2']);
        $latest = $this->capture(['inspection_date' => '2026-09-02', 'total_height_m' => '25']);
        $result = $this->aggregate($this->selection(), 'height_classes');

        $this->assertSame([['rule_version' => $latest->classificationRuleVersion->code,
            'group' => (string) $latest->height_class_number, 'trees' => 1]], $result['rows']);
    }

    public function test_latest_condition_filter_does_not_fall_back_to_an_older_matching_visit(): void
    {
        $conditions = Condition::query()->where('assessment_type', 'physical_mechanical')->orderBy('code')->limit(2)->get();
        $this->capture(['inspection_date' => '2026-09-01', 'physical_condition_ids' => [$conditions[0]->id]]);
        $this->capture(['inspection_date' => '2026-09-02', 'physical_condition_ids' => [$conditions[1]->id]]);
        $filtered = $this->selection(['physical_condition_ids' => [$conditions[0]->id]]);

        $this->assertSame(0, $this->aggregate($filtered, 'physical_conditions')['totalTrees']);
        $current = $this->aggregate($this->selection(), 'physical_conditions');
        $this->assertSame(1, $this->groupCount($current, $conditions[1]->name));
        $this->assertSame(0, $this->groupCount($current, $conditions[0]->name));
    }

    public function test_multivalued_conditions_count_distinct_trees_and_do_not_invent_a_missing_group(): void
    {
        $physical = Condition::query()->where('assessment_type', 'physical_mechanical')->orderBy('code')->limit(2)->get();
        $phytosanitary = Condition::query()->where('assessment_type', 'phytosanitary')->firstOrFail();
        $this->capture(['physical_condition_ids' => $physical->modelKeys(), 'phytosanitary_condition_ids' => [$phytosanitary->id]]);
        $result = $this->aggregate($this->selection(), 'physical_conditions');

        $this->assertSame(1, $result['totalTrees']);
        $this->assertSame(2, array_sum(array_column($result['rows'], 'trees')));
        $this->assertSame(0, $this->groupCount($result, 'Sin condición registrada'));
        $this->assertNotEmpty($result['notices']);
    }

    public function test_condition_any_and_all_select_the_same_trees_as_the_common_engine(): void
    {
        $conditions = Condition::query()->where('assessment_type', 'physical_mechanical')->orderBy('code')->limit(2)->get();
        $this->capture(['physical_condition_ids' => $conditions->modelKeys()]);
        $other = $this->point(-73.5, 4);
        $this->capture(['physical_condition_ids' => [$conditions[0]->id]], tree: $other);
        $any = $this->selection(['physical_condition_ids' => $conditions->modelKeys(), 'condition_mode' => 'any']);
        $all = $this->selection(['physical_condition_ids' => $conditions->modelKeys(), 'condition_mode' => 'all']);

        $this->assertSame(2, $this->aggregate($any, 'physical_conditions')['totalTrees']);
        $this->assertSame(1, $this->aggregate($all, 'physical_conditions')['totalTrees']);
    }

    public function test_historical_inactive_condition_labels_remain_in_reports(): void
    {
        $condition = Condition::query()->where('assessment_type', 'physical_mechanical')->firstOrFail();
        $this->capture(['physical_condition_ids' => [$condition->id]]);
        $condition->update(['active' => false]);
        $result = $this->aggregate($this->selection(), 'physical_conditions');

        $this->assertSame(1, $this->groupCount($result, $condition->name));
    }

    public function test_fauna_counts_categories_without_exporting_observations(): void
    {
        $fauna = FaunaCategory::query()->orderBy('code')->limit(2)->get();
        $this->capture(['fauna_category_ids' => $fauna->modelKeys(), 'fauna_observations' => 'TEXTO PRIVADO NO EXPORTABLE']);
        $result = $this->aggregate($this->selection(), 'fauna');

        $this->assertSame(1, $result['totalTrees']);
        $this->assertSame(2, array_sum(array_column($result['rows'], 'trees')));
        $this->assertStringNotContainsString('TEXTO PRIVADO', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_origin_dimensions_and_protection_flags_are_not_merged(): void
    {
        $this->tree->forceFill(['is_protected' => true, 'is_heritage' => true])->save();
        $origins = $this->aggregate($this->selection(), 'origins');
        $protected = $this->aggregate($this->selection(), 'protected');

        $this->assertSame(2, array_sum(array_column($origins['rows'], 'trees')));
        $this->assertSame(2, array_sum(array_column($protected['rows'], 'trees')));
        $this->assertSame(['Protegido', 'Patrimonial'], array_column($protected['rows'], 'dimension'));
        $this->assertSame(['Sí', 'Sí'], array_column($protected['rows'], 'group'));
        $this->assertNotEmpty($origins['notices']);
    }

    public function test_maintenance_count_restricts_to_the_actual_orders_matching_the_filters(): void
    {
        $this->order(['priority' => 'high']);
        $this->order(['priority' => 'normal']);
        $result = $this->aggregate($this->selection(['maintenance_priority' => 'high']), 'maintenances');

        $this->assertSame(1, $result['totalTrees']);
        $this->assertSame(1, array_sum(array_column($result['rows'], 'orders')));
        $this->assertSame(1, array_sum(array_column($result['rows'], 'trees')));
    }

    public function test_cancelled_order_semantics_match_the_existing_tree_filter(): void
    {
        $this->advance($this->order(), 'cancelled', ['reason' => 'Cancelación de prueba']);
        $unfiltered = $this->aggregate($this->selection(), 'maintenances');
        $typed = $this->aggregate($this->selection(['maintenance_type_id' => $this->maintenanceInput()['maintenance_type_id']]), 'maintenances');
        $cancelled = $this->aggregate($this->selection(['maintenance_status' => 'cancelled']), 'maintenances');

        $this->assertSame(1, array_sum(array_column($unfiltered['rows'], 'orders')));
        $this->assertSame(0, $typed['totalTrees']);
        $this->assertSame([], $typed['rows']);
        $this->assertSame(1, array_sum(array_column($cancelled['rows'], 'orders')));
    }

    public function test_public_latest_ignores_newer_private_inspection_and_private_maintenance(): void
    {
        $this->publishTree();
        $published = $this->publishInspection($this->capture(['inspection_date' => '2026-09-01', 'total_height_m' => '5']));
        $this->capture(['inspection_date' => '2026-09-02', 'total_height_m' => '25']);
        $this->order();
        $this->executedOrder();
        $selection = app(ReportFilterService::class)->forPublic([], 'height_classes');
        $classes = $this->aggregate($selection, 'height_classes');
        $orders = $this->aggregate($selection, 'maintenances');

        $this->assertSame((string) $published->height_class_number, $classes['rows'][0]['group']);
        $this->assertSame(1, array_sum(array_column($orders['rows'], 'orders')));
        $this->assertSame(['Ejecutado'], array_column($orders['rows'], 'status'));
    }

    public function test_public_organization_code_is_stable_and_not_the_private_id(): void
    {
        $this->publishTree();
        $publicCode = $this->organization->public_code;
        $this->organization->update(['code' => 'REPORT-NEW-CODE']);
        $selection = app(ReportFilterService::class)->forPublic([], 'organizations');
        $result = $this->aggregate($selection, 'organizations');

        $this->assertSame($publicCode, $result['rows'][0]['code']);
        $this->assertStringNotContainsString($this->organization->id, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_publication_is_rechecked_when_reusing_a_structural_selection(): void
    {
        $this->publishTree();
        $selection = app(ReportFilterService::class)->forPublic([], 'states');
        $this->assertSame(1, $this->aggregate($selection, 'states')['totalTrees']);
        $this->organization->forceFill(['is_public' => false])->save();
        $this->assertSame(0, $this->aggregate($selection, 'states')['totalTrees']);
    }

    public function test_reports_keep_the_full_filtered_set_without_viewport_truncation(): void
    {
        $this->point(-73.5, 4);
        $this->point(-72, 5);
        $selection = $this->selection($this->bbox());

        $this->assertSame(3, $this->aggregate($selection, 'states')['totalTrees']);
        $radius = $this->selection(['radius_latitude' => 4, 'radius_longitude' => -73.5, 'radius_meters' => 100]);
        $this->assertSame(1, $this->aggregate($radius, 'states')['totalTrees']);
    }

    public function test_group_limits_fail_explicitly_instead_of_returning_a_partial_report(): void
    {
        $this->point(-73.5, 4, ['current_status' => 'dead']);
        config(['reports.max_aggregate_rows' => 1]);
        $this->expectException(ValidationException::class);
        $this->aggregate($this->selection(), 'states');
    }

    public function test_query_count_is_independent_of_the_number_of_individuals(): void
    {
        $selection = $this->selection();
        $small = $this->queryCount(fn () => $this->aggregate($selection, 'origins'));
        for ($i = 0; $i < 12; $i++) {
            $this->point(-73.5, 4);
        }
        $large = $this->queryCount(fn () => $this->aggregate($selection, 'origins'));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(4, $large);
    }

    public function test_shared_maintenance_predicate_rejects_an_external_sql_alias(): void
    {
        $selection = $this->selection();
        $this->expectException(InvalidArgumentException::class);
        app(TreeQueryRepository::class)->applyMaintenanceFilters(DB::query(), $selection->filters->trees, $selection->scope, 'orders;SELECT');
    }

    private function selection(array $input = []): ReportSelectionDTO
    {
        return app(ReportFilterService::class)->forUser($this->admin, $input, 'inventory');
    }

    private function aggregate(ReportSelectionDTO $selection, string $report): array
    {
        return app(ReportRepository::class)->aggregate($selection, $report);
    }

    private function groupCount(array $report, string $name): int
    {
        return array_sum(array_column(array_filter($report['rows'], fn (array $row) => $row['group'] === $name), 'trees'));
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}

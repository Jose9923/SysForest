<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\MapTreeFilterDTO;
use App\DTOs\ReportSelectionDTO;
use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Repositories\ExportReadRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\MapTreeRepository;
use App\Repositories\TreeInspectionRepository;
use App\Services\InventoryService;
use App\Services\TreeLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PublicPortalTestCase;

/** El lector conserva filtros, orden, campañas y la visita que seleccionó el motor compartido. */
class ExportQueryTest extends PublicPortalTestCase
{
    public function test_map_and_export_use_the_same_latest_visit_and_any_all_semantics(): void
    {
        $other = $this->point(-73.6, 4.1);
        $physical = $this->condition('PHY_ROOT_BAD_ANCHORAGE')->id;
        $phytosanitary = $this->condition('PHT_FOLIAGE_CHLOROSIS')->id;
        $this->capture(['inspection_date' => '2025-01-01', 'phytosanitary_condition_ids' => [$phytosanitary]]);
        $latest = $this->capture(['physical_condition_ids' => [$physical]]);
        $both = $this->capture(['physical_condition_ids' => [$physical], 'phytosanitary_condition_ids' => [$phytosanitary]], tree: $other);
        $filter = ['physical_condition_ids' => [$physical], 'phytosanitary_condition_ids' => [$phytosanitary], 'condition_mode' => 'all'];
        $this->assertSelected([$other->id], $this->selection($filter));
        $selection = $this->selection(array_replace($filter, ['condition_mode' => 'any']));
        $this->assertSelected([$this->tree->id, $other->id], $selection);
        $rows = collect($this->reader()->rows($selection, 1))->keyBy(fn (array $row) => $row['_source']['tree_id']);
        $this->assertSame($latest->id, $rows[$this->tree->id]['_source']['inspection_id']);
        $this->assertSame($both->id, $rows[$other->id]['_source']['inspection_id']);
        $this->assertSelected([], $this->selection(['phytosanitary_condition_ids' => [$phytosanitary], 'date_to' => '2025-12-31']));
    }

    public function test_inventory_participation_does_not_replace_latest_visit_with_campaign_history(): void
    {
        $second = app(InventoryService::class)->create(
            $this->admin,
            ['code' => 'EXPORT-SECOND', 'name' => 'Segunda campaña', 'status' => 'active', 'active' => true]
        );
        $pivot = app(InventoryRepository::class)->attach($this->organization->id, $second->id, $this->tree->id, $this->admin->id);
        $this->capture(['inspection_date' => '2025-01-01']);
        $latest = $this->capture(['inventory_tree_id' => $pivot->id]);
        $selection = $this->selection(['inventory_id' => $this->inventory->id]);
        $this->assertSelected([$this->tree->id], $selection);
        $this->assertSame($latest->id, collect($this->reader()->rows($selection))->sole()['_source']['inspection_id']);
        $this->assertSelected([], $this->selection(['inventory_id' => (string) Str::uuid()]));
    }

    public function test_public_latest_is_selected_before_filters_and_private_latest_remains_distinct(): void
    {
        $this->publishTree();
        $published = $this->publishInspection($this->capture(['inspection_date' => '2025-01-01', 'cap_cm' => '30']));
        $hidden = $this->capture(['cap_cm' => '200']);
        $this->assertSelected([$this->tree->id], $this->selection(['cap_cm_max' => '40'], TreeReadScope::published()));
        $this->assertSelected([], $this->selection(['cap_cm_min' => '100'], TreeReadScope::published()));
        $public = collect($this->reader()->rows($this->selection(scope: TreeReadScope::published())))->sole();
        $private = collect($this->reader()->rows($this->selection()))->sole();
        $this->assertSame($published->id, $public['_source']['inspection_id']);
        $this->assertSame($hidden->id, $private['_source']['inspection_id']);
    }

    public function test_latest_tie_break_uses_microseconds_then_uuid_instead_of_update_time(): void
    {
        $template = $this->capture()->getRawOriginal();
        $repository = app(TreeInspectionRepository::class);
        $repository->create(array_replace($template, ['id' => '00000000-0000-4000-8000-000000000010',
            'inspection_date' => '2027-01-01', 'created_at' => '2027-01-01 10:00:00.100000+00']));
        $high = $repository->create(array_replace($template, ['id' => '00000000-0000-4000-8000-000000000020',
            'inspection_date' => '2027-01-01', 'created_at' => '2027-01-01 10:00:00.200000+00']));
        $repository->create(array_replace($template, ['id' => '00000000-0000-4000-8000-000000000015',
            'inspection_date' => '2027-01-01', 'created_at' => '2027-01-01 10:00:00.200000+00']));
        $row = collect($this->reader()->rows($this->selection()))->sole();
        $this->assertSame($high->id, $row['_source']['inspection_id']);
    }

    public function test_multiorganization_batch_does_not_replace_or_mix_inspection_and_evidence_owners(): void
    {
        $ownInspection = $this->capture(['general_observations' => 'Visita propia']);
        $ownPhoto = $this->attachEvidence(inspectionId: $ownInspection->id);
        $foreignOrganization = $this->anotherOrganization($this->organization);
        $this->member($this->admin, $foreignOrganization);
        $this->allowManagement($this->admin);
        $this->activateInspectionTenant($this->admin, $foreignOrganization);
        $foreign = $this->point(-73.5, 4.2, ['code' => $this->tree->code], $foreignOrganization);
        $foreignInspection = $this->capture(['general_observations' => 'Visita ajena'], tree: $foreign);
        $foreignPhoto = $this->attachEvidence(inspectionId: $foreignInspection->id);
        $this->activateInspectionTenant();
        $this->assertSelected([$this->tree->id], $this->selection());
        $selection = $this->selection(scope: TreeReadScope::national());
        $rows = collect($this->reader()->rows($selection))->keyBy(fn (array $row) => $row['_source']['tree_id']);
        $this->assertCount(2, $rows);
        $this->assertSame('Visita propia', $rows[$this->tree->id]['general_observations']);
        $this->assertSame('Visita ajena', $rows[$foreign->id]['general_observations']);
        $this->assertSame([$ownPhoto->id], $rows[$this->tree->id]['_source']['evidence_ids']);
        $this->assertSame([$foreignPhoto->id], $rows[$foreign->id]['_source']['evidence_ids']);
        $this->assertNotSame($rows[$this->tree->id]['_identity'], $rows[$foreign->id]['_identity']);
        $this->assertNull($this->reader()->find($this->selection(), $foreign->id));
        $this->assertNull($this->reader()->find($selection, $this->tree->code));
    }

    public function test_viewport_does_not_cut_exports_but_radius_does_and_zero_coordinates_survive(): void
    {
        $center = $this->point(0.0, 0.0);
        $far = $this->point(1.0, 1.0);
        $base = $this->selection();
        $filters = new MapTreeFilterDTO(
            $base->filters->trees,
            ['west' => -74.0, 'south' => 3.0, 'east' => -73.0, 'north' => 5.0]
        );
        $this->assertSelected([$this->tree->id, $center->id, $far->id], new ReportSelectionDTO($base->scope, $filters, []));
        $radius = new MapTreeFilterDTO(
            $base->filters->trees,
            $filters->bbox,
            8,
            ['longitude' => 0.0, 'latitude' => 0.0, 'meters' => 100.0]
        );
        $this->assertSelected([$center->id], new ReportSelectionDTO($base->scope, $radius, []));
    }

    public function test_export_has_no_point_limit_or_page_limit_and_batches_keep_every_identity_once(): void
    {
        $rows = [];
        for ($index = 0; $index < 2001; $index++) {
            $rows[] = ['id' => (string) Str::uuid(), 'organization_id' => $this->organization->id,
                'code' => 'EXPORT-BULK-'.str_pad((string) $index, 5, '0', STR_PAD_LEFT), 'current_status' => 'active',
                'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('trees')->insert($chunk);
        }
        $selection = $this->selection(['code' => 'EXPORT-BULK-'], values: ['page' => 99]);
        $export = collect($this->reader()->rows($selection, 1000));
        $this->assertSame(2001, $this->reader()->count($selection));
        $this->assertCount(2001, $export);
        $this->assertCount(2001, $export->pluck('_identity')->unique());
        $this->assertSame('EXPORT-BULK-00000', $export->first()['code']);
        $this->assertSame('EXPORT-BULK-02000', $export->last()['code']);
    }

    public function test_paginate_uses_one_sql_page_and_the_same_sort_as_download(): void
    {
        foreach (['EXPORT-A', 'EXPORT-C', 'EXPORT-B', 'EXPORT-D'] as $code) {
            $this->point(-73.6, 4.1, ['code' => $code]);
        }
        $selection = $this->selection(['code' => 'EXPORT-'], values: ['sort' => 'code', 'direction' => 'desc']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = $this->reader()->paginate($selection, 2, 2);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(4, $page->total());
        $this->assertSame(['EXPORT-B', 'EXPORT-A'], $page->getCollection()->pluck('code')->all());
        $this->assertSame(['EXPORT-D', 'EXPORT-C', 'EXPORT-B', 'EXPORT-A'], collect($this->reader()->rows($selection, 1))->pluck('code')->all());
        $pageQueries = collect($queries)->filter(fn (array $query) => str_contains($query['query'], 'ST_X(trees.geometry)'));
        $this->assertCount(1, $pageQueries);
        $this->assertStringContainsString('limit 2 offset 2', $pageQueries->sole()['query']);
    }

    public function test_find_keeps_public_identity_scope_and_active_filters(): void
    {
        $this->publishTree();
        $public = $this->selection(scope: TreeReadScope::published());
        $this->assertNotNull($this->reader()->find($public, $this->tree->code, $this->organization->public_code));
        $this->assertNull($this->reader()->find($public, $this->tree->id, $this->organization->public_code));
        $this->assertNull($this->reader()->find($public, $this->tree->code));
        $this->assertNull($this->reader()->find($public, $this->tree->code, 'OTRA-ORGANIZACION'));
        $restricted = $this->selection(['current_status' => 'dead'], TreeReadScope::published());
        $this->assertNull($this->reader()->find($restricted, $this->tree->code, $this->organization->public_code));
        $this->assertNotNull($this->reader()->find($this->selection(), $this->tree->id));
        $this->tree->forceFill(['is_public' => false])->save();
        $this->assertNull($this->reader()->find($public, $this->tree->code, $this->organization->public_code));
    }

    public function test_inactive_organization_and_voided_tree_do_not_enter_downloads(): void
    {
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->lock_version,
            'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
        $this->assertSelected([], $this->selection());
        $this->point(-73.6, 4.1);
        $this->organization->forceFill(['active' => false])->save();
        $this->assertSelected([], $this->selection(scope: TreeReadScope::national()));
    }

    public function test_public_maintenance_filter_selects_completed_same_order_without_duplicates(): void
    {
        $this->publishTree();
        $pending = $this->order();
        $this->assertSelected([$this->tree->id], $this->selection(['maintenance_type_id' => $pending->maintenance_type_id]));
        $this->assertSelected([], $this->selection(['maintenance_type_id' => $pending->maintenance_type_id], TreeReadScope::published()));
        $completed = $this->executedOrder();
        $this->executedOrder();
        $this->assertSelected([$this->tree->id], $this->selection(['maintenance_type_id' => $completed->maintenance_type_id], TreeReadScope::published()));
    }

    public function test_public_universe_excludes_default_private_records_and_hidden_campaign_inspections(): void
    {
        $this->assertSelected([], $this->selection(scope: TreeReadScope::published()));
        $this->publishTree();
        $hiddenInventory = app(InventoryService::class)->create(
            $this->admin,
            ['code' => 'EXPORT-PRIVATE', 'name' => 'Campaña privada', 'status' => 'active', 'active' => true]
        );
        $pivot = app(InventoryRepository::class)->attach($this->organization->id, $hiddenInventory->id, $this->tree->id, $this->admin->id);
        $this->publishInspection($this->capture(['inventory_tree_id' => $pivot->id]));
        $row = collect($this->reader()->rows($this->selection(scope: TreeReadScope::published())))->sole();
        $this->assertNull($row['latest_inspection_date']);
        $this->assertNull($row['_source']['inspection_id']);
    }

    private function reader(): ExportReadRepository
    {
        return app(ExportReadRepository::class);
    }

    private function selection(array $filters = [], ?TreeReadScope $scope = null, array $values = []): ReportSelectionDTO
    {
        return new ReportSelectionDTO(
            $scope ?? TreeReadScope::organization($this->organization->id),
            new MapTreeFilterDTO(TreeFilterDTO::fromValidated($filters), null),
            $values
        );
    }

    private function assertSelected(array $ids, ReportSelectionDTO $selection): void
    {
        $map = app(MapTreeRepository::class)->query($selection->scope, $selection->filters, false)->get()->modelKeys();
        $rows = collect($this->reader()->rows($selection, 2));
        $this->assertEqualsCanonicalizing($ids, $map);
        $this->assertEqualsCanonicalizing($ids, $rows->map(fn (array $row) => $row['_source']['tree_id'])->all());
        $this->assertSame(count($ids), $this->reader()->count($selection));
    }
}

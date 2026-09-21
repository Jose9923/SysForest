<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Models\BiogeographicOrigin;
use App\Models\Department;
use App\Models\EstablishmentOrigin;
use App\Models\GrowthHabit;
use App\Models\MaintenanceType;
use App\Models\Municipality;
use App\Models\Permission;
use App\Models\Placement;
use App\Models\Role;
use App\Models\Species;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\TreeInspectionRepository;
use App\Repositories\TreeQueryRepository;
use App\Repositories\TreeRepository;
use App\Services\InventoryService;
use App\Services\MaintenanceService;
use App\Services\TreeFilterService;
use App\Services\TreeInspectionReadService;
use App\Services\TreeLifecycleService;
use App\Services\TreeReadService;
use App\Services\TreeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MaintenanceTestCase;

class TreeFilterEngineTest extends MaintenanceTestCase
{
    public function test_all_consumers_match_one_latest_visit_without_combining_condition_groups_or_history(): void
    {
        $other = $this->newTree();
        $physical = $this->condition('PHY_ROOT_BAD_ANCHORAGE')->id;
        $phytosanitary = $this->condition('PHT_FOLIAGE_CHLOROSIS')->id;
        $this->capture(['inspection_date' => '2025-01-01', 'phytosanitary_condition_ids' => [$phytosanitary]]);
        $latest = $this->capture(['inspection_date' => '2026-09-12', 'physical_condition_ids' => [$physical]]);
        $both = $this->capture(['inspection_date' => '2026-09-12', 'physical_condition_ids' => [$physical],
            'phytosanitary_condition_ids' => [$phytosanitary]], tree: $other);
        $filter = ['physical_condition_ids' => [$physical], 'phytosanitary_condition_ids' => [$phytosanitary], 'condition_mode' => 'all'];
        $this->assertConsumers([$other->id], $filter);
        $this->assertConsumers([$this->tree->id, $other->id], array_replace($filter, ['condition_mode' => 'any']));
        $this->assertConsumers([], ['phytosanitary_condition_ids' => [$phytosanitary], 'date_to' => '2025-12-31']);
        $rows = app(TreeQueryRepository::class)->query($this->scope(), $this->filters([]))
            ->addSelect('latest_inspection.id as latest_inspection_id')->get()->keyBy('id');
        $this->assertSame($latest->id, $rows[$this->tree->id]->latest_inspection_id);
        $this->assertSame($both->id, $rows[$other->id]->latest_inspection_id);
    }

    public function test_lateral_and_batch_latest_share_date_microseconds_and_uuid_order_not_update_time(): void
    {
        $old = $this->capture(['inspection_date' => '2025-01-01']);
        $repository = app(TreeInspectionRepository::class);
        $template = $old->getRawOriginal();
        $lower = $repository->create(array_replace($template, [
            'id' => '00000000-0000-4000-8000-000000000010', 'inspection_date' => '2026-09-12',
            'created_at' => '2026-09-12 10:00:00.200000+00',
        ]));
        $higher = $repository->create(array_replace($template, [
            'id' => '00000000-0000-4000-8000-000000000020', 'inspection_date' => '2026-09-12',
            'created_at' => '2026-09-12 10:00:00.200000+00',
        ]));
        $repository->create(array_replace($template, [
            'id' => (string) Str::uuid(), 'inspection_date' => '2026-09-12',
            'created_at' => '2026-09-12 10:00:00.100000+00',
        ]));
        $repository->update($old, ['general_observations' => 'Corrección reciente', 'lock_version' => 2]);
        $row = app(TreeQueryRepository::class)->query($this->scope(), $this->filters([]))
            ->addSelect('latest_inspection.id as latest_inspection_id')->sole();
        $this->assertSame($this->tree->id, $row->id);
        $this->assertSame($higher->id, $row->latest_inspection_id);
        $this->assertSame($higher->id, $repository->latestForTrees($this->organization->id, [$this->tree->id])->get($this->tree->id)->id);
        $this->assertNotSame($lower->id, $row->latest_inspection_id);
    }

    public function test_inventory_filters_tree_participation_without_redefining_the_latest_inspection(): void
    {
        $second = app(InventoryService::class)->create($this->admin, ['code' => 'SECOND', 'name' => 'Segunda campaña', 'status' => 'active', 'active' => true]);
        $pivot = app(InventoryRepository::class)->attach($this->organization->id, $second->id, $this->tree->id, $this->admin->id);
        $firstPivot = $this->tree->inventories()->where('inventories.id', $this->inventory->id)->firstOrFail()->pivot;
        $old = $this->capture(['inspection_date' => '2025-01-01', 'inventory_tree_id' => $firstPivot->id]);
        $latest = $this->capture(['inspection_date' => '2026-09-12', 'inventory_tree_id' => $pivot->id]);
        $this->assertConsumers([$this->tree->id], ['inventory_id' => $this->inventory->id]);
        $this->assertConsumers([$this->tree->id], ['inventory_id' => $second->id]);
        $page = app(TreeInspectionRepository::class)->latestTrees($this->organization->id, ['inventory_id' => $this->inventory->id]);
        $this->assertSame($latest->id, $page->getCollection()->sole()->latestInspection->id);
        $history = app(TreeInspectionRepository::class)->paginate($this->organization->id, ['inventory_id' => $this->inventory->id]);
        $this->assertSame([$old->id], $history->getCollection()->modelKeys());
        $this->assertConsumers([], ['inventory_id' => (string) Str::uuid()]);
    }

    public function test_optional_inspection_and_geometry_do_not_hide_unmeasured_trees(): void
    {
        $this->assertNull($this->tree->geometry);
        $this->assertConsumers([$this->tree->id], []);
        $this->assertConsumers([$this->tree->id], ['condition_mode' => 'all', 'physical_condition_ids' => []]);
        $this->assertConsumers([], ['date_from' => '2020-01-01']);
        $this->assertConsumers([], ['height_class_number' => 1]);
        $this->assertConsumers([], ['cap_cm_min' => '0']);
    }

    public function test_measurement_maturity_and_class_filters_apply_only_to_latest_numeric_results(): void
    {
        $this->capture(['inspection_date' => '2025-01-01', 'cap_cm' => '500', 'total_height_m' => '30']);
        $latest = $this->capture(['inspection_date' => '2026-09-12', 'cap_cm' => '20', 'total_height_m' => '2']);
        $filter = ['date_from' => '2026-09-12', 'date_to' => '2026-09-12', 'cap_cm_max' => '20',
            'dap_m_min' => '0.06', 'dap_m_max' => '0.07', 'total_height_m_min' => '2', 'total_height_m_max' => '2',
            'maturity_state_id' => $latest->maturity_state_id, 'diameter_class_number' => $latest->diameter_class_number,
            'height_class_number' => $latest->height_class_number];
        $this->assertConsumers([$this->tree->id], $filter);
        $this->assertConsumers([], ['cap_cm_min' => '100']);
        $this->assertSame(1, app(TreeInspectionRepository::class)->paginate($this->organization->id, ['cap_cm_min' => '100'])->total());
    }

    public function test_taxonomy_origins_habits_placement_and_boolean_filters_keep_distinct_meanings(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $placement = Placement::query()->where('code', 'PLC_SIDEWALK')->sole();
        $habit = GrowthHabit::query()->where('code', 'HAB_TREE')->sole();
        $establishment = EstablishmentOrigin::query()->where('code', 'EST_PLANTED')->sole();
        $biogeography = BiogeographicOrigin::query()->where('code', 'BIO_NATIVE')->sole();
        $matching = $this->newTree(['species_id' => $species->id, 'placement_id' => $placement->id,
            'growth_habit_id' => $habit->id, 'establishment_origin_id' => $establishment->id,
            'biogeographic_origin_id' => $biogeography->id, 'is_protected' => true, 'is_heritage' => false]);
        $filter = ['species_id' => $species->id, 'family_id' => $species->family_id,
            'common_name' => 'Oití', 'scientific_name' => 'tomentosa', 'placement_id' => $placement->id,
            'growth_habit_id' => $habit->id, 'establishment_origin_id' => $establishment->id,
            'biogeographic_origin_id' => $biogeography->id, 'is_protected' => '1', 'is_heritage' => '0', 'code' => $matching->code];
        $this->assertConsumers([$matching->id], $filter);
        $this->assertConsumers([], array_replace($filter, ['is_heritage' => '1']));
        $this->assertConsumers([$this->tree->id], ['is_protected' => '0']);
        $this->assertConsumers([], ['establishment_origin_id' => $biogeography->id]);
        $this->assertConsumers([], ['biogeographic_origin_id' => $establishment->id]);
    }

    public function test_inactive_catalog_references_remain_searchable_without_becoming_available_for_capture(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $tree = $this->newTree(['species_id' => $species->id]);
        $condition = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $this->capture(['phytosanitary_condition_ids' => [$condition->id]], tree: $tree);
        $species->update(['active' => false]);
        $species->family->update(['active' => false]);
        $species->commonNames()->update(['active' => false]);
        $condition->update(['active' => false]);
        $condition->category->update(['active' => false]);
        $this->assertConsumers([$tree->id], ['species_id' => $species->id, 'common_name' => 'Oití',
            'phytosanitary_condition_ids' => [$condition->id]]);
    }

    public function test_global_search_retains_legacy_fields_and_escapes_wildcards_as_literals(): void
    {
        $special = $this->newTree(['external_number' => 'LEGADO-%_\\', 'location_description' => 'Patio oriental único']);
        $this->newTree(['external_number' => 'LEGADO-ABC']);
        $this->assertConsumers([$special->id], ['q' => '%_\\']);
        $this->assertConsumers([$special->id], ['q' => 'Patio oriental']);
        $this->assertConsumers([], ['q' => "%' OR TRUE --"]);
        $criteria = $this->filters(['q' => '0', 'is_protected' => '0']);
        $this->assertSame('0', $criteria->get('q'));
        $this->assertFalse($criteria->get('is_protected'));
        $this->assertContains('%0%', app(TreeQueryRepository::class)->query($this->scope(), $criteria)->getBindings());
    }

    public function test_territorial_filters_only_narrow_the_authorized_scope(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $this->member($this->admin, $other);
        $this->allowManagement($this->admin);
        $this->activateInspectionTenant($this->admin, $other);
        $inventory = app(InventoryService::class)->create($this->admin, ['code' => 'OTHER', 'name' => 'Ajeno', 'status' => 'active', 'active' => true]);
        $foreign = app(TreeService::class)->create($this->admin, ['inventory_ids' => [$inventory->id], 'current_status' => 'active']);
        $this->activateInspectionTenant();
        $filters = ['department_id' => $this->organization->municipality->department_id,
            'municipality_id' => $this->organization->municipality_id, 'organization_id' => $this->organization->id];
        $this->assertConsumers([$this->tree->id], $filters);
        $this->assertConsumers([], ['organization_id' => $other->id]);
        $this->assertConsumers([], ['inventory_id' => $inventory->id]);
        $national = app(TreeQueryRepository::class)->query(TreeReadScope::national(), $this->filters(['organization_id' => $other->id]))->get();
        $this->assertSame([$foreign->id], $national->modelKeys());
        $department = app(TreeQueryRepository::class)->query(TreeReadScope::department([$this->organization->municipality->department_id]), $this->filters([]))->get();
        $this->assertEqualsCanonicalizing([$this->tree->id, $foreign->id], $department->modelKeys());
        $anotherDepartment = Department::query()->create(['country_id' => $this->organization->municipality->department->country_id,
            'code' => 'FILTER-ALT', 'name' => 'Departamento de prueba de filtros', 'active' => true]);
        $this->assertCount(0, app(TreeQueryRepository::class)->query(TreeReadScope::department([$anotherDepartment->id]), $this->filters([]))->get());
        $municipality = Municipality::query()->create(['department_id' => $anotherDepartment->id,
            'code' => 'FILTER-MUN', 'name' => 'Municipio de prueba de filtros', 'active' => true]);
        $this->assertConsumers([], ['municipality_id' => $municipality->id]);
    }

    public function test_maintenance_filters_match_one_nonvoided_order_and_never_duplicate_trees(): void
    {
        $other = $this->newTree();
        $pruning = MaintenanceType::query()->where('code', 'MNT_PRUNING')->sole()->id;
        $watering = MaintenanceType::query()->where('code', 'MNT_IRRIGATION')->sole()->id;
        $this->order(['maintenance_type_id' => $pruning, 'priority' => 'low']);
        $this->order(['maintenance_type_id' => $watering, 'priority' => 'urgent']);
        $this->order(['maintenance_type_id' => $pruning, 'priority' => 'urgent'], tree: $other);
        $this->order(['maintenance_type_id' => $pruning, 'priority' => 'urgent'], tree: $other);
        $this->assertConsumers([$other->id], ['maintenance_type_id' => $pruning, 'maintenance_priority' => 'urgent',
            'maintenance_status' => 'pending', 'has_pending_maintenance' => true]);
        $this->assertConsumers([$this->tree->id, $other->id], ['maintenance_type_id' => $pruning]);
        $this->assertConsumers([$this->tree->id, $other->id], ['has_pending_maintenance' => true]);
    }

    public function test_cancelled_and_voided_maintenance_do_not_satisfy_default_filters(): void
    {
        $pruning = MaintenanceType::query()->where('code', 'MNT_PRUNING')->sole()->id;
        $cancelled = $this->advance($this->order(), 'cancelled', ['reason' => 'No se realizará']);
        $voided = $this->order();
        app(MaintenanceService::class)->void($this->admin, $voided->id, ['lock_version' => 1, 'deletion_notes' => 'Duplicada']);
        $this->assertConsumers([], ['maintenance_type_id' => $pruning]);
        $this->assertConsumers([], ['has_pending_maintenance' => true]);
        $this->assertConsumers([$this->tree->id], ['maintenance_status' => $cancelled->status->value]);
        $this->assertConsumers([$this->tree->id], ['has_pending_maintenance' => false]);
    }

    public function test_pending_checkbox_includes_scheduled_but_not_in_progress(): void
    {
        $scheduled = $this->advance($this->order(), 'scheduled');
        $other = $this->newTree();
        $working = $this->order(['assigned_to' => $this->admin->id], tree: $other);
        $working = $this->advance($working, 'assigned');
        $this->advance($working, 'in_progress');
        $this->assertSame('scheduled', $scheduled->status->value);
        $this->assertConsumers([$this->tree->id], ['has_pending_maintenance' => true]);
        $this->assertConsumers([$other->id], ['maintenance_status' => 'in_progress']);
        $this->assertConsumers([], ['maintenance_status' => 'in_progress', 'has_pending_maintenance' => true]);
    }

    public function test_softdelete_and_real_tree_status_remain_independent_and_paperbin_requires_permission(): void
    {
        $dead = $this->newTree(['current_status' => 'dead']);
        $felled = $this->newTree(['current_status' => 'felled']);
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->lock_version, 'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
        $this->assertConsumers([$dead->id, $felled->id], []);
        $this->assertConsumers([$dead->id], ['current_status' => 'dead']);
        $this->assertConsumers([$this->tree->id], ['trashed' => 'only']);
        $official = User::factory()->create();
        $this->member($official, $this->organization);
        $this->activateInspectionTenant($official);
        $this->expectException(AuthorizationException::class);
        app(TreeReadService::class)->index($official, ['trashed' => 'only']);
    }

    public function test_sort_and_latest_relationship_are_preserved_by_repository_wrappers(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $tree = $this->newTree(['species_id' => $species->id]);
        $latest = $this->capture(tree: $tree);
        $filters = ['sort' => 'scientific_name', 'direction' => 'asc'];
        $treePage = app(TreeRepository::class)->paginate($this->organization->id, $filters);
        $visitPage = app(TreeInspectionRepository::class)->latestTrees($this->organization->id, $filters);
        $this->assertSame($treePage->getCollection()->modelKeys(), $visitPage->getCollection()->modelKeys());
        $this->assertSame(20, $treePage->perPage());
        $this->assertTrue($treePage->getCollection()->first()->relationLoaded('species'));
        $this->assertTrue($visitPage->getCollection()->first()->relationLoaded('latestInspection'));
        $this->assertSame($latest->id, $visitPage->getCollection()->firstWhere('id', $tree->id)->latestInspection->id);
    }

    public function test_common_validator_preserves_nulls_zero_and_optional_bounds(): void
    {
        $filters = $this->filters(['q' => '0', 'date_from' => ' ', 'cap_cm_min' => '', 'cap_cm_max' => '0',
            'is_protected' => '0', 'is_heritage' => '1', 'has_pending_maintenance' => '0']);
        $this->assertSame('0', $filters->get('q'));
        $this->assertNull($filters->values()['date_from']);
        $this->assertNull($filters->values()['cap_cm_min']);
        $this->assertSame('0', $filters->get('cap_cm_max'));
        $this->assertFalse($filters->get('is_protected'));
        $this->assertTrue($filters->get('is_heritage'));
        $this->assertTrue($filters->hasInspectionFilters());
        $this->assertFalse($filters->hasMaintenanceFilters());
    }

    #[DataProvider('invalidFilters')]
    public function test_common_validator_rejects_invalid_ranges_and_identifiers(array $input, string $field): void
    {
        try {
            $this->filters($input);
            $this->fail('El filtro inválido debe rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidFilters(): array
    {
        return [
            [['date_from' => '2026-09-13', 'date_to' => '2026-09-12'], 'date_to'],
            [['cap_cm_min' => '2', 'cap_cm_max' => '1'], 'cap_cm_max'],
            [['dap_m_min' => '1.000000000001', 'dap_m_max' => '1'], 'dap_m_max'],
            [['total_height_m_min' => '3', 'total_height_m_max' => '2'], 'total_height_m_max'],
            [['cap_cm_min' => '1e3'], 'cap_cm_min'],
            [['family_id' => "' OR TRUE --"], 'family_id'],
            [['is_protected' => 'yes'], 'is_protected'],
            [['maintenance_status' => 'invented'], 'maintenance_status'],
            [['condition_mode' => 'some'], 'condition_mode'],
        ];
    }

    public function test_condition_group_validation_is_shared_by_tree_and_latest_readers(): void
    {
        $wrong = ['physical_condition_ids' => [$this->condition('PHT_FOLIAGE_CHLOROSIS')->id]];
        foreach ([TreeReadService::class, TreeInspectionReadService::class] as $service) {
            try {
                $service === TreeReadService::class ? app($service)->index($this->admin, $wrong)
                    : app($service)->latestTrees($this->admin, $wrong);
                $this->fail('Cada consumidor debe comprobar la evaluación de la condición.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('physical_condition_ids', $exception->errors());
            }
        }
    }

    #[DataProvider('restrictedDimensions')]
    public function test_tree_list_cannot_bypass_revoked_module_read_permission(string $permission, array $filter): void
    {
        $official = User::factory()->create();
        $this->member($official, $this->organization);
        Role::query()->where('name', 'OFFICIAL')->sole()->permissions()->detach(Permission::query()->where('code', $permission)->sole()->id);
        $this->activateInspectionTenant($official);
        $this->expectException(AuthorizationException::class);
        app(TreeReadService::class)->index($official, $filter);
    }

    public static function restrictedDimensions(): array
    {
        return [['inspections.view', ['date_from' => '2026-01-01']], ['maintenances.view', ['has_pending_maintenance' => true]]];
    }

    private function newTree(array $overrides = []): Tree
    {
        return app(TreeService::class)->create($this->admin, array_replace([
            'inventory_ids' => [$this->inventory->id], 'current_status' => 'active',
        ], $overrides));
    }

    private function filters(array $input): TreeFilterDTO
    {
        return app(TreeFilterService::class)->validate($input);
    }

    private function scope(): TreeReadScope
    {
        return TreeReadScope::organization($this->organization->id);
    }

    private function assertConsumers(array $ids, array $input): void
    {
        $filters = $this->filters($input);
        $engine = app(TreeQueryRepository::class)->query($this->scope(), $filters)->get();
        $list = app(TreeRepository::class)->paginate($this->organization->id, $filters->values());
        $latest = app(TreeInspectionRepository::class)->latestTrees($this->organization->id, $filters->values());
        $this->assertEqualsCanonicalizing($ids, $engine->modelKeys());
        $this->assertEqualsCanonicalizing($ids, $list->getCollection()->modelKeys());
        $this->assertEqualsCanonicalizing($ids, $latest->getCollection()->modelKeys());
        $this->assertSame(count($ids), $list->total());
        $this->assertSame(count($ids), $latest->total());
    }
}

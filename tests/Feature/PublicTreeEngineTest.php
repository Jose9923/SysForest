<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\PublicTreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Models\Species;
use App\Repositories\InventoryRepository;
use App\Repositories\MapTreeRepository;
use App\Repositories\PublicReferenceRepository;
use App\Repositories\PublicTreeRepository;
use App\Repositories\TreeInspectionRepository;
use App\Repositories\TreeQueryRepository;
use App\Services\EvidenceService;
use App\Services\InventoryService;
use App\Services\MaintenanceService;
use App\Services\PublicTreeFilterService;
use App\Services\TreeFilterService;
use App\Services\TreeLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PublicPortalTestCase;

class PublicTreeEngineTest extends PublicPortalTestCase
{
    public function test_default_private_records_are_absent_from_list_map_indicators_and_detail(): void
    {
        $filters = $this->filters();
        $this->assertSame(0, $this->repository()->paginate($filters)->total());
        $this->assertSame(0, $this->repository()->indicators($filters)['trees']);
        $this->assertCount(0, app(MapTreeRepository::class)->points(TreeReadScope::published(), $filters->map, 100));
        $this->expectException(ModelNotFoundException::class);
        $this->repository()->find($this->organization->code, $this->tree->code);
    }

    #[DataProvider('publicationLinks')]
    public function test_each_publication_link_is_required_and_revalidated(string $link): void
    {
        $this->publishTree();
        $this->assertSame(1, $this->repository()->paginate($this->filters())->total());
        [$model, $attributes] = match ($link) {
            'organization_public' => [$this->organization, ['is_public' => false]],
            'organization_active' => [$this->organization, ['active' => false]],
            'inventory_public' => [$this->inventory, ['is_public' => false]],
            'inventory_active' => [$this->inventory, ['active' => false]],
            'inventory_draft' => [$this->inventory, ['status' => 'draft']],
            'tree_public' => [$this->tree, ['is_public' => false]],
            'municipality_active' => [$this->organization->municipality, ['active' => false]],
            'department_active' => [$this->organization->municipality->department, ['active' => false]],
            'country_active' => [$this->organization->municipality->department->country, ['active' => false]],
        };
        $model->forceFill($attributes)->save();
        $this->assertSame(0, $this->repository()->paginate($this->filters())->total());
        $this->assertSame(0, $this->repository()->indicators($this->filters())['trees']);
    }

    public static function publicationLinks(): array
    {
        return array_map(fn ($value) => [$value], ['organization_public', 'organization_active', 'inventory_public',
            'inventory_active', 'inventory_draft', 'tree_public', 'municipality_active', 'department_active', 'country_active']);
    }

    public function test_archived_public_campaign_keeps_history_and_repeated_participation_does_not_duplicate(): void
    {
        $this->publishTree();
        $this->inventory->forceFill(['status' => 'archived'])->save();
        $second = $this->newInventory('SECOND');
        $this->publishTree($this->tree, $second);
        $this->assertSame(1, $this->repository()->paginate($this->filters())->total());
        $this->assertSame(1, $this->repository()->indicators($this->filters())['trees']);
        $this->assertSame(1, $this->repository()->paginate($this->filters([
            'inventory_id' => $this->organization->public_code.'~'.$this->inventory->code,
        ]))->total());
    }

    public function test_public_query_cannot_infer_private_search_fields(): void
    {
        $this->publishTree();
        $this->tree->forceFill(['external_number' => 'RESERVADO-LEGACY', 'location_description' => 'RESERVADO-LOCALIZACION'])->save();
        foreach (['RESERVADO-LEGACY', 'RESERVADO-LOCALIZACION'] as $term) {
            $this->assertSame(0, $this->repository()->paginate($this->filters(['q' => $term]))->total());
            $private = app(TreeFilterService::class)->validate(['q' => $term]);
            $this->assertSame(1, app(TreeQueryRepository::class)->query(TreeReadScope::organization($this->organization->id), $private)->count());
        }
        $this->assertSame(1, $this->repository()->paginate($this->filters(['q' => $this->tree->code]))->total());
        $this->assertSame(0, $this->repository()->paginate($this->filters(['q' => "%' OR TRUE --"]))->total());
    }

    public function test_latest_public_visit_is_chosen_before_numeric_and_condition_filters(): void
    {
        $this->publishTree();
        $physical = $this->condition('PHY_ROOT_BAD_ANCHORAGE');
        $phytosanitary = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $this->publishInspection($this->capture(['inspection_date' => '2025-01-01',
            'phytosanitary_condition_ids' => [$phytosanitary->id]]));
        $public = $this->publishInspection($this->capture(['inspection_date' => '2026-01-01', 'cap_cm' => '20',
            'physical_condition_ids' => [$physical->id]]));
        $this->capture(['inspection_date' => '2026-09-12', 'cap_cm' => '300',
            'phytosanitary_condition_ids' => [$phytosanitary->id]]);
        $physical->update(['active' => false]);
        $physical->category->update(['active' => false]);
        $criteria = ['physical_condition_ids' => [$physical->code], 'phytosanitary_condition_ids' => [$phytosanitary->code]];
        $this->assertSame(1, $this->repository()->paginate($this->filters($criteria + ['condition_mode' => 'any']))->total());
        $this->assertSame(0, $this->repository()->paginate($this->filters($criteria + ['condition_mode' => 'all']))->total());
        $this->assertSame(1, $this->repository()->paginate($this->filters(['cap_cm_max' => '20']))->total());
        $this->assertSame(0, $this->repository()->paginate($this->filters(['date_to' => '2025-12-31']))->total());
        $detail = $this->repository()->find($this->organization->public_code, $this->tree->code);
        $this->assertSame($public->id, $detail->publicLatestInspection->id);
        $this->assertSame(2, $this->repository()->inspections($detail)->total());
    }

    public function test_latest_tie_break_is_identical_in_private_public_detail_and_history(): void
    {
        $this->publishTree();
        $visit = $this->publishInspection($this->capture());
        $template = $visit->getRawOriginal();
        $repository = app(TreeInspectionRepository::class);
        foreach (['00000000-0000-4000-8000-000000000010', '00000000-0000-4000-8000-000000000020'] as $id) {
            $repository->create(array_replace($template, ['id' => $id,
                'inspection_date' => '2026-09-13', 'created_at' => '2026-09-13 10:00:00.123456+00']));
        }
        $public = $this->repository()->find($this->organization->public_code, $this->tree->code);
        $private = app(TreeQueryRepository::class)->query(
            TreeReadScope::organization($this->organization->id),
            app(TreeFilterService::class)->validate([])
        )->addSelect('latest_inspection.id as latest_id')->sole();
        $this->assertSame($id, $public->publicLatestInspection->id);
        $this->assertSame($id, $private->latest_id);
        $this->assertSame($id, $this->repository()->inspections($public)->items()[0]->id);
    }

    public function test_private_campaign_hides_its_visit_even_when_another_campaign_publishes_the_tree(): void
    {
        $this->publishTree();
        $private = $this->newInventory('PRIVATE');
        $pivot = app(InventoryRepository::class)->attach($this->organization->id, $private->id, $this->tree->id, $this->admin->id);
        $visit = $this->publishInspection($this->capture(['inventory_tree_id' => $pivot->id]));
        $this->assertSame(0, $this->repository()->inspections($this->tree)->total());
        $this->assertNull($this->repository()->find($this->organization->public_code, $this->tree->code)->publicLatestInspection);
        $this->publishInventory($private);
        $this->assertSame($visit->id, $this->repository()->find($this->organization->public_code, $this->tree->code)->publicLatestInspection->id);
    }

    public function test_maintenance_type_and_public_status_apply_to_the_same_order(): void
    {
        $this->publishTree();
        $completed = $this->executedOrder();
        $this->order(['maintenance_type_id' => DB::table('maintenance_types')->where('code', 'MNT_IRRIGATION')->value('id')]);
        $this->assertSame(0, $this->repository()->paginate($this->filters(['maintenance_type_id' => 'MNT_IRRIGATION']))->total());
        $this->assertSame(1, $this->repository()->paginate($this->filters(['maintenance_type_id' => 'MNT_PRUNING', 'maintenance_status' => 'executed']))->total());
        $this->assertSame([$completed->id], $this->repository()->maintenances($this->tree)->getCollection()->modelKeys());
        app(MaintenanceService::class)->void(
            $this->admin,
            $completed->id,
            ['lock_version' => $completed->lock_version, 'deletion_notes' => 'Prueba de exclusión pública']
        );
        $this->assertSame(0, $this->repository()->maintenances($this->tree)->total());
    }

    public function test_three_evidence_owner_chains_are_revalidated_without_exposing_private_photos(): void
    {
        $this->publishTree();
        $treePhoto = $this->publishEvidence($this->attachEvidence(), 'Árbol publicado');
        $visit = $this->publishInspection($this->capture());
        $visitPhoto = $this->publishEvidence($this->attachEvidence(inspectionId: $visit->id));
        $order = $this->executedOrder();
        $orderPhoto = $this->publishEvidence(app(EvidenceService::class)->upload(
            $this->admin,
            null,
            null,
            $this->evidenceInput(['evidence_type_id' => $this->evidenceType('EVD_MAINTENANCE_AFTER')->id]),
            $order->id
        )->sole());
        $this->attachEvidence();
        $this->assertEqualsCanonicalizing(
            [$treePhoto->id, $visitPhoto->id, $orderPhoto->id],
            $this->repository()->evidences($this->tree)->getCollection()->modelKeys()
        );
        $this->assertSame($visitPhoto->id, $this->repository()->findEvidence($visitPhoto->public_id)->id);
        $visit->forceFill(['is_public' => false])->save();
        $this->assertSame(2, $this->repository()->evidences($this->tree)->total());
        $this->expectException(ModelNotFoundException::class);
        $this->repository()->findEvidence($visitPhoto->public_id);
    }

    public function test_public_tree_softdelete_hides_evidence_and_preserves_non_active_real_states(): void
    {
        $this->publishTree();
        $photo = $this->publishEvidence($this->attachEvidence());
        $this->tree->forceFill(['current_status' => 'dead'])->save();
        $this->assertSame(1, $this->repository()->paginate($this->filters(['current_status' => 'dead']))->total());
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->lock_version, 'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
        $this->assertSame(0, $this->repository()->evidences($this->tree)->total());
        $this->expectException(ModelNotFoundException::class);
        $this->repository()->findEvidence($photo->public_id);
    }

    public function test_map_list_indicators_and_code_options_share_published_universe_and_zero_filters(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $point = $this->point(-73.6, 4.1, ['species_id' => $species->id]);
        $this->publishTree();
        $this->publishTree($point);
        $filters = $this->filters($this->bbox(['organization_id' => $this->organization->public_code, 'is_protected' => '0']));
        $map = app(MapTreeRepository::class);
        $points = $map->points(TreeReadScope::published(), $filters->map, 100);
        $this->assertSame([$point->id], $points->modelKeys());
        $this->assertSame($this->organization->public_code, $points->sole()->organization->public_code);
        $this->assertSame(2, $this->repository()->paginate($filters)->total());
        $indicators = $this->repository()->indicators($filters);
        $this->assertSame(2, $indicators['trees']);
        $this->assertSame(1, $indicators['georeferenced']);
        $this->assertSame(1, $indicators['without_geometry']);
        $this->assertSame(1, $indicators['species']);
        $this->assertSame(1, $indicators['families']);
        $options = app(PublicReferenceRepository::class);
        $this->assertSame($this->organization->public_code, $options->options('organizations', $filters)['results'][0]['id']);
        $this->assertSame($this->organization->public_code, $options->selected($filters)['organization_id'][0]['id']);
        $this->assertFalse($filters->trees->get('is_protected'));
        $this->assertSame([[$point->latitude ?? 4.1, -73.6], [4.1, -73.6]], $map->extent(TreeReadScope::published(), $filters->map));
        $radius = $this->filters(['radius_latitude' => 4.1, 'radius_longitude' => -73.6, 'radius_meters' => 0]);
        $this->assertSame(1, $this->repository()->paginate($radius)->total());
    }

    public function test_public_sorting_uses_allowlist_and_keeps_public_codes_in_pagination(): void
    {
        $this->publishTree();
        foreach (PublicTreeFilterService::SORTS as $sort) {
            $filters = $this->filters(['sort' => $sort, 'direction' => 'desc', 'organization_id' => $this->organization->public_code]);
            $page = $this->repository()->paginate($filters);
            $this->assertSame(1, $page->total());
            $this->assertStringContainsString('organization_id='.urlencode($this->organization->public_code), $page->url(2));
            $this->assertStringNotContainsString($this->organization->id, $page->url(2));
        }
    }

    #[DataProvider('forbiddenFilters')]
    public function test_private_or_invalid_filter_fields_are_rejected(array $input, string $field): void
    {
        try {
            $this->filters($input);
            $this->fail('El filtro público no debe aceptar el criterio reservado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function forbiddenFilters(): array
    {
        return [[['scope' => 'national'], 'scope'], [['trashed' => 'only'], 'trashed'],
            [['tree_id' => '00000000-0000-4000-8000-000000000020'], 'tree_id'],
            [['has_pending_maintenance' => false], 'has_pending_maintenance'],
            [['maintenance_priority' => 'normal'], 'maintenance_priority'],
            [['maintenance_status' => 'pending'], 'maintenance_status'],
            [['physical_condition_ids' => ['PHT_FOLIAGE_CHLOROSIS']], 'physical_condition_ids'],
            [['department_id' => '50'], 'department_id'], [['organization_id' => 'PRIVATE'], 'organization_id'],
            [['sort' => 'created_at'], 'sort'], [['unknown' => 'x'], 'unknown']];
    }

    private function filters(array $input = []): PublicTreeFilterDTO
    {
        return app(PublicTreeFilterService::class)->validate($input);
    }

    private function repository(): PublicTreeRepository
    {
        return app(PublicTreeRepository::class);
    }

    private function newInventory(string $code): \App\Models\Inventory
    {
        return app(InventoryService::class)->create(
            $this->admin,
            ['code' => $code, 'name' => 'Campaña de prueba '.$code, 'status' => 'active', 'active' => true]
        );
    }
}

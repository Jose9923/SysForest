<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Enums\Inventories\InventoryStatus;
use App\Enums\Trees\TreeStatus;
use App\Support\Mobile\MobileInventoryProjection;
use App\Models\User;
use App\Repositories\Mobile\MobileReferenceRepository;
use App\Validation\Mobile\MobileReferenceInput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Services\Catalogs\CatalogAccessService;
use App\Services\Forestry\ForestryAccessService;

/** Proyecciones explícitas para captura sin conexión, sin exponer auditoría ni autores. */
class MobileReferenceService
{
    public function __construct(
        private MobileReferenceRepository $references,
        private CatalogAccessService $catalogAccess,
        private ForestryAccessService $forestryAccess,
    ) {
    }

    public function manifest(User $actor): array
    {
        $organization = $this->forestryAccess->organization($actor);
        $this->catalogAccess->authorizeView($actor);

        return ['data' => [
            'organization_id' => $organization->id,
            'catalogs' => array_keys(MobileReferenceRepository::CATALOGS),
            'tree_statuses' => TreeStatus::options(),
            'inventory_statuses' => InventoryStatus::options(),
            'assessment_types' => ['physical_mechanical' => 'Físico-mecánico', 'phytosanitary' => 'Fitosanitario'],
            'pagination' => ['default_per_page' => 100, 'max_per_page' => 500],
            'cache_refresh' => 'full_replacement_after_all_pages',
            'active_only' => true,
            'classification' => 'server_computed',
            'supports_inventory_history_reads' => true,
            'supports_risk_inbox' => true,
            'supports_risk_preview' => true,
            'supports_risk_confirmation' => true,
        ], 'meta' => ['generated_at' => now()->toIso8601String()]];
    }

    public function catalog(User $actor, string $catalog, array $input): array
    {
        $this->forestryAccess->organization($actor);
        $this->catalogAccess->authorizeView($actor);
        $filters = $this->filters($input);
        $page = $this->references->catalog($catalog, $filters);

        return ['data' => $page->getCollection()->map(fn (Model $record) => $this->reference($catalog, $record))->all(),
            'meta' => $this->pagination($page) + ['catalog' => $catalog, 'active_only' => true]];
    }

    public function inventories(User $actor, array $input): array
    {
        $organization = $this->forestryAccess->organization($actor);
        $this->forestryAccess->authorize($actor, 'inventories.view');
        $page = $this->references->inventories($organization->id, $this->filters($input, true));
        $canReadTrees = $this->forestryAccess->allows($actor, 'trees.view');
        $canReadInspections = $canReadTrees && $this->forestryAccess->allows($actor, 'inspections.view');
        $canReadPhotos = $canReadTrees && $this->forestryAccess->allows($actor, 'evidences.view');
        $photoSources = $canReadPhotos ? ['tree'] : [];
        if ($canReadPhotos && $canReadInspections) {
            $photoSources[] = 'inspection';
        }
        if ($canReadPhotos && $this->forestryAccess->allows($actor, 'maintenances.view')) {
            $photoSources[] = 'maintenance';
        }

        return ['data' => $page->getCollection()->map(MobileInventoryProjection::make(...))->all(), 'meta' => $this->pagination($page) + [
            'organization_id' => $organization->id,
            'supports_inventory_creation' => true,
            'supports_inventory_history_reads' => true,
            'can_view_inventory_trees' => $canReadTrees,
            'can_view_reports' => $canReadTrees && $this->forestryAccess->allows($actor, 'reports.view'),
            'can_view_inventory_inspections' => $canReadInspections,
            'can_view_inventory_photos' => $canReadPhotos,
            'inventory_photo_sources' => $photoSources,
            'supports_maintenance_reads' => true,
            'supports_risk_inbox' => true,
            'supports_risk_preview' => true,
            'supports_risk_confirmation' => true,
            'can_view_risk_inbox' => $this->forestryAccess->allows($actor, 'risk.view')
                && $this->forestryAccess->allows($actor, 'trees.view')
                && $this->forestryAccess->allows($actor, 'inspections.view')
                && $this->forestryAccess->allows($actor, 'inventories.view'),
            'can_confirm_risk' => $this->forestryAccess->allows($actor, 'risk.view')
                && ($this->forestryAccess->allows($actor, 'inspections.create')
                    || $this->forestryAccess->allows($actor, 'inspections.update')),
            'can_create_inventories' => $this->forestryAccess->allows($actor, 'inventories.create'),
            'can_view_maintenances' => $this->forestryAccess->allows($actor, 'maintenances.view')
                && $this->forestryAccess->allows($actor, 'trees.view'),
            'can_create_trees' => $this->forestryAccess->allows($actor, 'trees.create'),
            'can_create_inspections' => $this->forestryAccess->allows($actor, 'inspections.create'),
            'can_update_trees' => $this->forestryAccess->allows($actor, 'trees.update'),
            'can_update_inspections' => $this->forestryAccess->allows($actor, 'inspections.update'),
            'can_upload_photos' => $this->forestryAccess->allows($actor, 'evidences.create'),
        ]];
    }

    private function reference(string $catalog, Model $record): array
    {
        $taxonomy = in_array($catalog, ['species', 'families'], true);
        $data = [
            'id' => $record->id,
            'code' => $record->code,
            'name' => $taxonomy ? $record->scientific_name : $record->name,
            'description' => \App\Support\Imports\ExcelReferencePresentation::text($record->description),
            'active' => (bool) $record->active,
            'sort_order' => (int) $record->sort_order,
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
        if ($taxonomy) {
            $data['scientific_name'] = $record->scientific_name;
        }
        if ($catalog === 'species') {
            $data['family_id'] = $record->family_id;
            $data['common_names'] = $record->commonNames->map(fn (Model $name) => [
                'id' => $name->id, 'code' => $name->code, 'name' => $name->name, 'is_primary' => (bool) $name->is_primary,
            ])->all();
        }
        if (in_array($catalog, ['condition_categories', 'physical_conditions', 'phytosanitary_conditions'], true)) {
            $data['assessment_type'] = $record->assessment_type;
        }
        if (in_array($catalog, ['physical_conditions', 'phytosanitary_conditions'], true)) {
            $data['condition_category_id'] = $record->condition_category_id;
        }

        return $data;
    }

    private function filters(array $input, bool $inventories = false): array
    {
        $filters = Validator::make($input, MobileReferenceInput::rules($inventories), MobileReferenceInput::messages())->validate();
        $filters['q'] = trim($filters['q'] ?? '');

        return $filters;
    }

    private function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'has_more' => $page->hasMorePages(),
        ];
    }
}

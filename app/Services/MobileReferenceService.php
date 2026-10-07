<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\TreeStatus;
use App\Models\Inventory;
use App\Models\User;
use App\Repositories\MobileReferenceRepository;
use App\Validation\MobileReferenceInput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

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
        $page = $this->references->inventories($organization->id, $this->filters($input));

        return ['data' => $page->getCollection()->map(fn (Inventory $inventory) => [
            'id' => $inventory->id,
            'organization_id' => $inventory->organization_id,
            'code' => $inventory->code,
            'name' => $inventory->name,
            'description' => $inventory->description,
            'starts_at' => $inventory->starts_at?->format('Y-m-d'),
            'ends_at' => $inventory->ends_at?->format('Y-m-d'),
            'status' => $inventory->status->value,
            'active' => $inventory->active,
            'updated_at' => $inventory->updated_at?->toIso8601String(),
        ])->all(), 'meta' => $this->pagination($page) + [
            'organization_id' => $organization->id,
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
            'description' => $record->description,
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

    private function filters(array $input): array
    {
        $filters = Validator::make($input, MobileReferenceInput::rules(), MobileReferenceInput::messages())->validate();
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

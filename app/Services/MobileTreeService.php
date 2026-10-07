<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\MobileTreeRepository;
use App\Repositories\TreeRepository;
use App\Validation\MobileMutationInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** La ficha móvil comparte permisos, historiales y control de versión con la interfaz web. */
class MobileTreeService
{
    public function __construct(
        private ForestryAccessService $access,
        private InventoryRepository $inventories,
        private TreeRepository $trees,
        private MobileTreeRepository $reads,
        private TreeService $writes,
        private MobileMutationService $mutations,
    ) {
    }

    public function index(User $actor, string $inventoryId, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        $inventory = $this->inventories->findForTenant($organization->id, $inventoryId);
        Gate::forUser($actor)->authorize('view', $inventory);
        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        $page = $this->reads->paginate($organization->id, $inventory->id, (int) ($input['per_page'] ?? 50), (int) ($input['page'] ?? 1));

        return ['inventory_id' => $inventory->id,
            'data' => $page->getCollection()->map($this->projection(...))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function show(User $actor, string $id): array
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $id);
        Gate::forUser($actor)->authorize('view', $tree);

        return ['data' => $this->projection($tree)];
    }

    public function update(User $actor, string $id, array $input): array
    {
        $data = Validator::make($input, MobileMutationInput::treeUpdate(), MobileMutationInput::messages())->validate();

        return $this->mutations->run($actor, 'tree.update', $id, 'trees.update', $data, function (User $actor) use ($id, $data): array {
            $tree = $this->writes->update($actor, $id, ['lock_version' => $data['lock_version']] + $data['changes']);

            return ['tree_id' => $tree->id, 'data' => $this->projection($tree)];
        }, function (User $actor, string $organizationId) use ($id): void {
            Gate::forUser($actor)->authorize('update', $this->trees->findForTenant($organizationId, $id));
        });
    }

    /** Lista blanca: nunca serializa relaciones Eloquent, geometría binaria ni datos personales. */
    private function projection(Tree $tree): array
    {
        $fields = ['id', 'organization_id', 'code', 'external_number', 'location_description',
            'placement_id', 'species_id', 'growth_habit_id', 'establishment_origin_id', 'biogeographic_origin_id',
            'is_protected', 'is_heritage', 'coordinate_raw', 'coordinate_format', 'datum', 'accuracy_m',
            'gps_brand', 'gps_model', 'lock_version', 'created_by', 'updated_by'];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $tree->getAttribute($field);
        }
        $data['current_status'] = $tree->current_status->value;
        $data['latitude'] = $tree->latitude === null ? null : (float) $tree->latitude;
        $data['longitude'] = $tree->longitude === null ? null : (float) $tree->longitude;
        foreach (['captured_at', 'created_at', 'updated_at'] as $field) {
            $data[$field] = $tree->getAttribute($field)?->toISOString();
        }
        $data['inventories'] = $tree->inventories->sortBy('id')->values()->map(fn ($inventory) => [
            'inventory_id' => $inventory->id, 'inventory_tree_id' => $inventory->pivot->id,
            'code' => $inventory->code, 'name' => $inventory->name,
            'status' => $inventory->status->value, 'active' => $inventory->active,
        ])->all();

        return $data;
    }
}

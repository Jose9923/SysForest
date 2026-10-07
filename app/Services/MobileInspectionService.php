<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\MobileInspectionRepository;
use App\Repositories\MobileTreeSyncRepository;
use App\Repositories\TreeInspectionRepository;
use App\Repositories\TreeRepository;
use App\Validation\DendrometryInput;
use App\Validation\MobileMutationInput;
use App\Validation\TreeInspectionInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Nuevas visitas y correcciones mantienen la historia y los cálculos de TreeInspection. */
class MobileInspectionService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private InventoryRepository $inventories,
        private MobileTreeSyncRepository $participations,
        private TreeInspectionRepository $inspections,
        private MobileInspectionRepository $reads,
        private TreeInspectionService $writes,
        private MobileMutationService $mutations,
    ) {
    }

    public function index(User $actor, string $treeId, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $treeId);
        Gate::forUser($actor)->authorize('view', $tree);
        Gate::forUser($actor)->authorize('viewAny', TreeInspection::class);
        $page = $this->reads->paginate($organization->id, $tree->id, (int) ($input['per_page'] ?? 50), (int) ($input['page'] ?? 1));

        return ['tree_id' => $tree->id, 'data' => $page->getCollection()->map($this->projection(...))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function show(User $actor, string $id): array
    {
        $organization = $this->access->organization($actor);
        $inspection = $this->inspections->findForTenant($organization->id, $id);
        Gate::forUser($actor)->authorize('view', $inspection);

        return ['data' => $this->projection($inspection)];
    }

    public function create(User $actor, string $treeId, array $input): array
    {
        $data = Validator::make($input, MobileMutationInput::inspectionCreate(), MobileMutationInput::messages())->validate();

        return $this->mutations->run($actor, 'inspection.create', $treeId, 'inspections.create', $data, function (User $actor, string $organizationId) use ($treeId, $data): array {
            $tree = $this->trees->findForTenant($organizationId, $treeId);
            Gate::forUser($actor)->authorize('create', [TreeInspection::class, $tree]);
            $inventory = $this->inventories->findForTenant($organizationId, $data['inventory_id']);
            Gate::forUser($actor)->authorize('view', $inventory);
            try {
                $participation = $this->participations->participation($organizationId, $inventory->id, $tree->id);
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages(['inventory_id' => 'El árbol debe pertenecer a este inventario antes de registrar la visita.']);
            }
            $inspection = $this->writes->create($actor, $tree->id, $data['inspection'] + ['inventory_tree_id' => $participation->id]);

            return ['tree_id' => $tree->id, 'data' => $this->projection($inspection)];
        }, function (User $actor, string $organizationId) use ($treeId, $data): void {
            Gate::forUser($actor)->authorize('create', [TreeInspection::class, $this->trees->findForTenant($organizationId, $treeId)]);
            Gate::forUser($actor)->authorize('view', $this->inventories->findForTenant($organizationId, $data['inventory_id']));
        });
    }

    public function update(User $actor, string $id, array $input): array
    {
        $data = Validator::make($input, MobileMutationInput::inspectionUpdate(), MobileMutationInput::messages())->validate();

        return $this->mutations->run($actor, 'inspection.update', $id, 'inspections.update', $data, function (User $actor) use ($id, $data): array {
            $inspection = $this->writes->update($actor, $id, ['lock_version' => $data['lock_version']] + $data['changes']);

            return ['tree_id' => $inspection->tree_id, 'data' => $this->projection($inspection)];
        }, function (User $actor, string $organizationId) use ($id): void {
            Gate::forUser($actor)->authorize('update', $this->inspections->findForTenant($organizationId, $id));
        });
    }

    /** Devuelve entradas y derivados por separado de cualquier relación o revisión interna. */
    private function projection(TreeInspection $inspection): array
    {
        $fields = array_merge(
            ['id', 'organization_id', 'tree_id', 'inventory_tree_id', 'lock_version', 'created_by', 'updated_by'],
            DendrometryInput::FIELDS,
            TreeInspectionInput::TEXT_FIELDS,
            ['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3',
                'diameter_class_number', 'height_class_number', 'maturity_state_id',
                'classification_rule_version_id', 'calculation_rule_version']
        );
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $inspection->getAttribute($field);
        }
        $data['inventory_id'] = $inspection->inventoryTree?->inventory_id;
        foreach (array_merge(['inspection_date'], TreeInspectionInput::DATE_FIELDS) as $field) {
            $data[$field] = $inspection->getAttribute($field)?->format('Y-m-d');
        }
        foreach (['created_at', 'updated_at'] as $field) {
            $data[$field] = $inspection->getAttribute($field)?->toISOString();
        }
        $data['physical_condition_ids'] = $inspection->conditions->where('assessment_type', 'physical_mechanical')->sortBy('id')->values()->modelKeys();
        $data['phytosanitary_condition_ids'] = $inspection->conditions->where('assessment_type', 'phytosanitary')->sortBy('id')->values()->modelKeys();
        $data['fauna_category_ids'] = $inspection->faunaCategories->sortBy('id')->values()->modelKeys();

        return $data;
    }
}

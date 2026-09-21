<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\InspectionConditionRepository;
use App\Repositories\InspectionFaunaRepository;
use App\Repositories\InspectionReferenceRepository;
use App\Repositories\InspectionRevisionRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\TreeInspectionRepository;
use App\Repositories\TreeRepository;
use App\Validation\TreeInspectionInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Proyecciones preparadas para Blade y filtros reutilizables por futuros consumidores. */
class TreeInspectionReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private TreeInspectionRepository $inspections,
        private InspectionConditionRepository $conditions,
        private InspectionFaunaRepository $fauna,
        private InspectionReferenceRepository $references,
        private InspectionRevisionRepository $revisions,
        private InventoryRepository $inventories,
        private EvidenceReadService $evidences,
        private TreeFilterService $treeFilters,
    ) {
    }

    public function index(User $actor, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('viewAny', TreeInspection::class);
        $filters = $this->filters($input);
        $options = $this->references->filterOptions() + [
            'inventories' => $this->inventories->options($organization->id),
            'conditionCategories' => $this->conditions->optionsForFilter()->groupBy('assessment_type'),
        ];

        return ['organization' => $organization, 'inspections' => $this->inspections->paginate($organization->id, $filters),
            'filters' => $filters, 'options' => $options, 'canCreateInspection' => $this->access->allows($actor, 'inspections.create')];
    }

    public function form(User $actor, string $treeId, ?string $inspectionId = null): array
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $treeId);
        $inspection = $inspectionId ? $this->inspections->findForTenant($organization->id, $inspectionId) : null;
        abort_if($inspection !== null && $inspection->tree_id !== $tree->id, 404);
        Gate::forUser($actor)->authorize($inspection ? 'update' : 'create', $inspection ?? [TreeInspection::class, $tree]);

        return ['organization' => $organization, 'tree' => $tree, 'inspection' => $inspection,
            'options' => ['conditionCategories' => $this->conditions->options($inspection)->groupBy('assessment_type'),
                'faunaCategories' => $this->fauna->options($inspection),
                'inventoryTrees' => $this->inspections->inventoryOptionsForTree($organization->id, $tree->id)],
            'selectedPhysicalConditionIds' => $inspection?->conditions->where('assessment_type', 'physical_mechanical')->modelKeys() ?? [],
            'selectedPhytosanitaryConditionIds' => $inspection?->conditions->where('assessment_type', 'phytosanitary')->modelKeys() ?? [],
            'selectedFaunaCategoryIds' => $inspection?->faunaCategories->modelKeys() ?? [],
            'canUpdate' => $inspection ? Gate::forUser($actor)->allows('update', $inspection) : false,
            'canCreateInspection' => Gate::forUser($actor)->allows('create', [TreeInspection::class, $tree])];
    }

    public function edit(User $actor, string $id): array
    {
        $inspection = $this->authorizeExisting($actor, $id, 'update');

        return $this->form($actor, $inspection->tree_id, $id);
    }

    public function show(User $actor, string $id): array
    {
        $inspection = $this->authorizeExisting($actor, $id, 'view');

        return ['organization' => $this->access->organization($actor), 'tree' => $inspection->tree,
            'inspection' => $inspection, 'canUpdate' => Gate::forUser($actor)->allows('update', $inspection),
            'selectedConditionGroups' => $inspection->conditions->groupBy('assessment_type')->map(fn ($items) => $items->groupBy('condition_category_id')),
            'revisions' => $this->revisions->forInspection($inspection->organization_id, $inspection->id),
            'inspectionEvidenceGallery' => $this->evidences->gallery($actor, $inspection)];
    }

    public function treeSummary(User $actor, Tree $tree): array
    {
        $this->access->organization($actor);
        Gate::forUser($actor)->authorize('view', $tree);
        $allowed = Gate::forUser($actor)->allows('viewAny', TreeInspection::class);
        $timeline = $allowed ? $this->inspections->timelineForTree($tree->organization_id, $tree->id) : collect();

        return ['latestInspection' => $timeline->first(), 'inspectionTimeline' => $timeline,
            'canCreateInspection' => Gate::forUser($actor)->allows('create', [TreeInspection::class, $tree])];
    }

    public function latestTrees(User $actor, array $input = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('viewAny', TreeInspection::class);

        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        $filters = $this->treeFilters->validate($input);
        if ($filters->hasMaintenanceFilters()) {
            $this->access->authorize($actor, 'maintenances.view');
        }
        if ($filters->get('trashed') === 'only') {
            $this->access->authorize($actor, 'trees.restore');
        }

        return $this->inspections->latestTrees($organization->id, $filters->values());
    }

    public function authorizeTree(User $actor, string $treeId): bool
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $treeId);
        Gate::forUser($actor)->authorize('create', [TreeInspection::class, $tree]);

        return true;
    }

    public function authorizeExisting(User $actor, string $id, string $ability): TreeInspection
    {
        $organization = $this->access->organization($actor);
        $inspection = $this->inspections->findForTenant($organization->id, $id);
        Gate::forUser($actor)->authorize($ability, $inspection);

        return $inspection;
    }

    private function filters(array $input): array
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $input);
        // La bandeja de visitas conserva sus campos y su semántica propia de campaña.
        $accepted = Validator::make($input, TreeInspectionInput::filters(), TreeInspectionInput::messages())->validate();

        return $this->treeFilters->validate($accepted)->values();
    }
}

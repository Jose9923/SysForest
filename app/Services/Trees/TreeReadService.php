<?php

declare(strict_types=1);

namespace App\Services\Trees;

use App\Enums\Trees\TreeStatus;
use App\Models\Trees\Tree;
use App\Models\User;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Catalogs\LocationChangeTypeRepository;
use App\Repositories\Trees\TreeCatalogRepository;
use App\Repositories\Trees\TreeRepository;
use Illuminate\Support\Facades\Gate;
use App\Services\Conditions\ConditionFilterOptionsService;
use App\Services\Evidences\EvidenceReadService;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Maintenances\MaintenanceReadService;
use App\Services\Inspections\TreeInspectionReadService;

/** Prepara datos y permisos para Blade sin consultas implícitas en las vistas. */
class TreeReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private TreeCatalogRepository $catalogs,
        private InventoryRepository $inventories,
        private EvidenceReadService $evidences,
        private TreeInspectionReadService $inspections,
        private MaintenanceReadService $maintenances,
        private TreeFilterService $filters,
        private TreeCaptureDateService $captureDates,
        private ConditionFilterOptionsService $conditionOptions,
        private LocationChangeTypeRepository $changeTypes
    ) {
    }

    public function index(User $actor, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        $criteria = $this->filters->validate($input);
        $filters = $criteria->values();
        if ($criteria->hasInspectionFilters()) {
            $this->access->authorize($actor, 'inspections.view');
        }
        if ($criteria->hasMaintenanceFilters()) {
            $this->access->authorize($actor, 'maintenances.view');
        }
        if (!empty($filters['trashed'])) {
            $this->access->authorize($actor, 'trees.restore');
        }

        $view = $this->shared($actor);
        $view['options'] = array_replace($view['options'], $this->catalogs->filterOptions());
        $view['options']['inventories'] = $this->inventories->options($organization->id, false);

        $canInspect = $this->access->allows($actor, 'inspections.view');

        return array_merge($view, ['organization' => $organization, 'filters' => $filters,
            'canViewRisk' => $canInspect,
            'conditionFilterCategories' => $canInspect ? $this->conditionOptions->groups() : collect(),
            'trees' => $this->trees->paginate($organization->id, $filters, $canInspect)]);
    }

    public function form(User $actor, ?string $id = null): array
    {
        $organization = $this->access->organization($actor);
        $tree = $id ? $this->trees->findForTenant($organization->id, $id) : null;
        Gate::forUser($actor)->authorize($tree ? 'update' : 'create', $tree ?? Tree::class);

        $view = $this->shared($actor, $tree);
        if ($tree === null) {
            $initial = $this->inspections->registrationForm($actor);
            $view['options'] = array_replace($view['options'], $initial['inspectionOptions']);
            unset($initial['inspectionOptions']);
            $view = array_merge($view, $initial);
        }

        return array_merge($view, ['organization' => $organization, 'tree' => $tree, 'selectedInventoryIds' => []]);
    }

    public function show(User $actor, string $id): array
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $id, false, $this->access->allows($actor, 'trees.restore'));
        Gate::forUser($actor)->authorize('view', $tree);

        $summary = array_merge($this->inspections->treeSummary($actor, $tree), $this->maintenances->treeSummary($actor, $tree));

        return array_merge($this->shared($actor, $tree), $summary, ['organization' => $organization, 'tree' => $this->trees->detail($tree),
            'locationChangeOptions' => $this->changeTypes->options(),
            'locationLabels' => $this->changeTypes->labels(),
            'wholeTreePhoto' => $this->evidences->wholeTreePhoto($actor, $tree),
            'treeEvidenceGallery' => $this->evidences->gallery($actor, $tree),
            'latestInspectionEvidenceGallery' => $summary['latestInspection'] ? $this->evidences->gallery($actor, $summary['latestInspection']) : null]);
    }

    public function authorizeExisting(User $actor, string $id, string $ability, bool $withTrashed = false): bool
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $id, false, $withTrashed);
        Gate::forUser($actor)->authorize($ability, $tree);

        return true;
    }

    private function shared(User $actor, ?Tree $tree = null): array
    {
        $organization = $this->access->organization($actor);
        $options = $this->catalogs->options($tree);
        $options['inventories'] = $this->inventories->options($organization->id, true);
        $statuses = [];
        foreach (TreeStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        $linkedIds = $tree?->inventories->modelKeys() ?? [];
        $availableInventories = $options['inventories']->reject(fn ($inventory) => in_array($inventory->id, $linkedIds, true));

        return ['options' => $options, 'statuses' => $statuses, 'availableInventories' => $availableInventories,
            'captureDate' => ['input' => $this->captureDates->inputValue($tree?->captured_at),
                'display' => $this->captureDates->displayValue($tree?->captured_at)],
            'canCreate' => Gate::forUser($actor)->allows('create', Tree::class) && $this->access->allows($actor, 'inspections.create'),
            'canUpdate' => $tree ? Gate::forUser($actor)->allows('update', $tree) : $this->access->allows($actor, 'trees.update'),
            'canVoid' => $tree ? Gate::forUser($actor)->allows('void', $tree) : $this->access->allows($actor, 'trees.void'),
            'canRestore' => $tree ? Gate::forUser($actor)->allows('restore', $tree) : $this->access->allows($actor, 'trees.restore'),
            'canManageInventory' => $this->access->allows($actor, 'inventories.update')];
    }
}

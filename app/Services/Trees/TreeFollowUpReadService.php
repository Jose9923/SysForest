<?php

declare(strict_types=1);

namespace App\Services\Trees;

use App\Enums\Trees\TreeStatus;
use App\Models\Trees\Tree;
use App\Models\User;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Trees\TreeCatalogRepository;
use App\Repositories\Trees\TreeFollowUpRepository;
use App\Services\Conditions\ConditionFilterOptionsService;
use App\Services\Forestry\ForestryAccessService;
use Illuminate\Support\Facades\Gate;

/** Conserva el ámbito y las autorizaciones operativas de árboles e inspecciones. */
class TreeFollowUpReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeFilterService $filters,
        private TreeFollowUpRepository $trees,
        private TreeCatalogRepository $catalogs,
        private InventoryRepository $inventories,
        private ConditionFilterOptionsService $conditions,
    ) {
    }

    public function index(User $actor, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        $criteria = $this->filters->validate($input);
        if ($criteria->hasInspectionFilters()) {
            $this->access->authorize($actor, 'inspections.view');
        }
        if ($criteria->has('risk_priority')) {
            $this->access->authorize($actor, 'risk.view');
        }
        if ($criteria->hasMaintenanceFilters()) {
            $this->access->authorize($actor, 'maintenances.view');
        }
        if ($criteria->has('trashed')) {
            $this->access->authorize($actor, 'trees.restore');
        }

        $canInspect = $this->access->allows($actor, 'inspections.view');
        $options = $this->catalogs->filterOptions();
        $options['inventories'] = $this->inventories->options($organization->id, false);
        $statuses = [];
        foreach (TreeStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return [
            'organization' => $organization,
            'filters' => $criteria->values(),
            'options' => $options,
            'statuses' => $statuses,
            'canViewInspections' => $canInspect,
            'canFilterInspections' => $canInspect,
            'canViewRisk' => $canInspect && $this->access->allows($actor, 'risk.view'),
            'canRestore' => $this->access->allows($actor, 'trees.restore'),
            // Todas las filas pertenecen al contexto verificado. La política de
            // creación exige este permiso y un árbol vigente, no una campaña activa.
            'canCreateInspection' => $this->access->allows($actor, 'inspections.create'),
            'conditionFilterCategories' => $canInspect ? $this->conditions->groups() : collect(),
            'trees' => $this->trees->paginate($organization->id, $criteria, $canInspect),
        ];
    }
}

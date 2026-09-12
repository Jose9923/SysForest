<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreeStatus;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\TreeCatalogRepository;
use App\Repositories\TreeRepository;
use App\Validation\TreeInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Prepara datos y permisos para Blade sin consultas implícitas en las vistas. */
class TreeReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private TreeCatalogRepository $catalogs,
        private InventoryRepository $inventories,
        private TreeInspectionReadService $inspections
    ) {
    }

    public function index(User $actor, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        $filters = Validator::make($input, TreeInput::filters(), TreeInput::messages())->validate();
        if (!empty($filters['trashed'])) {
            $this->access->authorize($actor, 'trees.restore');
        }

        $view = $this->shared($actor);
        $view['options']['inventories'] = $this->inventories->options($organization->id, false);

        return array_merge($view, ['organization' => $organization, 'filters' => $filters,
            'trees' => $this->trees->paginate($organization->id, $filters)]);
    }

    public function form(User $actor, ?string $id = null): array
    {
        $organization = $this->access->organization($actor);
        $tree = $id ? $this->trees->findForTenant($organization->id, $id) : null;
        Gate::forUser($actor)->authorize($tree ? 'update' : 'create', $tree ?? Tree::class);

        return array_merge($this->shared($actor, $tree), ['organization' => $organization, 'tree' => $tree, 'selectedInventoryIds' => []]);
    }

    public function show(User $actor, string $id): array
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $id, false, $this->access->allows($actor, 'trees.restore'));
        Gate::forUser($actor)->authorize('view', $tree);

        return array_merge($this->shared($actor, $tree), $this->inspections->treeSummary($actor, $tree), ['organization' => $organization, 'tree' => $this->trees->detail($tree)]);
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
            'canCreate' => Gate::forUser($actor)->allows('create', Tree::class),
            'canUpdate' => $tree ? Gate::forUser($actor)->allows('update', $tree) : $this->access->allows($actor, 'trees.update'),
            'canVoid' => $tree ? Gate::forUser($actor)->allows('void', $tree) : $this->access->allows($actor, 'trees.void'),
            'canRestore' => $tree ? Gate::forUser($actor)->allows('restore', $tree) : $this->access->allows($actor, 'trees.restore'),
            'canManageInventory' => $this->access->allows($actor, 'inventories.update')];
    }
}

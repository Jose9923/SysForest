<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tree;
use App\Models\User;
use App\Repositories\TreeRepository;
use App\Services\ForestryAccessService;
use App\Tenancy\TenantContext;

class TreePolicy
{
    public function __construct(
        private ForestryAccessService $access,
        private TenantContext $context,
        private TreeRepository $trees,
    ) {
    }

    public function viewAny(User $actor): bool
    {
        return $this->access->allows($actor, 'trees.view');
    }

    public function view(User $actor, Tree $tree): bool
    {
        return $this->sameContext($actor, $tree) && $this->access->allows($actor, 'trees.view')
            && (! $tree->trashed() || $this->access->allows($actor, 'trees.restore'));
    }

    public function create(User $actor): bool
    {
        return $this->access->allows($actor, 'trees.create');
    }

    public function update(User $actor, Tree $tree): bool
    {
        return $this->sameContext($actor, $tree) && ! $tree->trashed()
            && $this->access->allows($actor, 'trees.update')
            && ($this->access->allows($actor, 'inventories.update') || $this->trees->hasActiveInventory($tree));
    }

    public function void(User $actor, Tree $tree): bool
    {
        return $this->sameContext($actor, $tree) && ! $tree->trashed() && $this->access->allows($actor, 'trees.void');
    }

    public function delete(User $actor, Tree $tree): bool
    {
        return $this->void($actor, $tree);
    }

    public function restore(User $actor, Tree $tree): bool
    {
        return $this->sameContext($actor, $tree) && $tree->trashed() && $this->access->allows($actor, 'trees.restore');
    }

    public function forceDelete(User $actor, Tree $tree): bool
    {
        return false;
    }

    private function sameContext(User $actor, Tree $tree): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $tree->organization_id;
    }
}

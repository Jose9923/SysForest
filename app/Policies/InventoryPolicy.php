<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;
use App\Services\ForestryAccessService;
use App\Tenancy\TenantContext;

class InventoryPolicy
{
    public function __construct(private ForestryAccessService $access, private TenantContext $context)
    {
    }

    public function viewAny(User $actor): bool
    {
        return $this->access->allows($actor, 'inventories.view');
    }

    public function view(User $actor, Inventory $inventory): bool
    {
        return $this->sameContext($actor, $inventory) && $this->access->allows($actor, 'inventories.view');
    }

    public function create(User $actor): bool
    {
        return $this->access->allows($actor, 'inventories.create');
    }

    public function update(User $actor, Inventory $inventory): bool
    {
        return $this->sameContext($actor, $inventory) && $this->access->allows($actor, 'inventories.update');
    }

    private function sameContext(User $actor, Inventory $inventory): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $inventory->organization_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Mobile;

use App\Models\Inventories\InventoryTree;
use App\Models\Mobile\MobileTreeReceipt;

class MobileTreeSyncRepository
{
    public function receipt(string $organizationId, string $clientId): ?MobileTreeReceipt
    {
        return MobileTreeReceipt::query()->where('organization_id', $organizationId)
            ->where('client_id', $clientId)->first();
    }

    public function participation(string $organizationId, string $inventoryId, string $treeId): InventoryTree
    {
        return InventoryTree::query()->where('organization_id', $organizationId)
            ->where('inventory_id', $inventoryId)->where('tree_id', $treeId)->firstOrFail();
    }

    public function confirm(array $attributes): void
    {
        $receipt = new MobileTreeReceipt();
        $receipt->forceFill($attributes)->save();
    }
}

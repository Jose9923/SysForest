<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Inventories\Inventory;

final class MobileInventoryProjection
{
    public static function make(Inventory $inventory): array
    {
        return ['id' => $inventory->id, 'organization_id' => $inventory->organization_id,
            'code' => $inventory->code, 'name' => $inventory->name, 'description' => $inventory->description,
            'starts_at' => $inventory->starts_at?->format('Y-m-d'),
            'ends_at' => $inventory->ends_at?->format('Y-m-d'),
            'status' => $inventory->status->value, 'active' => $inventory->active,
            'updated_at' => $inventory->updated_at?->toIso8601String()];
    }
}

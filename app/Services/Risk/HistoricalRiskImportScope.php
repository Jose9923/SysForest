<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\Inventories\Inventory;
use App\Models\Organizations\Organization;
use Closure;
use LogicException;
use App\Services\Imports\ReferenceInventoryImportService;

/** Excepción acotada a la recuperación local del paquete histórico autorizado; nunca procede de HTTP. */
class HistoricalRiskImportScope
{
    private ?string $organizationId = null;
    private ?string $inventoryId = null;

    public function recoverReference(Organization $organization, Inventory $inventory, Closure $operation): mixed
    {
        app(ReferenceInventoryImportService::class)->assertFiles();
        if ($organization->code !== 'RESTREPO-META' || !ReferenceInventoryImportService::matchesInventoryCode($inventory->code)
            || $inventory->organization_id !== $organization->id || $organization->is_public || $inventory->is_public) {
            throw new LogicException('La excepción histórica no corresponde a la recuperación privada autorizada.');
        }
        $previous = [$this->organizationId, $this->inventoryId];
        $this->organizationId = $organization->id;
        $this->inventoryId = $inventory->id;
        try {
            return $operation();
        } finally {
            [$this->organizationId, $this->inventoryId] = $previous;
        }
    }

    public function matches(string $organizationId, string $inventoryId): bool
    {
        return $this->organizationId === $organizationId && $this->inventoryId === $inventoryId;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Mobile;

use App\Models\Inspections\TreeInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use App\Repositories\Inspections\InspectionQueryRepository;

class MobileInspectionRepository
{
    public function __construct(private InspectionQueryRepository $queries)
    {
    }

    /** La participación selecciona árboles; conserva sus visitas de cualquier campaña autorizada del tenant. */
    public function paginateForInventory(string $organizationId, string $inventoryId, int $perPage, int $page): LengthAwarePaginator
    {
        $query = TreeInspection::query()->where('tree_inspections.organization_id', $organizationId)
            ->whereHas('tree', fn (Builder $tree) => $tree->where('trees.organization_id', $organizationId)
                ->whereNull('trees.deleted_at')
                ->whereHas('inventories', fn (Builder $inventory) => $inventory->where('inventories.organization_id', $organizationId)
                    ->where('inventories.id', $inventoryId)->where('inventory_trees.organization_id', $organizationId)))
            ->with(['conditions', 'faunaCategories', 'inventoryTree', 'carbonEstimate', 'riskEvaluation.methodology']);

        return $this->queries->ordered($query)->paginate($perPage, ['*'], 'page', $page);
    }

    public function paginate(string $organizationId, string $treeId, int $perPage, int $page): LengthAwarePaginator
    {
        $query = TreeInspection::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)
            ->with(['conditions', 'faunaCategories', 'inventoryTree', 'carbonEstimate', 'riskEvaluation.methodology']);

        return $this->queries->ordered($query)->paginate($perPage, ['*'], 'page', $page);
    }
}

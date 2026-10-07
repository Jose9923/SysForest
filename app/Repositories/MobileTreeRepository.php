<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Tree;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class MobileTreeRepository
{
    public function paginate(string $organizationId, string $inventoryId, int $perPage, int $page): LengthAwarePaginator
    {
        return Tree::query()->where('trees.organization_id', $organizationId)
            ->whereHas('inventories', fn (Builder $query) => $query->where('inventories.organization_id', $organizationId)
                ->where('inventories.id', $inventoryId))
            ->select('trees.*')->selectRaw('ST_Y(geometry) AS latitude, ST_X(geometry) AS longitude')
            ->with(['inventories' => fn ($query) => $query->where('inventories.organization_id', $organizationId)])
            ->orderBy('trees.code')->orderBy('trees.id')->paginate($perPage, ['*'], 'page', $page);
    }
}

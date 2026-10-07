<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TreeInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MobileInspectionRepository
{
    public function __construct(private InspectionQueryRepository $queries)
    {
    }

    public function paginate(string $organizationId, string $treeId, int $perPage, int $page): LengthAwarePaginator
    {
        $query = TreeInspection::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)
            ->with(['conditions', 'faunaCategories', 'inventoryTree']);

        return $this->queries->ordered($query)->paginate($perPage, ['*'], 'page', $page);
    }
}

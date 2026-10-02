<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ImportRow;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\TreeStatusHistory;
use Illuminate\Database\Eloquent\Collection;

/** Lecturas acotadas a los lotes del libro autorizado, sin incluir individuos de otras cargas. */
class RestrepoOrnatoImportRepository
{
    public function importedRows(string $organizationId, array $batchIds): Collection
    {
        return ImportRow::query()->where('organization_id', $organizationId)
            ->whereIn('import_batch_id', $batchIds)->orderBy('row_number')->get();
    }

    public function lockTree(string $organizationId, string $treeId): Tree
    {
        return Tree::withTrashed()->where('organization_id', $organizationId)
            ->whereKey($treeId)->lockForUpdate()->firstOrFail();
    }

    public function latestInspection(string $organizationId, string $treeId): ?TreeInspection
    {
        return TreeInspection::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)
            ->orderByDesc('inspection_date')->orderByDesc('created_at')->orderByDesc('id')
            ->lockForUpdate()->first();
    }

    public function correctionExists(string $organizationId, string $treeId, string $reason, string $status): bool
    {
        return TreeStatusHistory::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)
            ->where('from_status', 'active')->where('to_status', $status)->where('reason', $reason)->exists();
    }
}

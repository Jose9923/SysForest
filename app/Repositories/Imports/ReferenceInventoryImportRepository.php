<?php

declare(strict_types=1);

namespace App\Repositories\Imports;

use App\Models\Imports\ImportRow;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\Trees\TreeStatusHistory;
use Illuminate\Database\Eloquent\Collection;

/** Lecturas acotadas a los lotes del libro autorizado, sin incluir individuos de otras cargas. */
class ReferenceInventoryImportRepository
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

    public function correctionExists(string $organizationId, string $treeId, string $reason, string $status, ?string $legacySuffix = null): bool
    {
        $reasons = TreeStatusHistory::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)
            ->where('from_status', 'active')->where('to_status', $status)->pluck('reason');

        return $reasons->contains(function (string $candidate) use ($reason, $legacySuffix): bool {
            if ($candidate === $reason) {
                return true;
            }

            // Hash del prefijo exacto emitido por la fuente original; no reescribe antecedentes.
            return $legacySuffix !== null
                && hash_equals('0cea667f4ca1dfd30a05dc1ea1ffd83c9f3ea1da3f3fce15ca75fb449bbb0707', hash('sha256', substr($candidate, 0, 74)))
                && substr($candidate, 74) === $legacySuffix;
        });
    }
}

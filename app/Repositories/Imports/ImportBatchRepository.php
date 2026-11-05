<?php

declare(strict_types=1);

namespace App\Repositories\Imports;

use App\Models\Imports\ImportBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** Nunca resuelve un lote solo por su UUID: toda consulta recibe organización autorizada. */
class ImportBatchRepository
{
    public function paginate(string $organizationId): LengthAwarePaginator
    {
        return ImportBatch::query()->where('organization_id', $organizationId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
    }

    public function find(string $organizationId, string $id, bool $lock = false): ImportBatch
    {
        if (!Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(ImportBatch::class);
        }
        $query = ImportBatch::query()->where('organization_id', $organizationId)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function create(array $attributes): ImportBatch
    {
        $batch = new ImportBatch();
        $batch->forceFill($attributes)->save();

        return $batch;
    }

    public function sourceWasImported(string $organizationId, string $sha256): bool
    {
        return ImportBatch::query()->where('organization_id', $organizationId)
            ->where('file_sha256', $sha256)->where('status', 'completed')->exists();
    }

    /** Devuelve primero un resultado completado; el hash siempre se consulta dentro de la organización. */
    public function findBySourceHashForTenant(string $organizationId, string $sha256, bool $lock = false): ?ImportBatch
    {
        $query = ImportBatch::query()->where('organization_id', $organizationId)->where('file_sha256', $sha256)
            ->orderByRaw("CASE WHEN status = 'completed' THEN 0 WHEN status = 'ready' THEN 1 ELSE 2 END")
            ->orderBy('created_at')->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** La identidad original del JSON queda en el nombre inmutable, sin incorporar columnas a los lotes XLSX. */
    public function preparedSourcesForTenant(string $organizationId, string $sourceHash, bool $lock = false): \Illuminate\Database\Eloquent\Collection
    {
        if (preg_match('/\\A[a-f0-9]{64}\\z/D', $sourceHash) !== 1) {
            return new \Illuminate\Database\Eloquent\Collection();
        }
        $query = ImportBatch::query()->where('organization_id', $organizationId)
            ->where('file_name', 'like', 'reviewed-'.$sourceHash.'-%')->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function update(ImportBatch $batch, array $attributes): ImportBatch
    {
        $allowed = ['status', 'valid_rows', 'invalid_rows', 'imported_rows', 'lock_version',
            'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'notice'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw new \LogicException('La identidad del lote no puede modificarse.');
        }
        $batch->forceFill($attributes)->save();

        return $batch;
    }
}

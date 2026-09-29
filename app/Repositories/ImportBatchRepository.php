<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ImportBatch;
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

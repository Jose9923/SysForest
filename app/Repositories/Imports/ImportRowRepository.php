<?php

declare(strict_types=1);

namespace App\Repositories\Imports;

use App\Models\Imports\ImportBatch;
use App\Models\Imports\ImportRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ImportRowRepository
{
    public function create(ImportBatch $batch, array $attributes): ImportRow
    {
        $row = new ImportRow();
        $row->forceFill($attributes + ['organization_id' => $batch->organization_id, 'import_batch_id' => $batch->id])->save();

        return $row;
    }

    public function paginate(ImportBatch $batch): LengthAwarePaginator
    {
        return $this->query($batch)->orderBy('row_number')->paginate(20);
    }

    public function all(ImportBatch $batch): Collection
    {
        return $this->query($batch)->orderBy('row_number')->get();
    }

    public function update(ImportRow $row, array $attributes): void
    {
        $allowed = ['normalized_values', 'errors', 'status', 'tree_id', 'tree_code', 'inspection_id'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw new \LogicException('La fila recibida y su identidad son inmutables.');
        }
        $row->forceFill($attributes)->save();
    }

    private function query(ImportBatch $batch): Builder
    {
        return ImportRow::query()->where('organization_id', $batch->organization_id)->where('import_batch_id', $batch->id);
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Catalogs\CatalogDefinition;
use App\Models\CatalogEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** Consultas comunes únicamente sobre modelos tipados del registro de catálogos simples. */
class CatalogRepository
{
    public function paginate(CatalogDefinition $catalog, array $filters): LengthAwarePaginator
    {
        $query = $this->query($catalog);
        if (($filters['q'] ?? '') !== '') {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn (Builder $query) => $query->where('name', 'ilike', $search)->orWhere('code', 'ilike', $search));
        }
        if (isset($filters['active'])) {
            $query->where('active', (string) $filters['active'] === '1');
        }

        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
    }

    public function counts(CatalogDefinition $catalog): array
    {
        $result = $this->query($catalog)->selectRaw('count(*) AS total, count(*) FILTER (WHERE active) AS enabled')->first();

        return ['total' => (int) $result->total, 'active' => (int) $result->enabled];
    }

    /** Los selectores normales excluyen inactivos; find conserva lectura histórica por UUID. */
    public function activeOptions(CatalogDefinition $catalog): Collection
    {
        return $this->query($catalog)->where('active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    public function find(CatalogDefinition $catalog, string $id, bool $lock = false): CatalogEntry
    {
        if (!Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel($catalog->modelClass, [$id]);
        }
        $query = $this->query($catalog)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function codeExists(CatalogDefinition $catalog, string $code, ?string $except = null): bool
    {
        return $this->query($catalog)->where('code', $code)->when($except, fn (Builder $query) => $query->whereKeyNot($except))->exists();
    }

    public function create(CatalogDefinition $catalog, array $data): CatalogEntry
    {
        return $this->query($catalog)->create($data);
    }

    public function update(CatalogEntry $entry, array $data): CatalogEntry
    {
        $entry->fill($data)->save();

        return $entry;
    }

    /** La siembra agrega códigos ausentes y respeta ediciones administrativas posteriores. */
    public function seed(CatalogDefinition $catalog, string $code, array $defaults): CatalogEntry
    {
        return $this->query($catalog)->firstOrCreate(['code' => $code], $defaults);
    }

    private function query(CatalogDefinition $catalog): Builder
    {
        return $catalog->modelClass::query();
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Conditions;

use App\Enums\Conditions\ConditionCatalogKind;
use App\Models\Conditions\Condition;
use App\Models\Conditions\ConditionCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ConditionCatalogRepository
{
    public function paginate(ConditionCatalogKind $catalog, array $filters): LengthAwarePaginator
    {
        $query = $this->query($catalog);
        if ($filters['active'] !== 'all') {
            $query->where('active', $filters['active'] === '1');
            if ($filters['active'] === '1' && $catalog === ConditionCatalogKind::Items) {
                $query->whereHas('category', fn (Builder $category) => $category->where('active', true));
            }
        }
        if ($filters['assessment_type'] !== '') {
            $query->where('assessment_type', $filters['assessment_type']);
        }
        if ($filters['q'] !== '') {
            $query->where(fn (Builder $search) => $search->where('code', 'ilike', '%'.$filters['q'].'%')->orWhere('name', 'ilike', '%'.$filters['q'].'%'));
        }

        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
    }

    public function find(ConditionCatalogKind $catalog, string $id, bool $lock = false): Model
    {
        return $this->query($catalog)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function create(ConditionCatalogKind $catalog, array $attributes): Model
    {
        return $this->query($catalog)->create($attributes)->refresh();
    }

    public function update(Model $record, array $attributes): Model
    {
        $record->fill($attributes)->save();

        return $record->refresh();
    }

    public function seed(ConditionCatalogKind $catalog, string $code, array $attributes): Model
    {
        return $this->query($catalog)->firstOrCreate(['code' => $code], $attributes);
    }

    public function categoryOptions(?string $includeId = null): Collection
    {
        return ConditionCategory::query()->where(function (Builder $query) use ($includeId): void {
            $query->where('active', true);
            if ($includeId !== null) {
                $query->orWhere('id', $includeId);
            }
        })->orderBy('assessment_type')->orderBy('sort_order')->orderBy('name')->get();
    }

    private function query(ConditionCatalogKind $catalog): Builder
    {
        return $catalog === ConditionCatalogKind::Categories ? ConditionCategory::query() : Condition::query()->with('category');
    }
}

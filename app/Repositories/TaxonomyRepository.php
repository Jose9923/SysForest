<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\TaxonomyCatalog;
use App\Models\Family;
use App\Models\Species;
use App\Models\SpeciesCommonName;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TaxonomyRepository
{
    public function paginate(TaxonomyCatalog $catalog, array $filters): LengthAwarePaginator
    {
        $query = $this->query($catalog);
        if ($filters['active'] !== 'all') {
            $query->where('active', $filters['active'] === '1');
            if ($filters['active'] === '1') {
                $this->activeParents($query, $catalog);
            }
        }
        if ($filters['q'] !== '') {
            $query->where(fn (Builder $search) => $search->where('code', 'ilike', '%'.$filters['q'].'%')
                ->orWhere($catalog->nameColumn(), 'ilike', '%'.$filters['q'].'%'));
        }

        return $query->orderBy('sort_order')->orderBy($catalog->nameColumn())->orderBy('id')->paginate(20)->withQueryString();
    }

    public function find(TaxonomyCatalog $catalog, string $id, bool $lock = false): Model
    {
        return $this->query($catalog)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function create(TaxonomyCatalog $catalog, array $attributes): Model
    {
        return $this->query($catalog)->create($attributes)->refresh();
    }

    public function update(Model $record, array $attributes): Model
    {
        $record->fill($attributes)->save();

        return $record->refresh();
    }

    /** Solo los seeders usan esta entrada; nunca sobrescribe ajustes administrativos. */
    public function seed(TaxonomyCatalog $catalog, string $code, array $attributes): Model
    {
        return $this->query($catalog)->firstOrCreate(['code' => $code], $attributes);
    }

    public function options(TaxonomyCatalog $catalog, ?string $includeId = null): Collection
    {
        return $this->query($catalog)->where(function (Builder $query) use ($catalog, $includeId): void {
            $query->where(function (Builder $active) use ($catalog): void {
                $active->where('active', true);
                $this->activeParents($active, $catalog);
            });
            if ($includeId !== null) {
                $query->orWhere('id', $includeId);
            }
        })->orderBy('sort_order')->orderBy($catalog->nameColumn())->get();
    }

    public function conflictingPrimary(string $speciesId, ?string $exceptId): bool
    {
        return SpeciesCommonName::query()->where('species_id', $speciesId)->where('is_primary', true)
            ->when($exceptId, fn (Builder $query) => $query->where('id', '<>', $exceptId))->exists();
    }

    private function activeParents(Builder $query, TaxonomyCatalog $catalog): void
    {
        if ($catalog === TaxonomyCatalog::Species) {
            $query->whereHas('family', fn (Builder $family) => $family->where('active', true));
        } elseif ($catalog === TaxonomyCatalog::CommonNames) {
            $query->whereHas('species', fn (Builder $species) => $species->where('active', true)
                ->whereHas('family', fn (Builder $family) => $family->where('active', true)));
        }
    }

    private function query(TaxonomyCatalog $catalog): Builder
    {
        return match ($catalog) {
            TaxonomyCatalog::Families => Family::query(),
            TaxonomyCatalog::Species => Species::query()->with(['family', 'commonNames']),
            TaxonomyCatalog::CommonNames => SpeciesCommonName::query()->with('species.family'),
        };
    }
}

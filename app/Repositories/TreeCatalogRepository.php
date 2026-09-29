<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\BiogeographicOrigin;
use App\Models\DeletionReason;
use App\Models\EstablishmentOrigin;
use App\Models\GrowthHabit;
use App\Models\Placement;
use App\Models\Species;
use App\Models\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Registro cerrado de referencias permanentes. Los nombres de modelos nunca proceden del cliente. */
class TreeCatalogRepository
{
    private const MODELS = ['placement_id' => Placement::class, 'species_id' => Species::class,
        'growth_habit_id' => GrowthHabit::class, 'establishment_origin_id' => EstablishmentOrigin::class,
        'biogeographic_origin_id' => BiogeographicOrigin::class, 'deletion_reason_id' => DeletionReason::class];

    private function available(string $field): Builder
    {
        $query = self::MODELS[$field]::query()->where('active', true);
        if ($field === 'species_id') {
            $query->whereHas('family', fn (Builder $q) => $q->where('active', true));
        }

        return $query;
    }

    public function validSelection(string $field, string $id, ?string $currentId = null): bool
    {
        // EXISTS no bloquea las filas consultadas: materializar la referencia evita una
        // desactivación entre la validación y el commit de la captura o anulación.
        $lock = DB::transactionLevel() > 0;
        $query = self::MODELS[$field]::query()->whereKey($id);
        $record = ($lock ? $query->sharedLock() : $query)->first();
        if ($record === null) {
            return false;
        }
        if ($currentId !== null && strtolower($id) === strtolower($currentId)) {
            return true;
        }
        if (!$record->active) {
            return false;
        }
        if ($record instanceof Species) {
            $parent = $record->family();
            $family = ($lock ? $parent->sharedLock() : $parent)->first();

            return $family !== null && $family->active;
        }

        return true;
    }

    public function options(?Tree $tree = null): array
    {
        $result = [];
        foreach (self::MODELS as $field => $model) {
            $current = $tree?->getAttribute($field);
            $query = $model::query()->where(function (Builder $q) use ($field, $current): void {
                $q->whereIn('id', $this->available($field)->select('id'));
                if ($current) {
                    $q->orWhere('id', $current);
                }
            });
            if ($field === 'species_id') {
                $query->with(['family', 'commonNames'])->orderBy('scientific_name');
            } else {
                $query->orderBy('sort_order')->orderBy('name');
            }
            $key = ['placement_id' => 'placements', 'species_id' => 'species', 'growth_habit_id' => 'growth_habits',
                'establishment_origin_id' => 'establishment_origins', 'biogeographic_origin_id' => 'biogeographic_origins',
                'deletion_reason_id' => 'deletion_reasons'][$field];
            $result[$key] = $query->get();
        }

        return $result;
    }
}

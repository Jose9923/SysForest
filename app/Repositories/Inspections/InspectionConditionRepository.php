<?php

declare(strict_types=1);

namespace App\Repositories\Inspections;

use App\Models\Conditions\Condition;
use App\Models\Conditions\ConditionCategory;
use App\Models\Inspections\TreeInspection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class InspectionConditionRepository
{
    public function options(?TreeInspection $inspection = null): Collection
    {
        $historical = $this->currentIds($inspection);
        $available = fn (Builder $conditions) => $conditions->where(function (Builder $conditions) use ($historical): void {
            $conditions->where(fn (Builder $active) => $active->where('active', true)->whereHas('category', fn (Builder $category) => $category->where('active', true)));
            if ($historical !== []) {
                $conditions->orWhereIn('id', $historical);
            }
        });

        return ConditionCategory::query()->whereHas('conditions', $available)->with(['conditions' => fn (HasMany $conditions) => $available($conditions->getQuery())->orderBy('sort_order')->orderBy('name')->orderBy('id')])
            ->orderBy('assessment_type')->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
    }

    /** Los filtros históricos incluyen catálogos desactivados; la captura conserva sus restricciones. */
    public function optionsForFilter(): Collection
    {
        return ConditionCategory::query()->whereHas('conditions')
            ->with(['conditions' => fn (HasMany $conditions) => $conditions->orderBy('sort_order')->orderBy('name')->orderBy('id')])
            ->orderBy('assessment_type')->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
    }

    public function validate(array $ids, string $assessmentType, ?TreeInspection $inspection = null): bool
    {
        if (!$this->validIds($ids) || !in_array($assessmentType, ['physical_mechanical', 'phytosanitary'], true)) {
            return false;
        }
        // Mantiene estable la disponibilidad hasta guardar; el orden evita bloqueos cruzados entre selecciones.
        if ($ids !== [] && DB::transactionLevel() > 0) {
            $categoryIds = Condition::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()
                ->get(['id', 'condition_category_id'])->pluck('condition_category_id')->unique()->all();
            ConditionCategory::query()->whereIn('id', $categoryIds)->orderBy('id')->sharedLock()->get(['id']);
        }
        $historical = $this->currentIds($inspection);

        return Condition::query()->whereIn('id', $ids)->where('assessment_type', $assessmentType)
            ->where(function (Builder $query) use ($historical): void {
                $query->where(fn (Builder $active) => $active->where('active', true)->whereHas('category', fn (Builder $category) => $category->where('active', true)));
                if ($historical !== []) {
                    $query->orWhereIn('id', $historical);
                }
            })->count() === count($ids);
    }

    /** Buscar historia permite referencias desactivadas sin habilitarlas para una nueva captura. */
    public function validateForFilter(array $ids, string $assessmentType): bool
    {
        return $this->validIds($ids)
            && in_array($assessmentType, ['physical_mechanical', 'phytosanitary'], true)
            && Condition::query()->whereIn('id', $ids)->where('assessment_type', $assessmentType)->count() === count($ids);
    }

    /** La selección de órganos en reportes conserva referencias históricas inactivas. */
    public function validateCategoriesForFilter(array $ids, string $assessmentType): bool
    {
        return $this->validIds($ids)
            && in_array($assessmentType, ['physical_mechanical', 'phytosanitary'], true)
            && ConditionCategory::query()->whereIn('id', $ids)->where('assessment_type', $assessmentType)->count() === count($ids);
    }

    public function conditionsBelongToCategories(array $conditionIds, array $categoryIds, string $assessmentType): bool
    {
        return Condition::query()->whereIn('id', $conditionIds)->where('assessment_type', $assessmentType)
            ->whereIn('condition_category_id', $categoryIds)->count() === count($conditionIds);
    }

    public function sync(string $organizationId, string $inspectionId, array $ids): void
    {
        $this->requireTransaction();
        TreeInspection::query()->where('organization_id', $organizationId)->whereKey($inspectionId)->firstOrFail();
        $ids = array_values(array_unique($ids));
        $query = DB::table('inspection_conditions')->where('organization_id', $organizationId)->where('inspection_id', $inspectionId);
        $current = (clone $query)->pluck('condition_id')->all();
        (clone $query)->whereNotIn('condition_id', $ids)->delete();
        $createdAt = now()->format('Y-m-d H:i:s.uP');
        foreach (array_diff($ids, $current) as $id) {
            DB::table('inspection_conditions')->insert(['organization_id' => $organizationId,
                'inspection_id' => $inspectionId, 'condition_id' => $id, 'created_at' => $createdAt]);
        }
    }

    private function currentIds(?TreeInspection $inspection): array
    {
        return $inspection === null ? [] : DB::table('inspection_conditions')
            ->where('organization_id', $inspection->organization_id)->where('inspection_id', $inspection->id)->pluck('condition_id')->all();
    }

    private function validIds(array $ids): bool
    {
        return array_all($ids, fn ($id): bool => is_string($id) && Str::isUuid($id))
            && count(array_unique($ids)) === count($ids);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Modificar condiciones requiere la transacción de la inspección.');
        }
    }
}

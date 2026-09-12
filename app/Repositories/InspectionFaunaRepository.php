<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FaunaCategory;
use App\Models\TreeInspection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class InspectionFaunaRepository
{
    public function options(?TreeInspection $inspection = null): Collection
    {
        $historical = $this->currentIds($inspection);

        return FaunaCategory::query()->where(function (Builder $query) use ($historical): void {
            $query->where('active', true);
            if ($historical !== []) {
                $query->orWhereIn('id', $historical);
            }
        })->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
    }

    public function validate(array $ids, ?TreeInspection $inspection = null): bool
    {
        if (!array_all($ids, fn ($id): bool => is_string($id) && Str::isUuid($id))
            || count(array_unique($ids)) !== count($ids)) {
            return false;
        }
        // La categoría no puede desactivarse entre esta validación y la inserción de la selección.
        if ($ids !== [] && DB::transactionLevel() > 0) {
            FaunaCategory::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
        }
        $historical = $this->currentIds($inspection);

        return FaunaCategory::query()->whereIn('id', $ids)->where(function (Builder $query) use ($historical): void {
            $query->where('active', true);
            if ($historical !== []) {
                $query->orWhereIn('id', $historical);
            }
        })->count() === count($ids);
    }

    public function sync(string $organizationId, string $inspectionId, array $ids): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Modificar fauna requiere la transacción de la inspección.');
        }
        TreeInspection::query()->where('organization_id', $organizationId)->whereKey($inspectionId)->firstOrFail();
        $ids = array_values(array_unique($ids));
        $query = DB::table('inspection_fauna')->where('organization_id', $organizationId)->where('inspection_id', $inspectionId);
        $current = (clone $query)->pluck('fauna_category_id')->all();
        (clone $query)->whereNotIn('fauna_category_id', $ids)->delete();
        $createdAt = now()->format('Y-m-d H:i:s.uP');
        foreach (array_diff($ids, $current) as $id) {
            DB::table('inspection_fauna')->insert(['organization_id' => $organizationId,
                'inspection_id' => $inspectionId, 'fauna_category_id' => $id, 'created_at' => $createdAt]);
        }
    }

    private function currentIds(?TreeInspection $inspection): array
    {
        return $inspection === null ? [] : DB::table('inspection_fauna')->where('organization_id', $inspection->organization_id)
            ->where('inspection_id', $inspection->id)->pluck('fauna_category_id')->all();
    }
}

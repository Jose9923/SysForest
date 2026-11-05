<?php

declare(strict_types=1);

namespace App\Services\Conditions;

use App\Repositories\Inspections\InspectionConditionRepository;
use Illuminate\Support\Collection;

/** Agrupa el catálogo histórico para filtros; el portal utiliza exclusivamente códigos públicos. */
class ConditionFilterOptionsService
{
    public function __construct(private InspectionConditionRepository $conditions)
    {
    }

    public function groups(bool $public = false): Collection
    {
        return $this->conditions->optionsForFilter()->groupBy('assessment_type')->map(
            fn ($categories) => $categories->map(fn ($category) => (object) [
                'id' => $public ? $category->code : $category->id,
                'name' => $category->name,
                'active' => (bool) $category->active,
                'conditions' => $category->conditions->map(fn ($condition) => (object) [
                    'id' => $public ? $condition->code : $condition->id,
                    'name' => $condition->name,
                    'active' => (bool) $condition->active,
                ]),
            ])
        );
    }
}

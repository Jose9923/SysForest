<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

use App\DTOs\Maps\MapTreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;

/** Selección validada: describe el universo del reporte, pero no conserva concesiones de acceso. */
final readonly class ReportSelectionDTO
{
    public const CONDITION_CATEGORY_FIELDS = [
        'physical_condition_category_ids' => 'physical_mechanical',
        'phytosanitary_condition_category_ids' => 'phytosanitary',
    ];
    public function __construct(
        public TreeReadScope $scope,
        public MapTreeFilterDTO $filters,
        public array $values,
    ) {
    }

    /** Las categorías limitan series, nunca el universo de árboles ni la API pública. */
    public function conditionSeries(string $assessmentType): array
    {
        if ($this->isPublic()) {
            return [];
        }
        $categoryField = array_search($assessmentType, self::CONDITION_CATEGORY_FIELDS, true);
        if ($categoryField === false) {
            return [];
        }
        $conditionField = $assessmentType === 'physical_mechanical' ? 'physical_condition_ids' : 'phytosanitary_condition_ids';
        $conditions = $this->filters->trees->get($conditionField, []);
        $categories = $this->values[$categoryField] ?? [];

        return $conditions === [] && $categories === [] ? [] : ['condition_ids' => $conditions, 'category_ids' => $categories];
    }

    public function hasConditionSeries(): bool
    {
        return $this->conditionSeries('physical_mechanical') !== [] || $this->conditionSeries('phytosanitary') !== [];
    }

    public function isPublic(): bool
    {
        return $this->scope->kind === 'published';
    }
}

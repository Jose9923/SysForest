<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

use App\DTOs\Maps\MapTreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;

/** Selección validada: describe el universo del reporte, pero no conserva concesiones de acceso. */
final readonly class ReportSelectionDTO
{
    public function __construct(
        public TreeReadScope $scope,
        public MapTreeFilterDTO $filters,
        public array $values,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->scope->kind === 'published';
    }
}

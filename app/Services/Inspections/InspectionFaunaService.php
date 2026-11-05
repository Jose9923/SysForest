<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\Inspections\TreeInspection;
use App\Repositories\Inspections\InspectionFaunaRepository;
use Illuminate\Validation\ValidationException;

class InspectionFaunaService
{
    public function __construct(private InspectionFaunaRepository $fauna)
    {
    }

    public function apply(TreeInspection $inspection, array $ids, ?TreeInspection $previous = null): void
    {
        if (!$this->fauna->validate($ids, $previous)) {
            throw ValidationException::withMessages(['fauna_category_ids' => 'Seleccione categorías de fauna disponibles.']);
        }
        $this->fauna->sync($inspection->organization_id, $inspection->id, $ids);
    }
}

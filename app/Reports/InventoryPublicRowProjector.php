<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Evidences\Evidence;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use Illuminate\Support\Collection;

/** Lista pública cerrada: la proyección no tiene acceso a textos privados ni a archivos originales. */
class InventoryPublicRowProjector
{
    public function __construct(private InventoryRowValues $values, private InventoryExportSchema $schema)
    {
    }

    public function project(Tree $tree, ?TreeInspection $inspection, Collection $evidences): array
    {
        $values = $this->values->shared($tree, $inspection);
        $values['organization_code'] = $tree->getRelation('organization')->public_code;
        $values['municipality_code'] = $values['country_code'].'~'.$values['department_code'].'~'.$values['municipality_code'];
        $values['department_code'] = $values['country_code'].'~'.$values['department_code'];
        $values = array_replace($values, $this->values->photos(
            $evidences,
            fn (Evidence $evidence) => route('public.evidences.file', ['evidence' => $evidence->public_id])
        ));

        return $this->schema->project($values, true);
    }
}

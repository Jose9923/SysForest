<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Inspections\TreeInspection;
use App\Models\Trees\Tree;
use App\Support\Carbon\CarbonPresentation;
use App\Support\Catalogs\ClassificationVersionPresentation;
use App\Support\Imports\ExcelReferencePresentation;
use Illuminate\Database\Eloquent\Model;

/** Contexto descargable de lectura: no recalcula resultados ni modifica recibos históricos. */
final class MobileAnalyticsProjection
{
    public static function treeReference(Tree $tree): array
    {
        $species = $tree->getRelation('species');
        $family = $species?->getRelation('family');

        return [
            'species' => $species === null ? null : [
                'id' => MobileIdentifiers::id($species), 'scientific_name' => $species->scientific_name,
                'family_id' => MobileIdentifiers::external('families', $species->family_id), 'active' => (bool) $species->active,
                'common_names' => $species->getRelation('commonNames')->sortBy(fn (Model $record) => MobileIdentifiers::id($record))->values()->map(fn (Model $name): array => [
                    'id' => MobileIdentifiers::id($name), 'code' => $name->code, 'name' => $name->name,
                    'is_primary' => (bool) $name->is_primary, 'active' => (bool) $name->active,
                ])->all(),
            ],
            'family' => $family === null ? null : [
                'id' => MobileIdentifiers::id($family), 'scientific_name' => $family->scientific_name, 'active' => (bool) $family->active,
            ],
            'placement' => self::reference($tree->getRelation('placement')),
            'growth_habit' => self::reference($tree->getRelation('growthHabit')),
            'establishment_origin' => self::reference($tree->getRelation('establishmentOrigin')),
            'biogeographic_origin' => self::reference($tree->getRelation('biogeographicOrigin')),
        ];
    }

    public static function inspection(TreeInspection $inspection): array
    {
        $tree = $inspection->getRelation('tree');
        $rule = $inspection->getRelation('classificationRuleVersion');
        $estimate = $inspection->getRelation('carbonEstimate');
        // La relación ya exige compatibilidad. Repetirla contra el contexto cargado evita
        // atribuir un resultado a otro individuo/versión incluso en consumidores aislados.
        $compatible = $estimate !== null && $tree !== null
            && $estimate->inspection_version === $inspection->lock_version
            && (string) $estimate->species_id === (string) $tree->species_id
            && MobileIdentifiers::external('growth_habits', $estimate->inputs['growth_habit_id'] ?? null) === MobileIdentifiers::external('growth_habits', $tree->growth_habit_id);
        $live = $tree !== null && in_array($tree->current_status->value, ['active', 'under_observation', 'transplanted'], true);
        $status = !$live ? 'excluded_status' : ($compatible ? $estimate->status : 'unprocessed');
        $included = $compatible && $live && $status === 'estimated';

        return [
            'schema_version' => 1, 'inspection_id' => MobileIdentifiers::id($inspection), 'inspection_version' => $inspection->lock_version,
            'tree_id' => MobileIdentifiers::external('trees', $inspection->tree_id), 'tree_lock_version' => $tree?->lock_version,
            'tree_species_id' => MobileIdentifiers::external('species', $tree?->species_id), 'tree_growth_habit_id' => MobileIdentifiers::external('growth_habits', $tree?->growth_habit_id),
            'tree_current_status' => $tree?->current_status?->value,
            'classification' => $rule === null ? null : [
                'id' => MobileIdentifiers::id($rule), 'code' => $rule->code, 'label' => ClassificationVersionPresentation::label($rule->code),
            ],
            'maturity_state' => self::reference($inspection->getRelation('maturityState')),
            'conditions' => $inspection->getRelation('conditions')->sortBy(fn (Model $record) => MobileIdentifiers::id($record))->values()->map(fn (Model $condition): array => [
                ...self::reference($condition), 'assessment_type' => $condition->assessment_type,
                'condition_category_id' => MobileIdentifiers::external('condition_categories', $condition->condition_category_id),
                'category' => self::reference($condition->getRelation('category')),
            ])->all(),
            'carbon' => [
                'status' => $status, 'label' => CarbonPresentation::label($status), 'compatible' => $compatible,
                'included_in_live_stock' => $included,
                'inputs_species_id' => $compatible ? MobileIdentifiers::external('species', $estimate->species_id) : null,
                'inputs_growth_habit_id' => $compatible ? MobileIdentifiers::external('growth_habits', $estimate->inputs['growth_habit_id'] ?? null) : null,
                'reference_status' => $compatible ? ($estimate->method['profile']['validation_status'] ?? null) : null,
                'biomass_kg' => $included ? $estimate->biomass_kg : null,
                'carbon_kg' => $included ? $estimate->carbon_kg : null,
                'co2_kg' => $included ? $estimate->co2_kg : null,
            ],
        ];
    }

    private static function reference(?Model $record): ?array
    {
        return $record === null ? null : [
            'id' => MobileIdentifiers::id($record), 'code' => $record->code,
            'name' => ExcelReferencePresentation::text($record->name), 'active' => (bool) $record->active,
        ];
    }
}

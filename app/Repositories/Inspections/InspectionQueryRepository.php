<?php

declare(strict_types=1);

namespace App\Repositories\Inspections;

use App\DTOs\Trees\TreeReadScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Repositories\PublicPortal\PublicVisibilityRepository;

/** Una definición de orden y predicados para visitas históricas y última inspección del árbol. */
class InspectionQueryRepository
{
    public function __construct(private PublicVisibilityRepository $visibility)
    {
    }

    public function ordered(Builder|QueryBuilder $query, string $alias = 'tree_inspections'): Builder|QueryBuilder
    {
        $this->requireAlias($alias);

        return $query->orderByDesc($alias.'.inspection_date')->orderByDesc($alias.'.created_at')->orderByDesc($alias.'.id');
    }

    /** Publicación delimita el universo antes del LIMIT; los filtros forestales siempre van después. */
    public function latestForTreeSubquery(?TreeReadScope $scope = null): QueryBuilder
    {
        $query = DB::table('tree_inspections')
            ->whereColumn('tree_inspections.organization_id', 'trees.organization_id')
            ->whereColumn('tree_inspections.tree_id', 'trees.id')
            ->select('tree_inspections.*');
        if ($scope?->kind === 'published') {
            $this->visibility->inspection($query);
        }

        if ($scope?->kind !== 'published') {
            $carbon = DB::table('carbon_estimates as ce')
                ->whereColumn('ce.organization_id', 'tree_inspections.organization_id')
                ->whereColumn('ce.inspection_id', 'tree_inspections.id')
                ->whereColumn('ce.inspection_version', 'tree_inspections.lock_version')
                ->whereRaw('ce.species_id IS NOT DISTINCT FROM trees.species_id')
                ->whereRaw("ce.inputs->>'growth_habit_id' IS NOT DISTINCT FROM trees.growth_habit_id::text")
                ->orderByDesc('ce.id')->limit(1);
            $query->leftJoinLateral($carbon, 'visit_carbon')->addSelect(
                DB::raw("visit_carbon.method->'profile'->>'validation_status' as carbon_validation_status"),
                'visit_carbon.status as carbon_status',
                'visit_carbon.biomass_kg as carbon_biomass_kg',
                'visit_carbon.carbon_kg as carbon_carbon_kg',
                'visit_carbon.co2_kg as carbon_co2_kg'
            );
            $query->leftJoin('risk_evaluations as visit_risk', fn ($join) => $join
                ->on('visit_risk.inspection_id', '=', 'tree_inspections.id')
                ->on('visit_risk.organization_id', '=', 'tree_inspections.organization_id')
                ->on('visit_risk.inspection_version', '=', 'tree_inspections.lock_version'))
                ->addSelect('visit_risk.priority as risk_priority', 'visit_risk.result as risk_result', 'visit_risk.id as risk_evaluation_id');
        }

        return $this->ordered($query)->limit(1);
    }

    public function applyFilters(Builder $query, array $filters, string $alias = 'tree_inspections'): void
    {
        $this->requireAlias($alias);
        if (!empty($filters['risk_priority'])) {
            $value = (string) $filters['risk_priority'];
            if ($alias === 'latest_inspection') {
                $this->riskFilter($query, $value, $alias.'.risk_priority', $alias.'.risk_result', $alias.'.risk_evaluation_id');
            } else {
                if ($value === 'unevaluated') {
                    $query->whereNotExists(fn ($risk) => $risk->selectRaw('1')->from('risk_evaluations as filtered_risk')
                        ->whereColumn('filtered_risk.inspection_id', $alias.'.id')->whereColumn('filtered_risk.inspection_version', $alias.'.lock_version'));
                } else {
                    $query->whereExists(function ($risk) use ($alias, $value): void {
                        $risk->selectRaw('1')->from('risk_evaluations as filtered_risk')
                            ->whereColumn('filtered_risk.organization_id', $alias.'.organization_id')
                            ->whereColumn('filtered_risk.inspection_id', $alias.'.id')->whereColumn('filtered_risk.inspection_version', $alias.'.lock_version');
                        $this->riskFilter($risk, $value, 'filtered_risk.priority', 'filtered_risk.result', 'filtered_risk.id');
                    });
                }
            }
        }
        if (!empty($filters['tree_id'])) {
            $query->where($alias.'.tree_id', $filters['tree_id']);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                $query->where($alias.'.inspection_date', $operator, $filters[$key]);
            }
        }
        foreach (['maturity_state_id', 'diameter_class_number', 'height_class_number'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($alias.'.'.$field, $filters[$field]);
            }
        }
        foreach (['cap_cm', 'dap_m', 'total_height_m'] as $field) {
            foreach (['min' => '>=', 'max' => '<='] as $suffix => $operator) {
                if (isset($filters[$field.'_'.$suffix]) && $filters[$field.'_'.$suffix] !== '') {
                    $query->where($alias.'.'.$field, $operator, $filters[$field.'_'.$suffix]);
                }
            }
        }
        if (!empty($filters['inventory_id'])) {
            $query->whereExists(fn (QueryBuilder $inventory) => $inventory->selectRaw('1')->from('inventory_trees as inspection_campaign')
                ->whereColumn('inspection_campaign.id', $alias.'.inventory_tree_id')
                ->whereColumn('inspection_campaign.tree_id', $alias.'.tree_id')
                ->whereColumn('inspection_campaign.organization_id', $alias.'.organization_id')
                ->where('inspection_campaign.inventory_id', $filters['inventory_id']));
        }
        $ids = array_values(array_unique(array_merge($filters['physical_condition_ids'] ?? [], $filters['phytosanitary_condition_ids'] ?? [])));
        if ($ids !== []) {
            $conditions = fn (QueryBuilder $conditions) => $conditions->from('inspection_conditions as matched_condition')
                ->whereColumn('matched_condition.organization_id', $alias.'.organization_id')
                ->whereColumn('matched_condition.inspection_id', $alias.'.id')->whereIn('matched_condition.condition_id', $ids);
            if (($filters['condition_mode'] ?? 'any') === 'all') {
                $query->where(fn (QueryBuilder $count) => $conditions($count)->selectRaw('COUNT(DISTINCT matched_condition.condition_id)'), '=', count($ids));
            } else {
                $query->whereExists(fn (QueryBuilder $exists) => $conditions($exists)->selectRaw('1'));
            }
        }
    }

    private function riskFilter($query, string $value, string $priority, string $result, string $id): void
    {
        if ($value === 'unevaluated') {
            $query->whereNull($id);
        } elseif ($value === 'inconsistent') {
            $query->whereRaw("{$result}->>'status' = ?", ['inconsistent']);
        } elseif ($value === 'pending') {
            $query->whereNull($priority)->whereRaw("{$result}->>'status' = ?", ['valid']);
        } else {
            $query->where($priority, (int) $value);
        }
    }

    private function requireAlias(string $alias): void
    {
        if (!in_array($alias, ['tree_inspections', 'latest_inspection'], true)) {
            throw new InvalidArgumentException('El alias de consulta de inspección no es válido.');
        }
    }
}

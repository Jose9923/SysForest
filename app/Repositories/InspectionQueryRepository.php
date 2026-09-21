<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Una definición de orden y predicados para visitas históricas y última inspección del árbol. */
class InspectionQueryRepository
{
    public function ordered(Builder|QueryBuilder $query, string $alias = 'tree_inspections'): Builder|QueryBuilder
    {
        $this->requireAlias($alias);

        return $query->orderByDesc($alias.'.inspection_date')->orderByDesc($alias.'.created_at')->orderByDesc($alias.'.id');
    }

    /** Primero elige la visita global; los filtros se aplican exclusivamente fuera de este LIMIT. */
    public function latestForTreeSubquery(): QueryBuilder
    {
        return $this->ordered(DB::table('tree_inspections')
            ->whereColumn('tree_inspections.organization_id', 'trees.organization_id')
            ->whereColumn('tree_inspections.tree_id', 'trees.id')
            ->select('tree_inspections.*'))->limit(1);
    }

    public function applyFilters(Builder $query, array $filters, string $alias = 'tree_inspections'): void
    {
        $this->requireAlias($alias);
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

    private function requireAlias(string $alias): void
    {
        if (!in_array($alias, ['tree_inspections', 'latest_inspection'], true)) {
            throw new InvalidArgumentException('El alias de consulta de inspección no es válido.');
        }
    }
}

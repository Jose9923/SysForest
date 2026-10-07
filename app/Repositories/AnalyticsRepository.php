<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\ReportSelectionDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Agrega el universo autorizado del motor común, sin materializar individuos ni datos personales. */
class AnalyticsRepository
{
    public function __construct(private MapTreeRepository $trees)
    {
    }

    public function summary(ReportSelectionDTO $selection, bool $includeInspections): array
    {
        $query = $this->trees->query($selection->scope, $selection->filters)->toBase()->select([])->selectRaw(
            'COUNT(*) AS total_trees, COUNT(trees.geometry) AS georeferenced, '
            .'COUNT(DISTINCT trees.species_id) AS species_count, COUNT(DISTINCT trees.organization_id) AS organizations_count, '
            .'COUNT(*) FILTER (WHERE trees.is_protected) AS protected_trees, COUNT(*) FILTER (WHERE trees.is_heritage) AS heritage_trees'
        );
        if ($includeInspections) {
            $query->selectRaw('COUNT(latest_inspection.id) AS with_inspection');
        }
        $row = $query->first();
        $total = (int) $row->total_trees;

        return ['totalTrees' => $total, 'georeferenced' => (int) $row->georeferenced,
            'withoutGeometry' => $total - (int) $row->georeferenced, 'speciesCount' => (int) $row->species_count,
            'organizationsCount' => (int) $row->organizations_count, 'protectedTrees' => (int) $row->protected_trees,
            'heritageTrees' => (int) $row->heritage_trees,
            'withInspection' => $includeInspections ? (int) $row->with_inspection : null,
            'withoutInspection' => $includeInspections ? $total - (int) $row->with_inspection : null];
    }

    public function inventories(ReportSelectionDTO $selection): array
    {
        $query = $this->selected($selection)
            ->join('inventory_trees as participation', fn ($join) => $join
                ->on('participation.tree_id', '=', 'selected.tree_id')->on('participation.organization_id', '=', 'selected.organization_id'))
            ->join('inventories', fn ($join) => $join->on('inventories.id', '=', 'participation.inventory_id')
                ->on('inventories.organization_id', '=', 'selected.organization_id'))
            ->join('organizations', 'organizations.id', '=', 'selected.organization_id');
        if ($selection->filters->trees->has('inventory_id')) {
            $query->where('inventories.id', $selection->filters->trees->get('inventory_id'));
        }

        return $this->rows($query->selectRaw('organizations.name AS organization, inventories.code AS code, inventories.name AS "group", COUNT(DISTINCT selected.tree_id) AS trees')
            ->groupBy('organizations.id', 'organizations.name', 'inventories.id', 'inventories.code', 'inventories.name')
            ->orderByDesc('trees')->orderBy('organizations.name')->orderBy('inventories.code'), ['trees']);
    }

    /** Cuenta registros por su fecha almacenada; no infiere visitas reales ni crecimiento biológico. */
    public function timeline(ReportSelectionDTO $selection): array
    {
        $query = $this->selected($selection)->join('tree_inspections as visits', fn ($join) => $join
            ->on('visits.tree_id', '=', 'selected.tree_id')->on('visits.organization_id', '=', 'selected.organization_id'));
        $filters = $selection->filters->trees;
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if ($filters->has($field)) {
                $query->where('visits.inspection_date', $operator, $filters->get($field));
            }
        }
        if ($filters->has('inventory_id')) {
            $query->whereExists(fn (Builder $participation) => $participation->selectRaw('1')->from('inventory_trees as visit_inventory')
                ->whereColumn('visit_inventory.id', 'visits.inventory_tree_id')->whereColumn('visit_inventory.tree_id', 'visits.tree_id')
                ->whereColumn('visit_inventory.organization_id', 'visits.organization_id')
                ->where('visit_inventory.inventory_id', $filters->get('inventory_id')));
        }

        return $this->rows($query->selectRaw("TO_CHAR(visits.inspection_date, 'YYYY-MM') AS month, COUNT(DISTINCT visits.id) AS inspections, COUNT(DISTINCT selected.tree_id) AS trees")
            ->groupByRaw("TO_CHAR(visits.inspection_date, 'YYYY-MM')")->orderBy('month'), ['inspections', 'trees']);
    }

    private function selected(ReportSelectionDTO $selection): Builder
    {
        $query = $this->trees->query($selection->scope, $selection->filters)->toBase()
            ->select(['trees.id as tree_id', 'trees.organization_id']);

        return DB::query()->fromSub($query, 'selected');
    }

    private function rows(Builder $query, array $integers): array
    {
        $limit = max(1, (int) config('reports.max_aggregate_rows', 10000));
        $rows = $query->limit($limit + 1)->get();
        if ($rows->count() > $limit) {
            throw ValidationException::withMessages(['filters' => 'Hay demasiados grupos. Aplica filtros más específicos.']);
        }

        return $rows->map(function (object $row) use ($integers): array {
            $values = (array) $row;
            foreach ($integers as $key) {
                $values[$key] = (int) $values[$key];
            }

            return $values;
        })->all();
    }
}

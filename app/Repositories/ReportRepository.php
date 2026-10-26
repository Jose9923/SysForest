<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\ReportSelectionDTO;
use App\Enums\MaintenanceStatus;
use App\Enums\TreeStatus;
use App\Reports\ReportCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Solo agrega el conjunto del motor común; los Services autorizan previamente la selección. */
class ReportRepository
{
    public function __construct(
        private MapTreeRepository $trees,
        private TreeQueryRepository $queries,
        private PublicVisibilityRepository $visibility,
        private ReportCatalog $catalog,
    ) {
    }

    public function aggregate(ReportSelectionDTO $selection, string $report): array
    {
        $definition = $this->catalog->get($report);
        if (!$definition['available']) {
            throw ValidationException::withMessages(['report' => $definition['notice']]);
        }
        $totalTrees = $this->trees->query($selection->scope, $selection->filters, false)->count('trees.id');
        $columns = [$this->column('group', 'Grupo'), $this->column('trees', 'Individuos', 'integer', 'árboles')];
        $notices = [];

        $rows = match ($report) {
            'inventory' => [['group' => 'Individuos del conjunto filtrado', 'trees' => $totalTrees]],
            'species' => $this->named($selection, 'species', 'species_id', 'scientific_name'),
            'families' => $this->families($selection),
            'habits' => $this->named($selection, 'growth_habits', 'growth_habit_id'),
            'states' => $this->states($selection),
            'risk' => $this->risks($selection),
            'carbon', 'carbon_coverage' => $this->carbon($selection, $report),
            'origins' => $this->origins($selection),
            'diameter_classes', 'height_classes' => $this->classes($selection, $report),
            'physical_conditions', 'phytosanitary_conditions' => $this->conditions($selection, $report),
            'maintenances' => $this->maintenances($selection),
            'municipalities' => $this->municipalities($selection),
            'organizations' => $this->organizations($selection),
            'inspections' => $this->inspections($selection),
            'fauna' => $this->fauna($selection),
            'protected' => $this->protected($selection),
        };

        if (in_array($report, ['origins', 'protected'], true)) {
            array_unshift($columns, $this->column('dimension', 'Dimensión'));
            $notices[] = 'Las dimensiones son independientes: no se deben sumar entre sí.';
        }
        if (in_array($report, ['diameter_classes', 'height_classes'], true)) {
            array_unshift($columns, $this->column('rule_version', 'Versión de clasificación'));
            $notices[] = 'El número de clase se interpreta con la versión de regla registrada en la última inspección.';
        }
        if (in_array($report, ['physical_conditions', 'phytosanitary_conditions', 'fauna'], true)) {
            $notices[] = 'Cada grupo cuenta individuos distintos. Un individuo puede pertenecer a varios grupos; la suma no representa el total de árboles.';
        }
        if (in_array($report, ['physical_conditions', 'phytosanitary_conditions'], true)) {
            array_unshift($columns, $this->column('category', 'Categoría'));
        }
        if ($report === 'maintenances') {
            $columns = [$this->column('type', 'Tipo'), $this->column('status', 'Estado'),
                $this->column('orders', 'Órdenes', 'integer', 'mantenimientos'), $this->column('trees', 'Individuos', 'integer', 'árboles')];
            $notices[] = 'Se cuentan órdenes e individuos distintos por grupo; un individuo puede tener varias órdenes y aparecer en distintos grupos.';
            $notices[] = $selection->isPublic() ? 'Solo se incluyen órdenes ejecutadas o verificadas, no anuladas.' : 'Las órdenes anuladas están excluidas. Sin filtros de mantenimiento, las canceladas se muestran como un estado independiente.';
        }
        if ($report === 'municipalities') {
            array_unshift($columns, $this->column('department', 'Departamento'));
        }
        if ($report === 'organizations') {
            array_unshift($columns, $this->column('code', 'Código institucional'));
        }
        if (in_array($report, ['carbon', 'carbon_coverage'], true)) {
            $notices[] = 'Las referencias bibliográficas precargadas permiten cálculos preliminares y no acreditan revisión profesional local. El reporte identifica cuántos estimados incluidos usan esas referencias.';
            $notices[] = 'Estimación preliminar de biomasa aérea con Chave (2014) y fracción de carbono IPCC 0,47. Requiere validación local; no incluye raíces ni suelo, ni representa captura anual o compensación de emisiones.';
            $notices[] = 'Solo se agrega la última visita de cada árbol actualmente Sano, En observación o Trasplantado. Las densidades y los límites son institucionales. Los faltantes no equivalen a cero; pueden coexistir referencias históricas distintas.';
            if ($report === 'carbon') {
                array_unshift($notices, \App\Support\CarbonPresentation::coverageNotice($rows));
                $columns = [$this->column('group', 'Especie'), $this->column('trees', 'Individuos', 'integer'),
                    $this->column('estimated', 'Estimados incluidos', 'integer'),
                    $this->column('provisional', 'Con referencia bibliográfica provisional', 'integer'),
                    $this->column('reviewed', 'Con referencia revisada por profesional', 'integer'), $this->column('pending', 'Pendientes o excluidos', 'integer'),
                    $this->column('biomass_kg', 'Biomasa aérea seca', 'decimal', 'kg'),
                    $this->column('carbon_kg', 'Carbono aéreo almacenado', 'decimal', 'kg'),
                    $this->column('co2_kg', 'CO₂ correspondiente al carbono aéreo', 'decimal', 'kg')];
            }
        }
        if ($report === 'risk') {
            $notices[] = 'Se muestra la decisión profesional de la última inspección; las sugerencias sin confirmar se cuentan aparte. Cada institución aplica su propia metodología y versión.';
        }
        if ($report === 'inspections') {
            $notices[] = 'Este resumen considera únicamente la última inspección pertinente de cada individuo, no el número de visitas históricas.';
        }
        $this->assertLimit(count($rows));

        return compact('columns', 'rows', 'totalTrees', 'notices') + ['totalRows' => count($rows)];
    }

    /** La proyección intermedia preserva una fila por árbol antes de expandir grupos multivaluados. */
    private function selected(ReportSelectionDTO $selection): Builder
    {
        $query = $this->trees->query($selection->scope, $selection->filters, false)->toBase()->select([
            'trees.id as tree_id', 'trees.organization_id', 'trees.species_id', 'trees.growth_habit_id',
            'trees.establishment_origin_id', 'trees.biogeographic_origin_id', 'trees.current_status',
            'trees.is_protected', 'trees.is_heritage', 'latest_inspection.id as inspection_id',
            'latest_inspection.classification_rule_version_id', 'latest_inspection.diameter_class_number',
            'latest_inspection.height_class_number',
        ]);

        if (!$selection->isPublic()) {
            $query->addSelect('latest_inspection.carbon_validation_status', 'latest_inspection.carbon_status', 'latest_inspection.carbon_biomass_kg', 'latest_inspection.carbon_carbon_kg', 'latest_inspection.carbon_co2_kg', 'latest_inspection.risk_priority', 'latest_inspection.risk_result', 'latest_inspection.risk_evaluation_id');
        }

        return DB::query()->fromSub($query, 'selected');
    }

    /** Las tablas y columnas son constantes internas elegidas por el catálogo cerrado. */
    private function named(ReportSelectionDTO $selection, string $table, string $foreignKey, string $name = 'name'): array
    {
        return $this->rows($this->selected($selection)
            ->leftJoin($table.' as grouping', 'grouping.id', '=', 'selected.'.$foreignKey)
            ->selectRaw('COALESCE(grouping.'.$name.', ?) AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sin dato'])
            ->groupBy('grouping.id', 'grouping.'.$name)->orderBy('grouping.'.$name));
    }

    private function families(ReportSelectionDTO $selection): array
    {
        return $this->rows($this->selected($selection)
            ->leftJoin('species', 'species.id', '=', 'selected.species_id')
            ->leftJoin('families', 'families.id', '=', 'species.family_id')
            ->selectRaw('COALESCE(families.scientific_name, ?) AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sin dato'])
            ->groupBy('families.id', 'families.scientific_name')->orderBy('families.scientific_name'));
    }

    private function carbon(ReportSelectionDTO $selection, string $report): array
    {
        abort_if($selection->isPublic(), 404);
        $live = "selected.current_status IN ('active', 'under_observation', 'transplanted')";
        $included = "({$live} AND selected.carbon_status = 'estimated')";
        if ($report === 'carbon_coverage') {
            $expression = "CASE WHEN NOT ({$live}) THEN 'excluded_status' ELSE COALESCE(selected.carbon_status, 'unprocessed') END";
            $rows = $this->rows($this->selected($selection)->selectRaw($expression.' AS "group", COUNT(*) AS trees')
                ->groupByRaw($expression)->orderBy('group'));

            return array_map(fn ($row) => array_replace($row, ['group' => \App\Support\CarbonPresentation::label($row['group'])]), $rows);
        }

        return $this->rows($this->selected($selection)->leftJoin('species', 'species.id', '=', 'selected.species_id')
            ->selectRaw('COALESCE(species.scientific_name, ?) AS "group", COUNT(*) AS trees, '
                ."COUNT(*) FILTER (WHERE {$included}) AS estimated, COUNT(*) FILTER (WHERE {$included} AND selected.carbon_validation_status = 'bibliographic') AS provisional, COUNT(*) FILTER (WHERE {$included} AND selected.carbon_validation_status = 'professional') AS reviewed, COUNT(*) FILTER (WHERE NOT COALESCE({$included}, FALSE)) AS pending, "
                ."SUM(selected.carbon_biomass_kg) FILTER (WHERE {$included}) AS biomass_kg, "
                ."SUM(selected.carbon_carbon_kg) FILTER (WHERE {$included}) AS carbon_kg, "
                ."SUM(selected.carbon_co2_kg) FILTER (WHERE {$included}) AS co2_kg", ['Sin especie'])
            ->groupBy('species.id', 'species.scientific_name')->orderBy('species.scientific_name'), ['trees', 'estimated', 'pending', 'provisional', 'reviewed']);
    }

    private function risks(ReportSelectionDTO $selection): array
    {
        abort_if($selection->isPublic(), 404);
        $expression = "CASE WHEN selected.risk_evaluation_id IS NULL THEN 'Sin evaluar' "
            ."WHEN selected.risk_result->>'status' = 'inconsistent' THEN 'Inconsistente' "
            ."WHEN selected.risk_priority IS NULL THEN 'Pendiente de confirmación' "
            ."WHEN selected.risk_priority = 1 THEN 'Bajo' WHEN selected.risk_priority = 2 THEN 'Moderado' "
            ."WHEN selected.risk_priority = 3 THEN 'Alto' ELSE 'Crítico' END";

        return $this->rows($this->selected($selection)->selectRaw($expression.' AS "group", COUNT(DISTINCT selected.tree_id) AS trees')
            ->groupByRaw($expression)->orderBy('group'));
    }

    private function states(ReportSelectionDTO $selection): array
    {
        $rows = $this->rows($this->selected($selection)->selectRaw('selected.current_status AS "group", COUNT(DISTINCT selected.tree_id) AS trees')
            ->groupBy('selected.current_status')->orderBy('selected.current_status'));

        return array_map(fn (array $row) => ['group' => TreeStatus::from($row['group'])->label(), 'trees' => $row['trees']], $rows);
    }

    private function origins(ReportSelectionDTO $selection): array
    {
        return [
            ...array_map(fn (array $row) => ['dimension' => 'Establecimiento'] + $row, $this->named($selection, 'establishment_origins', 'establishment_origin_id')),
            ...array_map(fn (array $row) => ['dimension' => 'Biogeográfico'] + $row, $this->named($selection, 'biogeographic_origins', 'biogeographic_origin_id')),
        ];
    }

    private function classes(ReportSelectionDTO $selection, string $report): array
    {
        $column = $report === 'diameter_classes' ? 'diameter_class_number' : 'height_class_number';

        $rows = $this->rows($this->selected($selection)
            ->leftJoin('classification_rule_versions as rules', 'rules.id', '=', 'selected.classification_rule_version_id')
            ->selectRaw('COALESCE(rules.code, ?) AS rule_version, COALESCE(CAST(selected.'.$column.' AS text), ?) AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sin inspección', 'Sin dato'])
            ->groupBy('rules.id', 'rules.code', 'selected.'.$column)->orderBy('rules.code')->orderBy('selected.'.$column));

        return array_map(fn (array $row): array => array_replace($row, [
            'rule_version' => \App\Support\ClassificationVersionPresentation::label($row['rule_version']),
        ]), $rows);
    }

    private function conditions(ReportSelectionDTO $selection, string $report): array
    {
        $type = $report === 'physical_conditions' ? 'physical_mechanical' : 'phytosanitary';
        $conditions = DB::table('inspection_conditions as link')
            ->join('conditions', 'conditions.id', '=', 'link.condition_id')
            ->join('condition_categories as categories', 'categories.id', '=', 'conditions.condition_category_id')
            ->whereColumn('link.organization_id', 'selected.organization_id')
            ->whereColumn('link.inspection_id', 'selected.inspection_id')->where('conditions.assessment_type', $type)
            ->select(['conditions.id', 'conditions.name', 'categories.id as category_id', 'categories.name as category']);

        return $this->rows($this->selected($selection)->leftJoinLateral($conditions, 'grouping')
            ->selectRaw('COALESCE(grouping.category, ?) AS category, COALESCE(grouping.name, ?) AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sin dato', 'Sin condición registrada'])
            ->groupBy('grouping.category_id', 'grouping.category', 'grouping.id', 'grouping.name')->orderBy('grouping.category')->orderBy('grouping.name'));
    }

    private function fauna(ReportSelectionDTO $selection): array
    {
        return $this->rows($this->selected($selection)
            ->leftJoin('inspection_fauna as link', fn ($join) => $join->on('link.inspection_id', '=', 'selected.inspection_id')->on('link.organization_id', '=', 'selected.organization_id'))
            ->leftJoin('fauna_categories', 'fauna_categories.id', '=', 'link.fauna_category_id')
            ->selectRaw('COALESCE(fauna_categories.name, ?) AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sin categoría registrada'])
            ->groupBy('fauna_categories.id', 'fauna_categories.name')->orderBy('fauna_categories.name'));
    }

    private function protected(ReportSelectionDTO $selection): array
    {
        $rows = [];
        foreach (['is_protected' => 'Protegido', 'is_heritage' => 'Patrimonial'] as $column => $dimension) {
            $groups = $this->rows($this->selected($selection)
                ->selectRaw('CASE WHEN selected.'.$column.' THEN ? ELSE ? END AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sí', 'No'])
                ->groupBy('selected.'.$column)->orderByDesc('selected.'.$column));
            foreach ($groups as $group) {
                $rows[] = ['dimension' => $dimension] + $group;
            }
        }

        return $rows;
    }

    private function inspections(ReportSelectionDTO $selection): array
    {
        return $this->rows($this->selected($selection)
            ->selectRaw('CASE WHEN selected.inspection_id IS NULL THEN ? ELSE ? END AS "group", COUNT(DISTINCT selected.tree_id) AS trees', ['Sin inspección', 'Con última inspección'])
            ->groupByRaw('selected.inspection_id IS NULL')->orderBy('group'));
    }

    private function organizations(ReportSelectionDTO $selection): array
    {
        $code = $selection->isPublic() ? 'public_code' : 'code';

        return $this->rows($this->selected($selection)->join('organizations', 'organizations.id', '=', 'selected.organization_id')
            ->selectRaw('organizations.'.$code.' AS code, organizations.name AS "group", COUNT(DISTINCT selected.tree_id) AS trees')
            ->groupBy('organizations.id', 'organizations.'.$code, 'organizations.name')->orderBy('organizations.name')->orderBy('organizations.'.$code));
    }

    private function municipalities(ReportSelectionDTO $selection): array
    {
        return $this->rows($this->selected($selection)->join('organizations', 'organizations.id', '=', 'selected.organization_id')
            ->join('municipalities', 'municipalities.id', '=', 'organizations.municipality_id')
            ->join('departments', 'departments.id', '=', 'municipalities.department_id')
            ->selectRaw('departments.name AS department, municipalities.name AS "group", COUNT(DISTINCT selected.tree_id) AS trees')
            ->groupBy('departments.id', 'departments.name', 'municipalities.id', 'municipalities.name')
            ->orderBy('departments.name')->orderBy('municipalities.name')->orderBy('municipalities.id'));
    }

    private function maintenances(ReportSelectionDTO $selection): array
    {
        $query = $this->selected($selection)
            ->join('maintenances as orders', fn ($join) => $join->on('orders.tree_id', '=', 'selected.tree_id')->on('orders.organization_id', '=', 'selected.organization_id'))
            ->join('maintenance_types as types', 'types.id', '=', 'orders.maintenance_type_id')
            ->whereNull('orders.deleted_at');
        if ($selection->filters->trees->hasMaintenanceFilters()) {
            $this->queries->applyMaintenanceFilters($query, $selection->filters->trees, $selection->scope, 'orders');
        } elseif ($selection->isPublic()) {
            $this->visibility->maintenance($query, 'orders');
        }
        $rows = $this->rows($query->selectRaw('types.name AS type, orders.status AS status, COUNT(DISTINCT orders.id) AS orders, COUNT(DISTINCT selected.tree_id) AS trees')
            ->groupBy('types.id', 'types.name', 'orders.status')->orderBy('types.name')->orderBy('orders.status'), ['trees', 'orders']);

        return array_map(fn (array $row) => array_replace($row, ['status' => MaintenanceStatus::from($row['status'])->label()]), $rows);
    }

    private function rows(Builder $query, array $integers = ['trees']): array
    {
        $rows = $query->limit($this->limit() + 1)->get()->map(function (object $row) use ($integers): array {
            $values = (array) $row;
            foreach ($integers as $key) {
                $values[$key] = (int) $values[$key];
            }

            return $values;
        })->all();
        $this->assertLimit(count($rows));

        return $rows;
    }

    private function assertLimit(int $count): void
    {
        if ($count > $this->limit()) {
            throw ValidationException::withMessages(['report' => 'El reporte supera el límite de grupos. Aplique filtros más específicos.']);
        }
    }

    private function limit(): int
    {
        return max(1, (int) config('reports.max_aggregate_rows', 10000));
    }

    private function column(string $key, string $label, string $type = 'text', ?string $unit = null): array
    {
        return array_filter(compact('key', 'label', 'type', 'unit'), fn ($value) => $value !== null);
    }
}

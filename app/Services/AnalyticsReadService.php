<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReportSelectionDTO;
use App\DTOs\TreeReadScope;
use App\Enums\TreeStatus;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Repositories\AnalyticsRepository;
use App\Repositories\ReportRepository;
use App\Repositories\ReportSnapshotRepository;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/** Prepara gráficas con permisos y filtros de reportes; nunca amplía el tenant de escritura. */
class AnalyticsReadService
{
    public function __construct(
        private ReportFilterService $filters,
        private ReportAccessService $access,
        private ReportReadService $presentation,
        private ReportRepository $reports,
        private ReportCatalog $catalog,
        private AnalyticsRepository $analytics,
        private ReportSnapshotRepository $snapshot,
        private PermissionService $permissions,
        private TenantContextService $tenants,
        private TenantContext $context,
    ) {
    }

    public function page(User $actor, array $input): array
    {
        $selection = $this->filters->forUser($actor, $input, 'species');

        return $this->snapshot->read(function () use ($actor, $selection): array {
            $inspections = $this->canReport($actor, $selection, 'inspections');
            $maintenances = $this->canReport($actor, $selection, 'maintenances');
            $export = $this->canReport($actor, $selection, 'species', true);
            $summary = $this->analytics->summary($selection, $inspections);
            $charts = [];
            $keys = ['carbon', 'carbon_coverage', 'risk', 'states', 'species', 'families', 'diameter_classes', 'height_classes',
                'phytosanitary_conditions', 'physical_conditions', 'inspections', 'maintenances'];
            if ($selection->scope->kind !== 'organization') {
                $keys = [...$keys, 'organizations', 'municipalities'];
            }
            foreach ($keys as $key) {
                if ($this->canReport($actor, $selection, $key)) {
                    $charts[] = $this->reportChart($actor, $selection, $key);
                }
            }
            $inventories = $this->canInventory($actor, $selection->scope);
            if ($inventories) {
                $rows = $this->analytics->inventories($selection);
                $charts[] = $this->customChart(
                    'inventories',
                    'Comparar inventarios',
                    'Individuos distintos que participan en cada inventario del conjunto filtrado.',
                    'bar',
                    'árboles',
                    $rows,
                    [$this->column('organization', 'Organización'), $this->column('code', 'Código'), $this->column('group', 'Inventario'),
                        $this->column('trees', 'Individuos', 'integer')],
                    'trees',
                    fn ($row) => $row['organization'].' · '.$row['code'].' · '.$row['group'],
                    ['Un árbol puede participar en varios inventarios; la suma de barras no es el total de individuos.',
                        'El filtro de inventario restringe también esta comparación al inventario seleccionado.'],
                    $export
                );
            }
            if ($inspections) {
                $rows = $this->analytics->timeline($selection);
                $charts[] = $this->customChart(
                    'timeline',
                    'Registros por fecha de inspección',
                    'Distribución mensual de registros históricos de los árboles seleccionados.',
                    'line',
                    'registros',
                    $rows,
                    [$this->column('month', 'Mes'), $this->column('inspections', 'Registros', 'integer'),
                        $this->column('trees', 'Individuos distintos', 'integer')],
                    'inspections',
                    fn ($row) => $row['month'],
                    ['Las fechas importadas pueden ser fechas de referencia. En Restrepo, 30/11/2025 corresponde a la entrega y no a un día de visita conocido.',
                        'Este gráfico no representa crecimiento del arbolado ni certifica visitas realizadas. Solo muestra meses con registros; entre puntos puede haber meses omitidos.',
                        'Los filtros seleccionan árboles por su última inspección; esta serie consulta su historia. Fechas e inventario también delimitan los registros de la serie.'],
                    $export
                );
            }

            return $this->presentation->metadata($actor, $selection, 'species') + [
                'filters' => $selection->values, 'summary' => $summary, 'charts' => $charts,
                'capabilities' => ['inspections' => $inspections, 'maintenances' => $maintenances, 'inventories' => $inventories, 'export' => $export],
                'coverage' => ['total' => $summary['totalTrees'], 'georeferenced' => $summary['georeferenced'],
                    'withoutGeometry' => $summary['withoutGeometry'], 'withInspection' => $summary['withInspection'],
                    'withoutInspection' => $summary['withoutInspection'],
                    'notice' => 'Un mismo árbol puede tener ubicación, inspección, ambas o ninguna.'],
                'notices' => ['Los indicadores cuentan individuos distintos y conservan el alcance autorizado.',
                    'Las condiciones y medidas corresponden a la última inspección pertinente; el estado es el actual del árbol.',
                    'El encuadre del mapa no limita este tablero. Los filtros y un radio explícito sí se aplican.'],
            ];
        });
    }

    private function reportChart(User $actor, ReportSelectionDTO $selection, string $key): array
    {
        $definition = $this->catalog->get($key);
        $data = $this->reports->aggregate($selection, $key);
        $metric = match ($key) {
            'maintenances' => 'orders', 'carbon' => 'co2_kg', default => 'trees'
        };
        $rows = $data['rows'];
        $labels = fn ($row) => match ($key) {
            'diameter_classes', 'height_classes' => $row['rule_version'].' · '.$row['group'],
            'phytosanitary_conditions', 'physical_conditions' => $row['category'].' · '.$row['group'],
            'maintenances' => $row['type'].' · '.$row['status'],
            'municipalities' => $row['department'].' · '.$row['group'],
            'organizations' => $row['code'].' · '.$row['group'],
            default => $row['group'],
        };
        $plotted = $rows;
        $notices = $data['notices'];
        if (!in_array($key, ['diameter_classes', 'height_classes'], true)) {
            usort($plotted, fn ($left, $right) => $right[$metric] <=> $left[$metric] ?: strcmp($labels($left), $labels($right)));
        }
        $limit = in_array($key, ['species', 'families'], true) ? 10 : 12;
        $visible = array_slice($plotted, 0, $limit);
        $chartLabels = array_map($labels, $visible);
        $values = array_column($visible, $metric);
        if (count($plotted) > $limit) {
            if (in_array($key, ['species', 'families'], true)) {
                $chartLabels[] = 'Otros grupos';
                $values[] = array_sum(array_column(array_slice($plotted, $limit), $metric));
                $notices[] = 'La gráfica muestra los diez grupos principales y reúne los restantes en Otros grupos. La tabla conserva todos.';
            } else {
                $notices[] = 'La gráfica muestra los primeros '.$limit.' grupos; la tabla y las descargas conservan todos. No se suman grupos que pueden superponerse.';
            }
        }
        $canExport = $this->canReport($actor, $selection, $key, true);
        $downloads = [];
        if ($canExport) {
            foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label) {
                if ($format === 'pdf' && count($rows) > (int) config('reports.max_pdf_rows', 200)) {
                    continue;
                }
                $downloads[] = ['format' => $format, 'label' => $label,
                    'url' => route('reports.download', ['format' => $format, 'report' => $key] + $selection->values, false)];
            }
        }

        $colors = [];
        if ($key === 'states') {
            $statusColors = [];
            foreach (TreeStatus::cases() as $status) {
                $statusColors[$status->label()] = (string) config('maps.colors.'.$status->value);
            }
            $colors = array_map(fn (string $label) => $statusColors[$label], $chartLabels);
        }

        return ['key' => $key, 'title' => $definition['title'], 'description' => $definition['description'],
            'type' => in_array($key, ['states', 'inspections'], true) ? 'doughnut' : 'bar',
            'labels' => $chartLabels, 'values' => $values, 'colors' => $colors, 'unit' => $definition['unit'],
            'rows' => $rows, 'columns' => $data['columns'], 'notices' => $notices, 'downloads' => $downloads,
            'exportable' => $canExport, 'reportUrl' => route('reports.index', ['report' => $key] + $selection->values, false),
            'totalGroups' => count($rows)];
    }

    private function customChart(
        string $key,
        string $title,
        string $description,
        string $type,
        string $unit,
        array $rows,
        array $columns,
        string $metric,
        callable $label,
        array $notices,
        bool $exportable
    ): array {
        $visible = $type === 'line' ? array_slice($rows, -36) : array_slice($rows, 0, 12);
        if (count($rows) > count($visible)) {
            $notices[] = $type === 'line' ? 'La gráfica muestra los últimos 36 meses con registros; la tabla conserva todos.'
                : 'La gráfica muestra los doce inventarios con más individuos; la tabla conserva todos.';
        }

        return compact('key', 'title', 'description', 'type', 'unit', 'rows', 'columns', 'notices', 'exportable')
            + ['labels' => array_map($label, $visible), 'values' => array_column($visible, $metric),
                'downloads' => [], 'reportUrl' => null, 'totalGroups' => count($rows)];
    }

    private function canReport(User $actor, ReportSelectionDTO $selection, string $key, bool $export = false): bool
    {
        try {
            $this->access->authorize($actor, $selection, $key, $export);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Inventarios requiere su propio permiso en el mismo alcance ya autorizado para los árboles. */
    private function canInventory(User $actor, TreeReadScope $scope): bool
    {
        if ($scope->kind === 'national') {
            return $this->permissions->allowsNational($actor, 'inventories.view');
        }
        if ($scope->kind === 'department') {
            foreach ($scope->departmentIds as $departmentId) {
                if (!$this->permissions->allowsDepartment($actor, 'inventories.view', $departmentId)) {
                    return false;
                }
            }

            return $scope->departmentIds !== [];
        }
        $organization = $this->tenants->assertCurrentAccess($actor);
        if ($organization->id !== $scope->organizationId) {
            return false;
        }

        return $this->context->accessMode() === 'department_read'
            ? $this->permissions->allowsDepartment($actor, 'inventories.view', $organization->municipality->department_id)
            : $this->permissions->allowsOrganization($actor, 'inventories.view', $organization->id);
    }

    private function column(string $key, string $label, string $type = 'text'): array
    {
        return compact('key', 'label', 'type');
    }
}

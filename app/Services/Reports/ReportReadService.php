<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\DTOs\Reports\ReportSelectionDTO;
use App\Models\User;
use App\Reports\ExportFormat;
use App\Reports\InventoryExportSchema;
use App\Reports\ReportCatalog;
use App\Reports\RiskTreeSchema;
use App\Repositories\Reports\ExportReadRepository;
use App\Repositories\Maps\MapTreeRepository;
use App\Repositories\Reports\ReportRepository;
use App\Validation\Maps\MapInput;
use App\Validation\Trees\TreeFilterInput;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use App\Services\Maps\MapReadService;
use App\Services\PublicPortal\PublicMapService;

/** Prepara tablas, criterios legibles y enlaces; todas las selecciones proceden del motor común. */
class ReportReadService
{
    public function __construct(
        private ReportFilterService $filters,
        private ReportCatalog $catalog,
        private ReportRepository $reports,
        private ExportReadRepository $trees,
        private InventoryExportSchema $schema,
        private MapTreeRepository $mapTrees,
        private MapReadService $privateMap,
        private PublicMapService $publicMap,
        private RiskTreeSchema $riskSchema,
    ) {
    }

    public function forUser(User $actor, array $input): array
    {
        [$key, $page] = $this->controls($input);

        return $this->page($actor, $this->filters->forUser($actor, $input, $key), $key, $page);
    }

    public function forRiskInbox(User $actor, array $input): array
    {
        [, $page] = $this->controls(array_replace($input, ['report' => 'risk_trees']));

        return $this->page($actor, $this->filters->forRiskInbox($actor, $input), 'risk_trees', $page);
    }

    public function forPublic(array $input): array
    {
        [$key, $page] = $this->controls($input);

        return $this->page(null, $this->filters->forPublic($input, $key), $key, $page);
    }

    public function controls(array $input): array
    {
        $data = Validator::make($input, ['report' => ['sometimes', 'required', 'string', 'max:60'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'format' => ['prohibited']], [
            'required' => 'Selecciona un reporte.', 'string' => 'El reporte no es válido.',
            'integer' => 'La página debe ser un entero.', 'min' => 'La página debe ser positiva.',
            'max' => 'El valor excede el límite permitido.', 'prohibited' => 'El formato se elige en el enlace de descarga.',
        ])->validate();
        $key = $data['report'] ?? 'inventory';
        $this->catalog->get($key);

        return [$key, (int) ($data['page'] ?? 1)];
    }

    public function inventoryColumns(bool $public): array
    {
        $visible = ['carbon_status', 'carbon_carbon_kg', 'carbon_co2_kg', 'risk_priority', 'risk_suggestion', 'risk_methodology', 'code', 'common_names', 'scientific_name', 'municipality', 'organization',
            'placement', 'current_status_label', 'latest_inspection_date'];

        return array_values(array_filter($this->schema->columns($public), fn ($column) => in_array($column['key'], $visible, true)));
    }

    public function metadata(?User $actor, ReportSelectionDTO $selection, string $key): array
    {
        $definition = $this->catalog->get($key);
        $seriesFields = ['physical_condition_category_ids' => 'physical_mechanical',
            'phytosanitary_condition_category_ids' => 'phytosanitary'];
        $mapInput = array_diff_key($selection->values, $seriesFields);
        $map = $selection->isPublic() ? $this->publicMap->page($mapInput)
            : $this->privateMap->page($actor, $mapInput);
        if (!$selection->isPublic()) {
            foreach ($seriesFields as $field => $type) {
                $ids = $selection->values[$field] ?? [];
                $map['selectedOptions'][$field] = ($map['conditionFilterCategories'][$type] ?? collect())
                    ->filter(fn ($category) => in_array($category->id, $ids, true))
                    ->map(fn ($category) => ['id' => $category->id, 'text' => $category->name])->values()->all();
            }
        }
        $scopeLabel = $selection->isPublic() ? 'Información publicada · consulta ciudadana' : $map['mapConfig']['scope']['label'];

        return ['title' => $definition['title'], 'generatedAt' => now()->toIso8601String(),
            'isPublic' => $selection->isPublic(), 'scopeLabel' => $scopeLabel,
            'appliedFilters' => $this->applied($selection->values, $map['selectedOptions']),
            'selectedOptions' => $map['selectedOptions'], 'conditionFilterCategories' => $map['conditionFilterCategories'],
            'filterConfig' => $this->filterConfig($map['mapConfig'], $selection->isPublic())];
    }

    private function page(?User $actor, ReportSelectionDTO $selection, string $key, int $page): array
    {
        $definition = $this->catalog->get($key);
        $perPage = (int) config('reports.page_size', 25);
        $summary = $this->mapTrees->summary($selection->scope, $selection->filters);
        if ($key === 'risk_trees') {
            abort_if($selection->isPublic(), 404);
            $rows = $this->trees->paginate($selection, $page, $perPage, riskOnly: true);
            $columns = $this->riskSchema->columns();
            $notices = $this->riskSchema->notices();
            $totalRows = $rows->total();
        } elseif ($key === 'inventory') {
            $rows = $this->trees->paginate($selection, $page, $perPage);
            $columns = $this->inventoryColumns($selection->isPublic());
            $notices = ['La tabla resume el inventario. Excel, CSV y GeoJSON incluyen las columnas permitidas del perfil completo.',
                'Los reportes usan la última inspección pertinente. El encuadre del mapa no limita el conjunto; un radio explícito sí.'];
            $totalRows = $rows->total();
        } else {
            $data = $this->reports->aggregate($selection, $key);
            $columns = $data['columns'];
            $notices = $data['notices'];
            $totalRows = $data['totalRows'];
            $rows = new LengthAwarePaginator(array_slice($data['rows'], ($page - 1) * $perPage, $perPage), $totalRows, $perPage, $page);
        }
        $route = $selection->isPublic() ? 'public.reports.index' : 'reports.index';
        $rows->withPath(route($route, absolute: false))->appends(['report' => $key] + $selection->values);
        $downloads = [];
        foreach (ExportFormat::cases() as $format) {
            if (($format === ExportFormat::Geojson && $key !== 'inventory')
                || ($format === ExportFormat::Pdf && $totalRows > (int) config('reports.max_pdf_rows', 200))) {
                continue;
            }
            $downloads[] = ['format' => $format->value, 'label' => 'Descargar '.$format->label(),
                'url' => route(
                    $selection->isPublic() ? 'public.reports.download' : 'reports.download',
                    ['format' => $format->value, 'report' => $key] + $selection->values,
                    false
                )];
        }
        if ($totalRows > (int) config('reports.max_pdf_rows', 200)) {
            $notices[] = 'Para PDF, limita la consulta a '.config('reports.max_pdf_rows').' filas. Excel y CSV permiten conjuntos mayores.';
        }

        return $this->metadata($actor, $selection, $key) + ['report' => $definition, 'reports' => array_values(array_filter($this->catalog->definitions(), fn ($item) => !$selection->isPublic() || !in_array($item['key'], ['risk', 'risk_trees', 'carbon', 'carbon_coverage'], true))),
            'filters' => $selection->values, 'summary' => ['totalTrees' => $summary['total'], 'totalRows' => $totalRows,
                'georeferenced' => $summary['georeferenced'], 'withoutGeometry' => $summary['without_geometry']],
            'columns' => $columns, 'rows' => $rows, 'notices' => $notices, 'downloads' => $downloads,
            'seo' => ['title' => $definition['title'].' · SYSFOREST', 'description' => 'Reportes del inventario forestal publicado.',
                'canonical' => rtrim((string) config('app.url'), '/').'/portal/reports?report='.$key]];
    }

    private function filterConfig(array $config, bool $public): array
    {
        $keys = $config['filter_keys'] ?? array_keys(TreeFilterInput::rules() + MapInput::rules(false));
        $keys = array_values(array_filter($keys, fn ($key) => !str_contains($key, '.')
            && !in_array($key, ['tree_id', 'trashed', 'include_deleted', 'include_voided_trees', 'page', 'render_mode', 'term', 'west', 'east', 'south', 'north', 'zoom'], true)));
        $config['filter_keys'] = array_values(array_unique([...$keys, 'radius_latitude', 'radius_longitude', 'radius_meters',
            'cap_cm_min', 'cap_cm_max', 'dap_m_min', 'dap_m_max', 'total_height_m_min', 'total_height_m_max', 'sort', 'direction']));

        if (!$public) {
            $config['filter_keys'] = array_values(array_unique([...$config['filter_keys'],
                'physical_condition_category_ids', 'phytosanitary_condition_category_ids']));
        }

        return $config;
    }

    private function applied(array $values, array $selected): array
    {
        $labels = ['risk_priority' => 'Prioridad fitosanitaria', 'q' => 'Búsqueda', 'code' => 'Código', 'common_name' => 'Nombre común (texto)', 'scientific_name' => 'Nombre científico (texto)',
            'department_id' => 'Departamento', 'municipality_id' => 'Municipio', 'organization_id' => 'Organización', 'inventory_id' => 'Inventario',
            'species_id' => 'Nombre científico', 'common_name_id' => 'Nombre común', 'family_id' => 'Familia', 'placement_id' => 'Emplazamiento', 'growth_habit_id' => 'Hábito',
            'establishment_origin_id' => 'Origen de establecimiento', 'biogeographic_origin_id' => 'Origen biogeográfico',
            'maturity_state_id' => 'Madurez', 'current_status' => 'Estado del árbol', 'date_from' => 'Desde', 'date_to' => 'Hasta',
            'is_protected' => 'Protegido', 'is_heritage' => 'Patrimonial', 'diameter_class_number' => 'Clase diamétrica',
            'height_class_number' => 'Clase de altura', 'physical_condition_ids' => 'Condiciones físico-mecánicas',
            'phytosanitary_condition_ids' => 'Condiciones fitosanitarias', 'condition_mode' => 'Coincidencia de condiciones',
            'physical_condition_category_ids' => 'Órganos mostrados · físico-mecánico',
            'phytosanitary_condition_category_ids' => 'Órganos mostrados · fitosanitario',
            'maintenance_type_id' => 'Tipo de mantenimiento', 'maintenance_status' => 'Estado del mantenimiento',
            'maintenance_priority' => 'Prioridad de mantenimiento', 'has_pending_maintenance' => 'Mantenimiento pendiente',
            'radius_latitude' => 'Centro del radio · latitud', 'radius_longitude' => 'Centro del radio · longitud', 'radius_meters' => 'Radio (m)',
            'cap_cm_min' => 'CAP mínimo (cm)', 'cap_cm_max' => 'CAP máximo (cm)', 'dap_m_min' => 'DAP mínimo (m)',
            'dap_m_max' => 'DAP máximo (m)', 'total_height_m_min' => 'Altura mínima (m)', 'total_height_m_max' => 'Altura máxima (m)',
            'sort' => 'Orden', 'direction' => 'Dirección'];
        $result = [];
        foreach ($values as $key => $value) {
            if (!isset($labels[$key]) || $value === null || $value === '' || $value === []) {
                continue;
            }
            $options = $selected[$key] ?? [];
            if ($options !== [] && is_array($options)) {
                $texts = isset($options['text']) ? [$options['text']] : array_column($options, 'text');
                if ($texts !== []) {
                    $value = implode(', ', $texts);
                }
            }
            $value = is_array($value) ? implode(', ', $value) : $value;
            $value = match ($key) {
                'risk_priority' => match ((string) $value) {
                    'pending' => 'Pendiente de confirmación', 'inconsistent' => 'Inconsistente', 'unevaluated' => 'Sin evaluar',
                    default => \App\Enums\Risk\RiskPriority::tryFrom((int) $value)?->label() ?? $value,
                },
                'current_status' => \App\Enums\Trees\TreeStatus::tryFrom((string) $value)?->label() ?? $value,
                'maintenance_status' => \App\Enums\Maintenances\MaintenanceStatus::tryFrom((string) $value)?->label() ?? $value,
                'maintenance_priority' => \App\Enums\Maintenances\MaintenancePriority::tryFrom((string) $value)?->label() ?? $value,
                'condition_mode' => $value === 'all' ? 'Todas' : 'Cualquiera',
                'direction' => $value === 'desc' ? 'Descendente' : 'Ascendente',
                default => $value,
            };
            if (in_array($key, ['is_protected', 'is_heritage', 'has_pending_maintenance'], true)) {
                $value = in_array($value, [true, 1, '1'], true) ? 'Sí' : 'No';
            }
            $result[] = ['label' => $labels[$key], 'value' => (string) $value];
        }

        return $result;
    }
}

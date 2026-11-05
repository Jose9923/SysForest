<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\Enums\Maintenances\MaintenancePriority;
use App\Enums\Maintenances\MaintenanceStatus;
use App\Enums\Trees\TreeStatus;
use App\Http\Resources\Maps\MapCellResource;
use App\Http\Resources\Maps\MapTreeResource;
use App\Models\User;
use App\Repositories\Maps\MapOptionRepository;
use App\Repositories\Maps\MapTreeRepository;
use Illuminate\Support\Facades\Validator;
use App\Services\Conditions\ConditionFilterOptionsService;

/** Prepara Blade y GeoJSON con un único alcance, filtros compartidos y proyecciones mínimas. */
class MapReadService
{
    public function __construct(
        private MapFilterService $filters,
        private MapAccessService $access,
        private MapTreeRepository $trees,
        private MapOptionRepository $options,
        private ConditionFilterOptionsService $conditionOptions,
    ) {
    }

    public function page(User $actor, array $input): array
    {
        ['scope' => $scope, 'filters' => $filters] = $this->filters->context($actor, $input);
        $legend = [];
        foreach (TreeStatus::cases() as $status) {
            $legend[] = ['status' => $status->value, 'label' => $status->label(), 'color' => config('maps.colors.'.$status->value)];
        }
        $enumOptions = fn (array $cases) => array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);
        $mode = in_array($input['render_mode'] ?? '', ['points', 'clusters', 'heat'], true) ? $input['render_mode'] : 'clusters';
        $initial = $filters->trees->values() + ['render_mode' => $mode];
        if (isset($input['scope'])) {
            $initial['scope'] = $scope->kind;
        }

        $canInspect = $this->access->allows($actor, $scope, 'inspections.view');

        return ['initialFilters' => $initial, 'selectedOptions' => $this->options->selected($scope, $filters->trees->values()),
            'conditionFilterCategories' => $canInspect ? $this->conditionOptions->groups() : collect(),
            'mapConfig' => [
                'endpoints' => [
                    'trees' => route('maps.trees', absolute: false), 'clusters' => route('maps.clusters', absolute: false),
                    'heat' => route('maps.heat', absolute: false), 'extent' => route('maps.extent', absolute: false),
                    'search' => route('maps.search', absolute: false), 'options' => '/api/v1/map/options',
                ],
                'scope' => ['mode' => $scope->kind, 'label' => match ($scope->kind) {
                    'national' => 'Consulta nacional', 'department' => 'Consulta departamental', default => 'Organización seleccionada',
                }],
                'base_layers' => config('maps.base_layers'), 'legend' => $legend,
                'risk_legend' => array_map(fn ($priority) => ['label' => $priority->label(), 'color' => $priority->color()], \App\Enums\Risk\RiskPriority::cases()),
                'heat_gradient' => config('maps.heat_gradient'),
                'default_view' => config('maps.default_view'),
                'limits' => ['max_points' => (int) config('maps.max_points'), 'debounce_ms' => (int) config('maps.debounce_ms'),
                    'cluster_zoom_threshold' => (int) config('maps.cluster_zoom_threshold')],
                'tree_statuses' => $enumOptions(TreeStatus::cases()),
                'maintenance_statuses' => $enumOptions(MaintenanceStatus::cases()),
                'maintenance_priorities' => $enumOptions(MaintenancePriority::cases()),
                'can_inspect' => $canInspect,
                'can_filter_maintenance' => $this->access->allows($actor, $scope, 'maintenances.view'),
            ]];
    }

    public function points(User $actor, array $input): array
    {
        return $this->features($actor, $input, false);
    }

    public function clusters(User $actor, array $input): array
    {
        return $this->features($actor, $input, true);
    }

    public function heat(User $actor, array $input): array
    {
        ['scope' => $scope, 'filters' => $filters] = $this->filters->context($actor, $input, true);
        $summary = $this->trees->summary($scope, $filters);
        $cells = $this->trees->cells($scope, $filters);
        $features = $cells->map(fn ($cell) => (new MapCellResource($cell, true))->resolve())->all();

        return $this->collection($features, $summary + ['in_view' => (int) $cells->sum('count'),
            'returned' => count($features), 'truncated' => false, 'aggregation' => 'density_grid']);
    }

    public function extent(User $actor, array $input): array
    {
        ['scope' => $scope, 'filters' => $filters] = $this->filters->context($actor, $input);
        $summary = $this->trees->summary($scope, $filters);

        return $summary + ['bounds' => $this->trees->extent($scope, $filters)
                ?? $this->options->territoryExtent($scope, $filters->trees->values()),
            'territories' => $this->options->territories($scope, $filters->trees->values())];
    }

    public function search(User $actor, array $input): array
    {
        $lookup = $this->lookupInput($input, true);
        ['scope' => $scope, 'filters' => $filters] = $this->filters->context($actor, $input);
        $records = $this->trees->search($scope, $filters, $lookup['term'], (int) ($lookup['page'] ?? 1));
        $items = $records->take(20)->map(function ($tree) use ($scope): array {
            $feature = (new MapTreeResource($tree, false, $scope->kind))->resolve();

            return ['id' => $tree->id, 'text' => $tree->code.' · '.($feature['properties']['scientific_name'] ?? 'Sin especie'),
                'geometry' => $feature['geometry'], 'open_url' => $feature['properties']['open_url']];
        })->all();

        return ['results' => $items, 'pagination' => ['more' => $records->count() > 20]];
    }

    public function options(User $actor, string $kind, array $input): array
    {
        $lookup = $this->lookupInput($input);
        ['scope' => $scope, 'filters' => $filters] = $this->filters->context($actor, $input);
        if (in_array($kind, ['maturity_states', 'physical_conditions', 'phytosanitary_conditions'], true)) {
            $this->access->authorize($actor, $scope, 'inspections.view');
        } elseif ($kind === 'maintenance_types') {
            $this->access->authorize($actor, $scope, 'maintenances.view');
        }

        return $this->options->options($kind, $scope, $filters->trees->values(), $lookup['term'] ?? '', (int) ($lookup['page'] ?? 1));
    }

    private function features(User $actor, array $input, bool $cluster): array
    {
        ['scope' => $scope, 'filters' => $filters] = $this->filters->context($actor, $input, true);
        $summary = $this->trees->summary($scope, $filters);
        $inView = $this->trees->countInView($scope, $filters);
        $limit = (int) config('maps.max_points');
        $aggregate = $cluster && ($filters->zoom < (int) config('maps.cluster_zoom_threshold') || $inView > $limit);
        if ($aggregate) {
            $features = $this->trees->cells($scope, $filters)->map(fn ($cell) => (new MapCellResource($cell))->resolve())->all();
        } else {
            $includeInspection = $this->access->allows($actor, $scope, 'inspections.view');
            $features = $this->trees->points($scope, $filters, $limit)
                ->map(fn ($tree) => (new MapTreeResource($tree, $includeInspection, $scope->kind))->resolve())->all();
        }

        return $this->collection($features, $summary + ['in_view' => $inView, 'returned' => count($features),
            'truncated' => !$aggregate && $inView > $limit, 'aggregation' => $aggregate ? 'grid' : 'none']);
    }

    private function lookupInput(array $input, bool $required = false): array
    {
        return Validator::make($input, ['term' => [$required ? 'required' : 'nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000']], [
            'required' => 'Escriba un término de búsqueda.', 'max' => 'La búsqueda supera el límite permitido.',
            'string' => 'La búsqueda debe ser texto.', 'integer' => 'La página debe ser un entero.',
            'min' => 'La página debe ser positiva.',
        ])->validate();
    }

    private function collection(array $features, array $meta): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => $meta];
    }
}

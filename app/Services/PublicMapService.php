<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PublicTreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Enums\MaintenanceStatus;
use App\Enums\TreeStatus;
use App\Http\Resources\MapCellResource;
use App\Http\Resources\PublicTreeMapResource;
use App\Repositories\MapOptionRepository;
use App\Repositories\MapTreeRepository;
use App\Repositories\PublicReferenceRepository;

/** Lectura anónima del mismo motor PostGIS: sin actor, permisos institucionales ni TenantContext. */
class PublicMapService
{
    public function __construct(
        private PublicTreeFilterService $filters,
        private MapTreeRepository $trees,
        private MapOptionRepository $territories,
        private PublicReferenceRepository $references,
    ) {
    }

    public function page(array $input): array
    {
        return $this->pageFor($this->filters->validate($input));
    }

    public function pageFor(PublicTreeFilterDTO $filters): array
    {
        return ['initialFilters' => $filters->publicValues + ['render_mode' => 'clusters'],
            'filters' => $filters->publicValues, 'selectedOptions' => $this->references->selected($filters),
            'mapConfig' => $this->configuration()];
    }

    public function configuration(): array
    {
        $enumOptions = fn (array $cases) => array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);

        return [
            'endpoints' => [
                'trees' => route('public.map.trees', absolute: false), 'clusters' => route('public.map.clusters', absolute: false),
                'heat' => route('public.map.heat', absolute: false), 'extent' => route('public.map.extent', absolute: false),
                'search' => route('public.map.search', absolute: false), 'options' => '/api/public/v1/options',
            ],
            'access' => 'public', 'credentials' => 'omit', 'filter_keys' => $this->filters->publicFilterKeys(),
            'base_layers' => config('maps.base_layers'),
            'legend' => array_map(fn (TreeStatus $status) => ['status' => $status->value,
                'label' => $status->label(), 'color' => config('maps.colors.'.$status->value)], TreeStatus::cases()),
            'heat_gradient' => config('maps.heat_gradient'), 'default_view' => config('maps.default_view'),
            'limits' => ['max_points' => (int) config('maps.max_points'), 'debounce_ms' => (int) config('maps.debounce_ms'),
                'cluster_zoom_threshold' => (int) config('maps.cluster_zoom_threshold')],
            'tree_statuses' => $enumOptions(TreeStatus::cases()),
            'maintenance_statuses' => $enumOptions([MaintenanceStatus::Executed, MaintenanceStatus::Verified]),
            'can_inspect' => true, 'can_filter_maintenance' => true,
        ];
    }

    public function points(array $input): array
    {
        return $this->features($input, false);
    }

    public function clusters(array $input): array
    {
        return $this->features($input, true);
    }

    public function heat(array $input): array
    {
        $filters = $this->filters->validate($input, true);
        $scope = TreeReadScope::published();
        $cells = $this->trees->cells($scope, $filters->map);
        $features = $cells->map(fn ($cell) => (new MapCellResource($cell, true))->resolve())->all();

        return $this->collection($features, $this->trees->summary($scope, $filters->map) + [
            'in_view' => (int) $cells->sum('count'), 'returned' => count($features),
            'truncated' => false, 'aggregation' => 'density_grid',
        ]);
    }

    public function extent(array $input): array
    {
        $filters = $this->filters->validate($input);
        $scope = TreeReadScope::published();

        return $this->trees->summary($scope, $filters->map) + [
            'bounds' => $this->trees->extent($scope, $filters->map)
                ?? $this->territories->territoryExtent($scope, $filters->trees->values()),
            'territories' => $this->territories->territories($scope, $filters->trees->values()),
        ];
    }

    public function search(array $input): array
    {
        $filters = $this->filters->validate($input);
        $term = trim((string) ($input['term'] ?? ''));
        if ($term === '') {
            return ['results' => [], 'pagination' => ['more' => false]];
        }
        $records = $this->trees->search(TreeReadScope::published(), $filters->map, $term, (int) ($input['page'] ?? 1));
        $results = $records->take(20)->map(function ($tree): array {
            $feature = (new PublicTreeMapResource($tree))->resolve();

            return ['id' => $feature['id'], 'text' => $tree->code.' · '.($feature['properties']['scientific_name'] ?? 'Sin especie'),
                'geometry' => $feature['geometry'], 'open_url' => $feature['properties']['open_url']];
        })->all();

        return ['results' => $results, 'pagination' => ['more' => $records->count() > 20]];
    }

    public function options(string $kind, array $input): array
    {
        $filters = $this->filters->validate($input);

        return $this->references->options($kind, $filters, (string) ($input['term'] ?? ''), (int) ($input['page'] ?? 1));
    }

    private function features(array $input, bool $cluster): array
    {
        $filters = $this->filters->validate($input, true);
        $scope = TreeReadScope::published();
        $summary = $this->trees->summary($scope, $filters->map);
        $inView = $this->trees->countInView($scope, $filters->map);
        $limit = (int) config('maps.max_points');
        $aggregate = $cluster && ($filters->map->zoom < (int) config('maps.cluster_zoom_threshold') || $inView > $limit);
        $features = $aggregate
            ? $this->trees->cells($scope, $filters->map)->map(fn ($cell) => (new MapCellResource($cell))->resolve())->all()
            : $this->trees->points($scope, $filters->map, $limit)->map(fn ($tree) => (new PublicTreeMapResource($tree))->resolve())->all();

        return $this->collection($features, $summary + ['in_view' => $inView, 'returned' => count($features),
            'truncated' => !$aggregate && $inView > $limit, 'aggregation' => $aggregate ? 'grid' : 'none']);
    }

    private function collection(array $features, array $meta): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => $meta];
    }
}

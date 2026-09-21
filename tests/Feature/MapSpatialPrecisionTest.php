<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tree;
use App\Services\CoordinateParserService;
use App\Services\MapReadService;
use App\Services\TreeService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MapTestCase;

/** Compara consultas con puntos guardados por el servicio real, sin redondear el fixture con PDO. */
class MapSpatialPrecisionTest extends MapTestCase
{
    #[DataProvider('preciseEdges')]
    public function test_bbox_keeps_a_precisely_stored_point_on_each_inclusive_edge(string $raw, array $bounds): void
    {
        $tree = $this->precisePoint($raw);
        $response = app(MapReadService::class)->points($this->admin, $this->bbox($bounds));

        $this->assertSame([$tree->id], array_column($response['features'], 'id'));
        $this->assertSame(1, $response['meta']['in_view']);
    }

    public static function preciseEdges(): array
    {
        return [
            'north' => ['4.255333333333333, -73.57197222222223', ['north' => '4.255333333333333']],
            'west' => ['4.255333333333333, -73.57197222222223', ['west' => '-73.57197222222223']],
            'south' => ['4.255333333333366, -73.57197222222223', ['south' => '4.255333333333366']],
            'east' => ['4.255333333333333, -73.57197222222277', ['east' => '-73.57197222222277']],
        ];
    }

    #[DataProvider('preciseCoordinates')]
    public function test_zero_meter_radius_matches_the_exact_stored_center(string $raw): void
    {
        $tree = $this->precisePoint($raw);
        $this->precisePoint('4.2554, -73.5721');
        $coordinates = app(CoordinateParserService::class)->parse($raw);
        $response = app(MapReadService::class)->points($this->admin, $this->bbox([
            'radius_latitude' => json_encode($coordinates->latitude, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'radius_longitude' => json_encode($coordinates->longitude, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'radius_meters' => '0',
        ]));

        $this->assertSame([$tree->id], array_column($response['features'], 'id'));
        $this->assertSame(1, $response['meta']['total']);
        $this->assertSame(1, $response['meta']['in_view']);
    }

    public static function preciseCoordinates(): array
    {
        return [
            'decimal' => ['4.255333333333333, -73.57197222222223'],
            'dms' => ['4°15\'19.2" N, 73°34\'19.1" W'],
        ];
    }

    public function test_heat_and_clusters_do_not_split_a_cell_by_rounding_its_origin(): void
    {
        $this->precisePoint('4.255333333333333, -73.57197222222223');
        $this->precisePoint('4.256333333333333, -73.57097222222223');
        $input = ['west' => '-73.57197222222223', 'south' => '4.255333333333333',
            'east' => '-72.57197222222223', 'north' => '5.255333333333333', 'zoom' => 22];
        config(['maps.max_points' => 1]);
        $service = app(MapReadService::class);
        $heat = $service->heat($this->admin, $input);
        $clusters = $service->clusters($this->admin, $input);

        $this->assertSame(2, $heat['meta']['in_view']);
        $this->assertCount(1, $heat['features']);
        $this->assertSame(2, $heat['features'][0]['properties']['weight']);
        $this->assertSame('grid', $clusters['meta']['aggregation']);
        $this->assertCount(1, $clusters['features']);
        $this->assertSame(2, $clusters['features'][0]['properties']['count']);
    }

    public function test_grid_step_does_not_depend_on_php_display_precision(): void
    {
        $step = 1.23456789 / 64;
        foreach ([$step - 0.000001, $step + 0.000001] as $longitude) {
            $this->precisePoint('0.01, '.json_encode($longitude, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        }

        // Una configuración de presentación abreviada no debe alterar los parámetros espaciales.
        $previous = ini_get('precision');
        ini_set('precision', '3');
        try {
            $result = app(MapReadService::class)->heat($this->admin, [
                'west' => '0', 'south' => '0', 'east' => '1.23456789', 'north' => '1', 'zoom' => 22,
            ]);
        } finally {
            ini_set('precision', $previous);
        }

        $this->assertSame(2, $result['meta']['in_view']);
        $this->assertCount(2, $result['features']);
        $this->assertSame([1, 1], array_column(array_column($result['features'], 'properties'), 'weight'));
    }

    private function precisePoint(string $raw): Tree
    {
        return app(TreeService::class)->create($this->admin, [
            'inventory_ids' => [$this->inventory->id], 'current_status' => 'active',
            'coordinate_raw' => $raw, 'datum' => 'WGS84',
        ]);
    }
}

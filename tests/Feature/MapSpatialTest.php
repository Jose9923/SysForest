<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DeletionReason;
use App\Models\Species;
use App\Services\MapReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MapTestCase;

class MapSpatialTest extends MapTestCase
{
    public function test_geojson_uses_postgis_points_inclusive_edges_and_complete_counts(): void
    {
        $inside = $this->point(-73.5, 4);
        $edge = $this->point(-73, 5);
        $this->point(-72, 4);
        $response = app(MapReadService::class)->points($this->admin, $this->bbox());

        $this->assertSame('FeatureCollection', $response['type']);
        $this->assertEqualsCanonicalizing([$inside->id, $edge->id], array_column($response['features'], 'id'));
        $this->assertSame(['total' => 4, 'georeferenced' => 3, 'without_geometry' => 1, 'in_view' => 2,
            'returned' => 2, 'truncated' => false, 'aggregation' => 'none'], $response['meta']);
        foreach ($response['features'] as $feature) {
            $this->assertSame('Point', $feature['geometry']['type']);
            $this->assertCount(2, $feature['geometry']['coordinates']);
        }
    }

    public function test_extent_ignores_viewport_and_uses_actual_filtered_points(): void
    {
        $this->point(-73.5, 4);
        $this->point(-72, 6);
        $result = app(MapReadService::class)->extent($this->admin, $this->bbox());

        $this->assertSame([[4.0, -73.5], [6.0, -72.0]], $result['bounds']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['without_geometry']);
        $this->assertSame([], $result['territories']['features']);
    }

    public function test_empty_geometry_has_no_synthetic_point_or_extent(): void
    {
        $result = app(MapReadService::class)->extent($this->admin, []);
        $this->assertNull($result['bounds']);
        $this->assertSame(1, $result['without_geometry']);
        $this->assertSame([], app(MapReadService::class)->points($this->admin, $this->bbox())['features']);
    }

    public function test_radius_uses_geography_meters_and_includes_its_center(): void
    {
        $inside = $this->point(-73.5, 4);
        $this->point(-73.51, 4);
        $input = $this->bbox(['radius_latitude' => 4, 'radius_longitude' => -73.5, 'radius_meters' => 100]);
        $response = app(MapReadService::class)->points($this->admin, $input);

        $this->assertSame([$inside->id], array_column($response['features'], 'id'));
        $this->assertSame(1, $response['meta']['total']);
    }

    #[DataProvider('invalidSpatialInput')]
    public function test_spatial_input_is_validated_before_sql(array $changes): void
    {
        $this->expectException(ValidationException::class);
        app(MapReadService::class)->points($this->admin, $this->bbox($changes));
    }

    public static function invalidSpatialInput(): array
    {
        return [
            [['west' => -181]], [['east' => 181]], [['north' => 91]], [['south' => -91]],
            [['west' => -70]], [['south' => 5]], [['north' => 3]], [['west' => null]],
            [['west' => '0); DROP TABLE trees; --']], [['east' => ['a']]], [['south' => 'NaN']], [['north' => 'INF']],
            [['zoom' => -1]], [['zoom' => 23]], [['zoom' => 1.5]], [['radius_meters' => 10]],
            [['radius_latitude' => 4, 'radius_longitude' => -73.5, 'radius_meters' => -1]],
            [['trashed' => 'only']], [['include_deleted' => 1]], [['include_voided_trees' => 1]],
        ];
    }

    public function test_clusters_count_all_records_without_duplicate_individual_properties(): void
    {
        for ($index = 0; $index < 4; $index++) {
            $this->point(-73.5 + $index * 0.00001, 4);
        }
        $result = app(MapReadService::class)->clusters($this->admin, $this->bbox(['zoom' => 5]));

        $this->assertSame('grid', $result['meta']['aggregation']);
        $this->assertSame(4, array_sum(array_column(array_column($result['features'], 'properties'), 'count')));
        $this->assertCount(1, $result['features']);
        $this->assertArrayNotHasKey('code', $result['features'][0]['properties']);
        $this->assertSame([[-73.5, 4.0], [-73.49997, 4.0]], [
            array_reverse($result['features'][0]['properties']['bounds'][0]),
            array_reverse($result['features'][0]['properties']['bounds'][1]),
        ]);
    }

    public function test_high_zoom_clusters_can_expose_authorized_individuals(): void
    {
        $tree = $this->point(-73.5, 4);
        $result = app(MapReadService::class)->clusters($this->admin, $this->bbox());

        $this->assertSame('none', $result['meta']['aggregation']);
        $this->assertSame($tree->id, $result['features'][0]['id']);
    }

    public function test_point_limit_is_explicit_and_never_replaces_the_total(): void
    {
        config(['maps.max_points' => 2]);
        for ($index = 0; $index < 3; $index++) {
            $this->point(-73.5, 4);
        }
        $result = app(MapReadService::class)->points($this->admin, $this->bbox());

        $this->assertCount(2, $result['features']);
        $this->assertSame(3, $result['meta']['in_view']);
        $this->assertSame(4, $result['meta']['total']);
        $this->assertTrue($result['meta']['truncated']);
        $clusters = app(MapReadService::class)->clusters($this->admin, $this->bbox());
        $this->assertFalse($clusters['meta']['truncated']);
        $this->assertSame('grid', $clusters['meta']['aggregation']);
    }

    public function test_heat_weights_cover_exactly_filtered_points_with_no_private_attributes(): void
    {
        $this->point(-73.5, 4, ['current_status' => 'dead']);
        $this->point(-73.5, 4, ['current_status' => 'dead']);
        $this->point(-73.4, 4);
        $result = app(MapReadService::class)->heat($this->admin, $this->bbox(['current_status' => 'dead']));

        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(2, array_sum(array_column(array_column($result['features'], 'properties'), 'weight')));
        $this->assertSame(['weight' => 2], $result['features'][0]['properties']);
        $this->assertSame([-73.5, 4.0], $result['features'][0]['geometry']['coordinates']);
        $this->assertArrayNotHasKey('id', $result['features'][0]);
    }

    public function test_soft_delete_and_real_status_are_independent(): void
    {
        $dead = $this->point(-73.5, 4, ['current_status' => 'dead']);
        $void = $this->point(-73.6, 4, ['deleted_at' => now(), 'deleted_by' => $this->admin->id,
            'deletion_reason_id' => DeletionReason::query()->firstOrFail()->id]);
        $result = app(MapReadService::class)->points($this->admin, $this->bbox(['current_status' => 'dead']));

        $this->assertSame([$dead->id], array_column($result['features'], 'id'));
        $all = app(MapReadService::class)->points($this->admin, $this->bbox());
        $this->assertNotContains($void->id, array_column($all['features'], 'id'));
    }

    public function test_search_respects_filters_and_escapes_wildcards(): void
    {
        $this->tree->forceFill(['species_id' => null])->save();
        $species = Species::query()->firstOrFail();
        $species->update(['scientific_name' => 'Especie 100% literal']);
        $other = Species::query()->whereKeyNot($species->id)->firstOrFail();
        $other->update(['scientific_name' => 'Especie 1000']);
        $tree = $this->point(-73.5, 4, ['species_id' => $species->id]);
        $this->point(-73.6, 4, ['species_id' => $other->id]);
        $result = app(MapReadService::class)->search($this->admin, ['term' => '100%']);

        $this->assertSame([$tree->id], array_column($result['results'], 'id'));
        $this->assertSame([], app(MapReadService::class)->search($this->admin, ['term' => '100%', 'current_status' => 'dead'])['results']);
    }

    public function test_search_returns_unlocated_tree_without_synthetic_coordinates(): void
    {
        $result = app(MapReadService::class)->search($this->admin, ['term' => $this->tree->code]);

        $this->assertSame([$this->tree->id], array_column($result['results'], 'id'));
        $this->assertNull($result['results'][0]['geometry']);
        $this->assertSame(route('maps.open', ['tree' => $this->tree->id, 'scope' => 'organization'], false), $result['results'][0]['open_url']);
        $this->assertSame([], app(MapReadService::class)->points($this->admin, $this->bbox())['features']);
    }

    public function test_foreign_organization_filters_do_not_expand_the_scope(): void
    {
        $foreign = $this->anotherOrganization($this->organization);
        $this->point(-73.5, 4, [], $foreign);
        $this->actingTenant($this->admin, $this->organization);
        $this->getJson('/api/v1/map/trees?'.http_build_query($this->bbox(['organization_id' => $foreign->id])))
            ->assertNotFound();
    }

    public function test_resources_do_not_export_internal_geometry_or_notes(): void
    {
        $species = Species::query()->firstOrFail();
        $tree = $this->point(-73.5, 4, ['species_id' => $species->id, 'location_description' => 'PRIVATE-NOTE',
            'gps_model' => 'PRIVATE-GPS']);
        $this->capture(['general_observations' => 'PRIVATE-INSPECTION'], tree: $tree);
        $result = app(MapReadService::class)->points($this->admin, $this->bbox());
        $json = json_encode($result);

        foreach (['PRIVATE-NOTE', 'PRIVATE-GPS', 'PRIVATE-INSPECTION', 'created_by', 'updated_by', 'organization_id', 'coordinate_raw', 'storage_path'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(['date' => '2026-09-12'], $result['features'][0]['properties']['latest_inspection']);
    }

    public function test_large_territory_is_reported_as_omitted_without_losing_its_real_extent(): void
    {
        $department = $this->organization->municipality->department_id;
        DB::update('UPDATE departments SET geometry = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(?, ?), 4326), 1, 6000)) WHERE id = ?', [-73.5, 4, $department]);
        $result = app(MapReadService::class)->extent($this->admin, ['department_id' => $department]);

        $this->assertSame([[3.0, -74.5], [5.0, -72.5]], $result['bounds']);
        $this->assertSame([], $result['territories']['features']);
        $this->assertSame(['omitted_complex' => 1, 'omitted_invalid' => 0, 'truncated' => false], $result['territories']['meta']);
    }

    public function test_selected_real_territorial_geometry_is_returned_without_inventing_other_polygons(): void
    {
        $department = $this->organization->municipality->department_id;
        DB::update('UPDATE departments SET geometry = ST_Multi(ST_MakeEnvelope(?, ?, ?, ?, 4326)) WHERE id = ?', [-74, 3, -73, 5, $department]);
        $result = app(MapReadService::class)->extent($this->admin, ['department_id' => $department]);

        $this->assertSame([[3.0, -74.0], [5.0, -73.0]], $result['bounds']);
        $this->assertCount(1, $result['territories']['features']);
        $this->assertSame('MultiPolygon', $result['territories']['features'][0]['geometry']['type']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DeletionReason;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeLocationHistory;
use App\Models\User;
use App\Repositories\TreeLocationRepository;
use App\Services\TreeLocationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\PostgresTestCase;

class TreeSpatialTest extends PostgresTestCase
{
    private User $actor;
    private Organization $organization;
    private TreeLocationRepository $locations;
    private TreeLocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->organization = $this->pilot();
        $this->locations = app(TreeLocationRepository::class);
        $this->service = app(TreeLocationService::class);
    }

    public function test_decimal_point_has_longitude_x_latitude_y_srid_4326_and_initial_history(): void
    {
        $tree = $this->point(4.154, -73.633);
        $point = $this->pointValues($tree);
        $history = TreeLocationHistory::query()->where('tree_id', $tree->id)->sole();

        $this->assertSame(4326, $point->srid);
        $this->assertSame('POINT', $point->geometry_type);
        $this->assertEqualsWithDelta(-73.633, (float) $point->longitude, 0.000000001);
        $this->assertEqualsWithDelta(4.154, (float) $point->latitude, 0.000000001);
        $this->assertNull($tree->fresh()->datum);
        $this->assertNull($history->old_geometry);
        $this->assertNull($history->old_coordinate_raw);
        $this->assertNull($history->change_type);
        $this->assertSame($this->organization->id, $history->organization_id);
        $this->assertSame($this->actor->id, $history->changed_by);
        $this->assertSame('decimal', $history->new_metadata['coordinate_format']);
        $this->assertDatabaseHas('security_events', ['event' => 'tree.location_changed', 'actor_user_id' => $this->actor->id, 'organization_id' => $tree->organization_id]);
        $event = DB::table('security_events')->where('event', 'tree.location_changed')->where('metadata->resource_id', $tree->id)->sole();
        $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
        $expected = ['resource_id' => $tree->id, 'resource_code' => $tree->code,
            'revision_id' => $history->id, 'reason' => null, 'change_type' => null];
        ksort($metadata);
        ksort($expected);
        $this->assertSame($expected, $metadata);
        $this->assertSame('tree', $event->resource_type);
        $this->assertSame($tree->id, $event->resource_id);
    }

    public function test_dms_source_and_explicit_datum_and_capture_metadata_are_preserved(): void
    {
        $tree = $this->tree();
        $raw = '4°15\'19.2" N, 73°34\'19.1" W';
        $this->assertTrue($this->service->apply($tree, $this->actor, [
            'coordinate_raw' => $raw, 'datum' => 'WGS 84', 'accuracy_m' => '3.250',
            'gps_brand' => 'Garmin', 'gps_model' => 'Equipo declarado', 'captured_at' => '2026-09-12T14:30:00-05:00',
        ], true));
        $fresh = $tree->fresh();
        $point = $this->pointValues($tree);
        $this->assertSame($raw, $fresh->coordinate_raw);
        $this->assertSame('dms', $fresh->coordinate_format);
        $this->assertSame('WGS84', $fresh->datum);
        $this->assertSame('3.250', $fresh->accuracy_m);
        $this->assertSame('Garmin', $fresh->gps_brand);
        $this->assertSame('Equipo declarado', $fresh->gps_model);
        $this->assertSame('2026-09-12T19:30:00+00:00', $fresh->captured_at->utc()->toIso8601String());
        $this->assertEqualsWithDelta(-73.57197222222223, (float) $point->longitude, 0.000000001);
        $this->assertEqualsWithDelta(4.255333333333333, (float) $point->latitude, 0.000000001);
    }

    public function test_repeating_dms_and_microsecond_capture_is_a_no_op_after_postgis_round_trip(): void
    {
        $tree = $this->tree();
        $raw = '4°15\'19.2" N, 73°34\'19.1" W';
        $values = ['coordinate_raw' => $raw, 'captured_at' => '2026-09-12T14:30:00.123456-05:00'];
        $this->assertTrue($this->service->apply($tree, $this->actor, $values, true));
        $this->assertSame('123456', $tree->fresh()->captured_at->format('u'));
        $this->assertFalse($this->service->apply($tree, $this->actor, $values));
        $this->assertFalse($this->service->apply($tree, $this->actor, ['captured_at' => '2026-09-12T19:30:00.123456Z']));
        $this->assertSame(1, $this->historyCount($tree));
    }

    public function test_unknown_datum_remains_null_and_unsupported_transformations_are_rejected(): void
    {
        $tree = $this->tree();
        $this->service->apply($tree, $this->actor, ['coordinate_raw' => '4.154, -73.633', 'datum' => 'desconocido'], true);
        $this->assertNull($tree->fresh()->datum);
        try {
            $this->service->apply($tree, $this->actor, $this->change(['datum' => 'MAGNA-SIRGAS']));
            $this->fail('Se inventó una transformación de datum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('datum', $exception->errors());
            $this->assertNull($tree->fresh()->datum);
            $this->assertSame(1, $this->historyCount($tree));
        }
    }

    public function test_bbox_is_inclusive_and_scoped_to_organization(): void
    {
        $inside = $this->point(4.15, -73.63);
        $edge = $this->point(4.16, -73.62);
        $this->point(4.17, -73.61);
        $other = $this->anotherOrganization($this->organization);
        $this->point(4.15, -73.63, $other);
        $this->tree();
        $result = $this->locations->withinBoundingBox($this->organization->id, -73.64, 4.14, -73.62, 4.16);

        $this->assertEqualsCanonicalizing([$inside->id, $edge->id], $result->modelKeys());
        $this->assertSame([$this->organization->id], $result->pluck('organization_id')->unique()->values()->all());
    }

    public function test_radius_is_measured_in_meters_and_returns_distance_from_postgis(): void
    {
        $origin = $this->point(0, 0);
        $near = $this->point(0, 0.001);
        $this->point(0, 0.01);
        $this->point(0, 0, $this->anotherOrganization($this->organization));
        $result = $this->locations->withinRadius($this->organization->id, 0, 0, 150);

        $this->assertSame([$origin->id, $near->id], $result->modelKeys());
        $this->assertEqualsWithDelta(0, (float) $result[0]->distance_m, 0.001);
        $this->assertEqualsWithDelta(111.31949, (float) $result[1]->distance_m, 0.05);
        $this->assertCount(1, $this->locations->withinRadius($this->organization->id, 0, 0, 0));
    }

    public function test_nearest_is_limited_ordered_and_excludes_unlocated_and_foreign_trees(): void
    {
        $first = $this->point(0, 0.001);
        $second = $this->point(0, 0.002);
        $this->point(0, 0.003);
        $this->tree();
        $this->point(0, 0, $this->anotherOrganization($this->organization));
        $result = $this->locations->nearest($this->organization->id, 0, 0, 2);

        $this->assertSame([$first->id, $second->id], $result->modelKeys());
        $this->assertLessThan((float) $result[1]->distance_m, (float) $result[0]->distance_m);
    }

    public function test_all_spatial_queries_filter_inventory_with_its_organization(): void
    {
        $included = $this->point(0, 0.001);
        $this->point(0, 0.002);
        $inventory = $this->inventory($this->organization, $included);
        $otherOrganization = $this->anotherOrganization($this->organization);
        $foreign = $this->point(0, 0.001, $otherOrganization);
        $foreignInventory = $this->inventory($otherOrganization, $foreign);

        $this->assertSame([$included->id], $this->locations->withinBoundingBox($this->organization->id, -1, -1, 1, 1, $inventory->id)->modelKeys());
        $this->assertSame([$included->id], $this->locations->withinRadius($this->organization->id, 0, 0, 1000, $inventory->id)->modelKeys());
        $this->assertSame([$included->id], $this->locations->nearest($this->organization->id, 0, 0, 10, $inventory->id)->modelKeys());
        $this->assertEmpty($this->locations->withinBoundingBox($this->organization->id, -1, -1, 1, 1, $foreignInventory->id));
        $this->assertEmpty($this->locations->withinRadius($this->organization->id, 0, 0, 1000, $foreignInventory->id));
        $this->assertEmpty($this->locations->nearest($this->organization->id, 0, 0, 10, $foreignInventory->id));
    }

    public function test_annulled_tree_is_excluded_from_all_spatial_queries(): void
    {
        $tree = $this->point(0, 0);
        $reason = DeletionReason::query()->create(['code' => 'DEL_SPATIAL_TEST', 'name' => 'Error del registro']);
        $tree->forceFill(['deleted_by' => $this->actor->id, 'deletion_reason_id' => $reason->id, 'deleted_at' => now()])->save();

        $this->assertEmpty($this->locations->withinBoundingBox($this->organization->id, -1, -1, 1, 1));
        $this->assertEmpty($this->locations->withinRadius($this->organization->id, 0, 0, 1000));
        $this->assertEmpty($this->locations->nearest($this->organization->id, 0, 0));
    }

    public function test_location_change_preserves_old_and_new_points_and_source_metadata(): void
    {
        $tree = $this->point(4.154, -73.633);
        $this->assertTrue($this->service->apply($tree, $this->actor, $this->change([
            'coordinate_raw' => '4.155, -73.632', 'accuracy_m' => '2.5', 'gps_brand' => 'Garmin',
            'location_change_type' => 'relocation',
        ])));
        $history = TreeLocationHistory::query()->where('tree_id', $tree->id)->where('change_type', 'relocation')->sole();
        $geometries = DB::selectOne('SELECT ST_X(old_geometry) AS old_x, ST_Y(old_geometry) AS old_y, ST_X(new_geometry) AS new_x, ST_Y(new_geometry) AS new_y FROM tree_location_histories WHERE id = ?', [$history->id]);

        $this->assertEqualsWithDelta(-73.633, (float) $geometries->old_x, 0.000000001);
        $this->assertEqualsWithDelta(4.154, (float) $geometries->old_y, 0.000000001);
        $this->assertEqualsWithDelta(-73.632, (float) $geometries->new_x, 0.000000001);
        $this->assertEqualsWithDelta(4.155, (float) $geometries->new_y, 0.000000001);
        $this->assertSame('4.154, -73.633', $history->old_coordinate_raw);
        $this->assertSame('4.155, -73.632', $history->new_coordinate_raw);
        $this->assertNull($history->old_metadata['accuracy_m']);
        $this->assertSame('2.500', $history->new_metadata['accuracy_m']);
        $this->assertSame('Garmin', $history->new_metadata['gps_brand']);
        $this->assertSame('Revisión de campo', $history->reason);
        $this->assertSame(1, $tree->fresh()->lock_version);
    }

    public function test_metadata_change_is_historized_but_normalized_no_op_does_not_add_history(): void
    {
        $tree = $this->point(4.154, -73.633);
        $this->assertTrue($this->service->apply($tree, $this->actor, $this->change(['datum' => 'WGS84', 'accuracy_m' => '3.5'])));
        $this->assertFalse($this->service->apply($tree, $this->actor, ['datum' => 'WGS 84', 'accuracy_m' => '3.500']));
        $this->assertFalse($this->service->apply($tree, $this->actor, []));
        $this->assertSame(2, $this->historyCount($tree));
        $this->assertSame(2, DB::table('security_events')->where('event', 'tree.location_changed')->where('metadata->resource_id', $tree->id)->count());
        $history = TreeLocationHistory::query()->where('tree_id', $tree->id)->where('change_type', 'correction')->sole();
        $sameGeometry = DB::selectOne('SELECT ST_Equals(old_geometry, new_geometry) AS equal FROM tree_location_histories WHERE id = ?', [$history->id]);
        $this->assertTrue($sameGeometry->equal);
        $this->assertNull($history->old_metadata['datum']);
        $this->assertSame('WGS84', $history->new_metadata['datum']);
    }

    public function test_explicit_clear_keeps_previous_location_in_history_and_omitted_metadata(): void
    {
        $tree = $this->tree();
        $this->service->apply($tree, $this->actor, ['coordinate_raw' => '4.154, -73.633', 'gps_brand' => 'Garmin'], true);
        $this->assertTrue($this->service->apply($tree, $this->actor, $this->change(['coordinate_raw' => null])));
        $fresh = $tree->fresh();
        $history = TreeLocationHistory::query()->where('tree_id', $tree->id)->where('change_type', 'correction')->sole();

        $this->assertNull($fresh->geometry);
        $this->assertNull($fresh->coordinate_raw);
        $this->assertNull($fresh->coordinate_format);
        $this->assertSame('Garmin', $fresh->gps_brand);
        $this->assertNotNull($history->old_geometry);
        $this->assertNull($history->new_geometry);
        $this->assertSame('4.154, -73.633', $history->old_coordinate_raw);
        $this->assertNull($history->new_coordinate_raw);
    }

    public function test_metadata_can_be_declared_without_inventing_a_point_or_datum(): void
    {
        $tree = $this->tree();
        $this->assertFalse($this->service->apply($tree, $this->actor, [], true));
        $this->assertSame(0, $this->historyCount($tree));
        $this->assertTrue($this->service->apply($tree, $this->actor, ['gps_brand' => 'Garmin'], true));

        $this->assertNull($tree->fresh()->geometry);
        $this->assertNull($tree->fresh()->datum);
        $this->assertNull($tree->fresh()->gps_model);
        $this->assertNull($tree->fresh()->accuracy_m);
        $this->assertSame(1, $this->historyCount($tree));
    }

    public function test_invalid_changes_do_not_modify_tree_or_history(): void
    {
        $tree = $this->point(4.154, -73.633);
        foreach ([
            ['coordinate_raw' => 'NaN, -73.633'],
            ['accuracy_m' => '-1'], ['accuracy_m' => '0.1234'],
            ['captured_at' => '2026-02-30T10:00:00Z'],
            ['captured_at' => 'tomorrow'],
            ['location_change_type' => 'invented'],
        ] as $input) {
            try {
                $this->service->apply($tree, $this->actor, $this->change($input));
                $this->fail('Se aceptó metadata o coordenada inválida.');
            } catch (ValidationException) {
                $this->assertSame(1, $this->historyCount($tree));
                $this->assertSame('4.154, -73.633', $tree->fresh()->coordinate_raw);
            }
        }
    }

    public function test_edit_requires_change_type_and_reason_when_a_value_changes(): void
    {
        $tree = $this->point(4.154, -73.633);
        foreach ([[], ['location_change_type' => 'correction'], ['location_reason' => 'Corrección']] as $explanation) {
            try {
                $this->service->apply($tree, $this->actor, array_merge(['coordinate_raw' => null], $explanation));
                $this->fail('Se vació una ubicación sin tipo y motivo.');
            } catch (ValidationException) {
                $this->assertNotNull($tree->fresh()->geometry);
                $this->assertSame(1, $this->historyCount($tree));
            }
        }
    }

    public function test_wrong_organization_cannot_read_or_mutate_location_by_tree_uuid(): void
    {
        $tree = $this->point(0, 0);
        $other = $this->anotherOrganization($this->organization);
        $forged = clone $tree;
        $forged->organization_id = $other->id;
        try {
            $this->service->apply($forged, $this->actor, $this->change(['coordinate_raw' => '1, 1']));
            $this->fail('Se resolvió el UUID sin limitar la organización.');
        } catch (ModelNotFoundException) {
            $this->assertSame('0, 0', $tree->fresh()->coordinate_raw);
            $this->assertSame(1, $this->historyCount($tree));
        }
    }

    public function test_caller_rollback_reverts_current_point_and_history_together(): void
    {
        $tree = $this->point(0, 0);
        try {
            DB::transaction(function () use ($tree): void {
                $this->service->apply($tree, $this->actor, $this->change(['coordinate_raw' => '1, 1']));
                throw new RuntimeException('Fallo posterior de la operación completa.');
            });
        } catch (RuntimeException) {
            $this->assertSame(1, DB::table('security_events')->where('event', 'tree.location_changed')->where('metadata->resource_id', $tree->id)->count());
            $this->assertSame('0, 0', $tree->fresh()->coordinate_raw);
            $this->assertSame(1, $this->historyCount($tree));
        }
    }

    public function test_spatial_arguments_are_validated_before_queries(): void
    {
        foreach ([
            fn () => $this->locations->withinBoundingBox($this->organization->id, 1, -1, -1, 1),
            fn () => $this->locations->withinRadius($this->organization->id, 91, 0, 100),
            fn () => $this->locations->withinRadius($this->organization->id, 0, 0, -1),
            fn () => $this->locations->withinRadius($this->organization->id, 0, 0, INF),
            fn () => $this->locations->nearest($this->organization->id, NAN, 0),
            fn () => $this->locations->nearest($this->organization->id, 0, 0, 0),
            fn () => $this->locations->nearest($this->organization->id, 0, 0, 101),
            fn () => $this->locations->nearest($this->organization->id, 0, 0, 1, 'invalid-uuid'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Se aceptó una consulta espacial inválida.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_geometry_and_geography_gist_indexes_exist(): void
    {
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'trees')->pluck('indexdef', 'indexname');
        $this->assertStringContainsString('USING gist (geometry)', $indexes['trees_geometry_gist']);
        $this->assertStringContainsString('USING gist', $indexes['trees_geography_gist']);
        $this->assertStringContainsString('geography', $indexes['trees_geography_gist']);
    }

    private function tree(?Organization $organization = null): Tree
    {
        $tree = new Tree();
        $tree->forceFill([
            'organization_id' => ($organization ?? $this->organization)->id,
            'code' => 'SPATIAL-'.strtoupper(Str::random(12)),
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ])->save();

        return $tree;
    }

    private function point(float $latitude, float $longitude, ?Organization $organization = null): Tree
    {
        $tree = $this->tree($organization);
        $this->service->apply($tree, $this->actor, ['coordinate_raw' => $latitude.', '.$longitude], true);

        return $tree;
    }

    private function inventory(Organization $organization, Tree $tree): Inventory
    {
        $inventory = new Inventory();
        $inventory->forceFill(['organization_id' => $organization->id, 'code' => 'INV-'.strtoupper(Str::random(12)), 'name' => 'Inventario espacial', 'status' => 'active'])->save();
        $inventory->trees()->attach($tree->id, [
            'id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'included_at' => now(), 'included_by' => $this->actor->id,
        ]);

        return $inventory;
    }

    private function pointValues(Tree $tree): object
    {
        return DB::selectOne('SELECT ST_X(geometry) AS longitude, ST_Y(geometry) AS latitude, ST_SRID(geometry) AS srid, GeometryType(geometry) AS geometry_type FROM trees WHERE organization_id = ? AND id = ?', [$tree->organization_id, $tree->id]);
    }

    private function historyCount(Tree $tree): int
    {
        return TreeLocationHistory::query()->where('organization_id', $tree->organization_id)->where('tree_id', $tree->id)->count();
    }

    private function change(array $values): array
    {
        return array_replace(['location_change_type' => 'correction', 'location_reason' => 'Revisión de campo'], $values);
    }
}

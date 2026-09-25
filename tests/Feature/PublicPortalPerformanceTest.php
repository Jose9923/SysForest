<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\TreeReadScope;
use App\Models\Placement;
use App\Models\Species;
use App\Repositories\MapTreeRepository;
use App\Services\PublicMapService;
use App\Services\PublicTreeFilterService;
use App\Services\PublicTreeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PublicPortalTestCase;

/** Volumen sintético publicado exclusivamente en la transacción de sysforest_testing. */
class PublicPortalPerformanceTest extends PublicPortalTestCase
{
    public function test_public_read_query_counts_and_payloads_remain_bounded_as_the_dataset_grows(): void
    {
        config(['maps.max_points' => 2000]);
        $this->publishOrganization();
        $this->publishInventory();
        $map = app(PublicMapService::class);
        $trees = app(PublicTreeService::class);
        $measurements = [];
        $previous = 0;
        foreach ([1, 100, 1024, 2048] as $size) {
            $coordinates = [];
            for ($index = $previous; $index < $size; $index++) {
                $coordinates[] = [-73.7 + ($index % 64) * 0.001, 4.05 + intdiv($index, 64) * 0.001];
            }
            $this->insertPoints($coordinates, $previous);
            $previous = $size;
            $input = $this->bbox(['organization_id' => $this->organization->public_code]);
            [$points, $queries] = $this->measure(fn () => $map->points($input));
            $measurements[$size]['points'] = $queries;
            $this->assertCount(min(2000, $size), $points['features']);
            $this->assertSame($size, $points['meta']['total']);
            $this->assertSame($size, $points['meta']['in_view']);
            $this->assertSame($size > 2000, $points['meta']['truncated']);
            $this->assertNotEmpty($points['features'][0]['properties']['scientific_name']);
            $this->assertNotEmpty($points['features'][0]['properties']['common_name']);
            $this->assertNotEmpty($points['features'][0]['properties']['placement']);
            [$list, $queries] = $this->measure(fn () => $trees->listing($input));
            $measurements[$size]['list'] = $queries;
            $this->assertSame($size, $list['trees']->total());
            $this->assertLessThanOrEqual(20, count($list['trees']->items()));
            $this->assertSame($size, $list['indicators']['trees']);
            [$clusters, $queries] = $this->measure(fn () => $map->clusters(array_replace($input, ['zoom' => 5])));
            $measurements[$size]['clusters'] = $queries;
            $this->assertSame('grid', $clusters['meta']['aggregation']);
            $this->assertSame($size, $clusters['meta']['in_view']);
            $this->assertLessThanOrEqual(4225, count($clusters['features']));
            [$heat, $queries] = $this->measure(fn () => $map->heat($input));
            $measurements[$size]['heat'] = $queries;
            $this->assertSame($size, $heat['meta']['in_view']);
            $this->assertLessThanOrEqual(4225, count($heat['features']));
            [$search, $queries] = $this->measure(fn () => $map->search(['term' => 'PUB-PERF-']));
            $measurements[$size]['search'] = $queries;
            $this->assertCount(min(20, $size), $search['results']);
            $this->assertSame($size > 20, $search['pagination']['more']);
            [$options, $queries] = $this->measure(fn () => $map->options('inventories', ['organization_id' => $this->organization->public_code]));
            $measurements[$size]['options'] = $queries;
            $this->assertCount(1, $options['results']);
            $this->assertSame($this->organization->public_code.'~'.$this->inventory->code, $options['results'][0]['id']);
        }

        foreach ($measurements as $size => $counts) {
            foreach ($counts as $operation => $count) {
                $message = 'Consultas públicas '.$operation.' ('.$size.' árboles): '.json_encode($measurements, JSON_THROW_ON_ERROR);
                $this->assertLessThan(80, $count, $message);
                $this->assertLessThanOrEqual($measurements[1][$operation] + 5, $count, $message);
            }
        }
        $this->artifact('/tmp/sysforest-phase11-query-count.json', ['synthetic_counts' => $measurements,
            'point_limit' => 2000, 'largest_dataset' => 2048, 'database' => config('database.connections.pgsql.database')]);
    }

    public function test_public_scope_selective_bbox_uses_the_natural_gist_plan(): void
    {
        $this->publishOrganization();
        $this->publishInventory();
        $coordinates = [];
        for ($row = 0; $row < 64; $row++) {
            for ($column = 0; $column < 64; $column++) {
                $coordinates[] = [-77 + $column * 0.1, -2 + $row * 0.1];
            }
        }
        $this->insertPoints($coordinates);
        $bbox = ['west' => -73.81, 'south' => 1.19, 'east' => -73.79, 'north' => 1.21];
        $filters = app(PublicTreeFilterService::class)->validate($bbox, true);
        $query = app(MapTreeRepository::class)->query(TreeReadScope::published(), $filters->map, true)
            ->whereNotNull('trees.geometry')->select('trees.id');
        $settings = (array) DB::selectOne("SELECT current_setting('enable_seqscan') AS enable_seqscan,
            current_setting('enable_indexscan') AS enable_indexscan, current_setting('enable_bitmapscan') AS enable_bitmapscan");
        $this->assertSame('on', $settings['enable_seqscan']);
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $raw = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);
        $plan = json_decode($raw[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR);
        $indexes = array_values(array_unique($this->indexes($plan[0]['Plan'])));
        $this->artifact('/tmp/sysforest-phase11-explain.json', ['database' => config('database.connections.pgsql.database'),
            'synthetic_public_points' => 4096, 'bbox' => $bbox, 'planner_settings' => $settings,
            'sql' => $sql, 'bindings' => $bindings, 'indexes' => $indexes, 'explain_analyze_buffers' => $plan]);
        $this->assertSame(1, $plan[0]['Plan']['Actual Rows']);
        $this->assertContains(
            'trees_geometry_gist',
            $indexes,
            'El encuadre público selectivo debe utilizar el índice espacial naturalmente: '.json_encode($plan, JSON_THROW_ON_ERROR)
        );
        $this->assertArrayHasKey('Shared Hit Blocks', $plan[0]['Plan']);
    }

    /** Los lotes y sus vínculos de publicación quedan fuera del conteo de consultas del lector. */
    private function insertPoints(array $coordinates, int $offset = 0): void
    {
        $species = Species::query()->orderBy('code')->pluck('id')->all();
        $placements = Placement::query()->orderBy('code')->pluck('id')->all();
        $timestamp = now()->toDateTimeString();
        foreach (array_chunk($coordinates, 256, true) as $batch) {
            $tuples = [];
            $bindings = [];
            $participations = [];
            foreach ($batch as $index => [$longitude, $latitude]) {
                $ordinal = $index + $offset;
                $id = (string) Str::uuid();
                $tuples[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ST_SetSRID(ST_MakePoint(?, ?), 4326))';
                array_push(
                    $bindings,
                    $id,
                    $this->organization->id,
                    'PUB-PERF-'.str_pad((string) $ordinal, 5, '0', STR_PAD_LEFT),
                    $species[$ordinal % count($species)],
                    $placements[$ordinal % count($placements)],
                    $this->admin->id,
                    $this->admin->id,
                    $timestamp,
                    $timestamp,
                    $longitude,
                    $latitude
                );
                $participations[] = ['id' => (string) Str::uuid(), 'organization_id' => $this->organization->id,
                    'inventory_id' => $this->inventory->id, 'tree_id' => $id,
                    'included_at' => $timestamp, 'included_by' => $this->admin->id];
            }
            DB::insert('INSERT INTO trees (id, organization_id, code, species_id, placement_id,
                created_by, updated_by, created_at, updated_at, is_public, geometry) VALUES '.implode(', ', $tuples), $bindings);
            DB::table('inventory_trees')->insert($participations);
        }
    }

    private function measure(callable $operation): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $operation();

            return [$result, count($connection->getQueryLog())];
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function indexes(array $node): array
    {
        $indexes = isset($node['Index Name']) ? [$node['Index Name']] : [];
        foreach ($node['Plans'] ?? [] as $child) {
            array_push($indexes, ...$this->indexes($child));
        }

        return $indexes;
    }

    /** Opt-in: artefactos técnicos, sin secretos ni datos de la base operacional. */
    private function artifact(string $path, array $data): void
    {
        if ((string) env('SYSFOREST_PUBLIC_EXPLAIN') !== '1') {
            return;
        }
        $this->assertNotFalse(file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        ));
    }
}

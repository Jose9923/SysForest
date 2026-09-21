<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\MapTreeFilterDTO;
use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Models\Placement;
use App\Models\Species;
use App\Models\Tree;
use App\Repositories\MapTreeRepository;
use App\Services\MapReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MapTestCase;

/** Los lotes y sus índices se ejercitan únicamente dentro de la transacción de sysforest_testing. */
class MapPerformanceTest extends MapTestCase
{
    public function test_point_query_count_stays_bounded_from_one_to_one_hundred_complete_markers(): void
    {
        config(['maps.max_points' => 2000]);
        $species = Species::query()->where('active', true)->orderBy('code')->pluck('id')->all();
        $placements = Placement::query()->where('active', true)->orderBy('code')->pluck('id')->all();
        $this->assertGreaterThan(1, count($species));
        $this->assertNotEmpty($placements);

        $firstId = $this->insertPoints('PERF-COUNT-', [[-73.6, 4.1]], $species, $placements)[0];
        $this->capture([], tree: Tree::query()->findOrFail($firstId));
        $input = $this->bbox(['scope' => 'organization']);
        $service = app(MapReadService::class);
        [$one, $oneQueries] = $this->measurePoints($service, $input);

        $coordinates = [];
        for ($index = 1; $index < 100; $index++) {
            $coordinates[] = [-73.65 + ($index % 10) * 0.001, 4.15 + intdiv($index, 10) * 0.001];
        }
        $this->insertPoints('PERF-COUNT-', $coordinates, $species, $placements, 1);
        [$hundred, $hundredQueries] = $this->measurePoints($service, $input);

        $this->assertCount(1, $one['features']);
        $this->assertCount(100, $hundred['features']);
        $this->assertFalse($hundred['meta']['truncated']);
        $this->assertSame(100, $hundred['meta']['in_view']);
        $this->assertCount(100, array_unique(array_column($hundred['features'], 'id')));
        foreach ($hundred['features'] as $feature) {
            $this->assertNotEmpty($feature['properties']['scientific_name']);
            $this->assertNotEmpty($feature['properties']['common_name']);
            $this->assertNotEmpty($feature['properties']['placement']);
        }
        $first = collect($hundred['features'])->firstWhere('id', $firstId);
        $this->assertSame('2026-09-12', $first['properties']['latest_inspection']['date']);
        $measurements = ['one_marker_queries' => $oneQueries, 'hundred_marker_queries' => $hundredQueries];
        $message = 'Consultas SQL de MapReadService::points: '.json_encode($measurements, JSON_THROW_ON_ERROR);
        $this->assertLessThan(80, $oneQueries, $message);
        $this->assertLessThan(80, $hundredQueries, $message);
        $this->assertLessThanOrEqual($oneQueries + 5, $hundredQueries, $message);
        $this->writeArtifact('/tmp/sysforest-phase10-query-count.json', $measurements);
    }

    public function test_selective_bbox_naturally_uses_gist_with_four_thousand_ninety_six_points(): void
    {
        $coordinates = [];
        for ($row = 0; $row < 64; $row++) {
            for ($column = 0; $column < 64; $column++) {
                $coordinates[] = [-77 + $column * 0.1, -2 + $row * 0.1];
            }
        }
        $this->insertPoints('PERF-PLAN-', $coordinates);
        $this->assertSame(4096, DB::table('trees')->where('organization_id', $this->organization->id)
            ->where('code', 'like', 'PERF-PLAN-%')->count());

        // Se conserva el planificador normal; no se fuerza el índice ni se cambian permisos de mantenimiento.
        $settings = (array) DB::selectOne("SELECT current_setting('enable_seqscan') AS enable_seqscan,
            current_setting('enable_indexscan') AS enable_indexscan,
            current_setting('enable_bitmapscan') AS enable_bitmapscan");
        $this->assertSame('on', $settings['enable_seqscan']);
        $bbox = ['west' => -73.81, 'south' => 1.19, 'east' => -73.79, 'north' => 1.21];
        $filters = new MapTreeFilterDTO(TreeFilterDTO::fromValidated([]), $bbox, 15);
        $query = app(MapTreeRepository::class)->query(TreeReadScope::organization($this->organization->id), $filters, true)
            ->whereNotNull('trees.geometry')->select(['trees.id'])
            ->selectRaw('ST_X(trees.geometry) AS longitude, ST_Y(trees.geometry) AS latitude,
                latest_inspection.inspection_date AS latest_inspection_date');
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $raw = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);
        $plan = json_decode($raw[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR);
        $indexes = array_values(array_unique($this->indexNames($plan[0]['Plan'])));

        $artifact = ['database' => config('database.connections.pgsql.database'), 'synthetic_points' => 4096,
            'bbox' => $bbox, 'planner_settings' => $settings, 'indexes' => $indexes,
            'sql' => $sql, 'bindings' => $bindings, 'explain_analyze_buffers' => $plan];
        $this->writeArtifact('/tmp/sysforest-phase10-explain.json', $artifact);
        $this->assertSame(1, $plan[0]['Plan']['Actual Rows']);
        $this->assertContains(
            'trees_geometry_gist',
            $indexes,
            'El encuadre selectivo debe usar el GiST espacial sin deshabilitar recorridos secuenciales. Plan: '
                .json_encode($plan, JSON_THROW_ON_ERROR)
        );
        $this->assertArrayHasKey('Shared Hit Blocks', $plan[0]['Plan']);
        $this->assertGreaterThanOrEqual(0, $plan[0]['Execution Time']);
    }

    /** La preparación por lotes queda fuera de la ventana que cuenta las consultas del servicio real. */
    private function insertPoints(string $prefix, array $coordinates, array $species = [], array $placements = [], int $offset = 0): array
    {
        $ids = [];
        $timestamp = now()->toDateTimeString();
        foreach (array_chunk($coordinates, 256, true) as $batch) {
            $tuples = [];
            $bindings = [];
            foreach ($batch as $index => [$longitude, $latitude]) {
                $ordinal = $index + $offset;
                $id = (string) Str::uuid();
                $ids[] = $id;
                $tuples[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326))';
                array_push(
                    $bindings,
                    $id,
                    $this->organization->id,
                    $prefix.str_pad((string) $ordinal, 5, '0', STR_PAD_LEFT),
                    $species === [] ? null : $species[$ordinal % count($species)],
                    $placements === [] ? null : $placements[$ordinal % count($placements)],
                    $this->admin->id,
                    $this->admin->id,
                    $timestamp,
                    $timestamp,
                    $longitude,
                    $latitude
                );
            }
            DB::insert('INSERT INTO trees (id, organization_id, code, species_id, placement_id,
                created_by, updated_by, created_at, updated_at, geometry) VALUES '.implode(', ', $tuples), $bindings);
        }

        return $ids;
    }

    /** Incluye autorización, filtros, contadores, selección, relaciones y proyección de cada marcador. */
    private function measurePoints(MapReadService $service, array $input): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $service->points($this->admin, $input);

            return [$result, count($connection->getQueryLog())];
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function indexNames(array $node): array
    {
        $indexes = isset($node['Index Name']) ? [$node['Index Name']] : [];
        foreach ($node['Plans'] ?? [] as $child) {
            array_push($indexes, ...$this->indexNames($child));
        }

        return $indexes;
    }

    /** El artefacto optativo describe datos técnicos sintéticos, sin usuarios, credenciales ni ubicaciones productivas. */
    private function writeArtifact(string $path, array $data): void
    {
        if ((string) env('SYSFOREST_MAP_EXPLAIN') !== '1') {
            return;
        }

        $this->assertNotFalse(file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL));
    }
}

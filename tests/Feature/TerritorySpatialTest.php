<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\MunicipalityRepository;
use App\Services\TerritoryService;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

class TerritorySpatialTest extends PostgresTestCase
{
    public function test_real_postgis_multipolygon_roundtrip_and_spatial_query(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        // Polígono sintético exclusivo de la transacción de pruebas; no es un límite oficial.
        $wkt = 'MULTIPOLYGON(((-73.7 4.1,-73.6 4.1,-73.6 4.2,-73.7 4.2,-73.7 4.1)))';
        $service = app(TerritoryService::class);
        $service->storeMunicipalityGeometry($actor, $pilot->municipality_id, $wkt);
        $geometry = app(MunicipalityRepository::class)->geometry($pilot->municipality_id);
        $this->assertSame($wkt, $geometry->wkt);
        $this->assertSame(4326, (int) $geometry->srid);
        $this->assertSame([$pilot->municipality_id], $service->containingPoint(-73.65, 4.15)->modelKeys());
        $this->assertCount(0, $service->containingPoint(-70, 0));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_territorial_geometry_columns_have_gist_indexes_and_expected_type(): void
    {
        $indexes = DB::select("SELECT tablename FROM pg_indexes WHERE tablename IN ('departments','municipalities') AND indexdef LIKE '%USING gist%'");
        $this->assertCount(2, $indexes);
        $columns = DB::select("SELECT f_table_name, type, srid FROM geometry_columns WHERE f_table_name IN ('departments','municipalities')");
        $this->assertCount(2, $columns);
        foreach ($columns as $column) {
            $this->assertSame('MULTIPOLYGON', $column->type);
            $this->assertSame(4326, (int) $column->srid);
        }
    }

    public function test_territory_overview_resolves_the_full_hierarchy(): void
    {
        $this->pilot();
        $countries = app(TerritoryService::class)->overview();
        $colombia = $countries->firstWhere('code', '170');
        $this->assertTrue($colombia->relationLoaded('departments'));
        $meta = $colombia->departments->firstWhere('code', '50');
        $this->assertTrue($meta->relationLoaded('municipalities'));
        $this->assertSame('Villavicencio', $meta->municipalities->firstWhere('code', '50001')->name);
        $this->assertSame('Colombia', app(TerritoryService::class)->municipalityOptions()->first()->department->country->name);
    }
}

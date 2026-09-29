<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalogs\CatalogRegistry;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Repositories\ClassificationRuleRepository;
use App\Services\CatalogService;
use App\Services\ConditionCatalogService;
use App\Services\OrganizationService;
use App\Services\TaxonomyService;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class AdministrativeConcurrencyTest extends PostgresTestCase
{
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->first = User::factory()->create();
        $this->second = User::factory()->create();
        $this->allowNationalManagement($this->first);
        $this->allowNationalManagement($this->second);
    }

    /** Dos formularios abiertos con la misma versión no pueden confirmar ambos sus cambios. */
    #[DataProvider('editableKinds')]
    public function test_second_editor_gets_conflict_without_overwriting_data_or_audit(string $kind): void
    {
        [$record, $url, $editUrl, $input, $name] = $this->fixture($kind);
        $record = $record->fresh();
        $this->actingAs($this->first)->get($editUrl)->assertOk()
            ->assertSee('name="lock_version" value="1"', false);
        $firstInput = array_replace($input, [$name => 'Primera edición conservada', 'lock_version' => $record->lock_version]);
        $this->put($url, $firstInput)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $record->fresh()->lock_version);
        $events = SecurityEvent::query()->where('metadata->resource_id', $record->id)->count();

        $secondInput = array_replace($input, [$name => 'Segunda edición obsoleta', 'lock_version' => $record->lock_version]);
        $this->actingAs($this->second)->putJson($url, $secondInput)->assertConflict();
        $this->assertSame('Primera edición conservada', $record->fresh()->getAttribute($name));
        $this->assertSame(2, $record->fresh()->lock_version);
        $this->assertSame($events, SecurityEvent::query()->where('metadata->resource_id', $record->id)->count());

        $this->get($editUrl)->assertOk()->assertSee('name="lock_version" value="2"', false);
        $this->put($url, array_replace($secondInput, ['lock_version' => 2]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Segunda edición obsoleta', $record->fresh()->getAttribute($name));
        $this->assertSame(3, $record->fresh()->lock_version);
    }

    #[DataProvider('editableKinds')]
    public function test_missing_revision_is_never_replaced_with_current_server_version(string $kind): void
    {
        [$record, $url, , $input, $name] = $this->fixture($kind);
        $originalName = $record->fresh()->getAttribute($name);
        $this->actingAs($this->first)->putJson($url, array_replace($input, [$name => 'No debe guardarse']))
            ->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertSame($originalName, $record->fresh()->getAttribute($name));
        $this->assertSame(1, $record->fresh()->lock_version);
    }

    #[DataProvider('invalidVersions')]
    public function test_catalog_rejects_malformed_revisions_without_writes(mixed $version): void
    {
        [$record, $url, , $input] = $this->fixture('placements');
        $this->actingAs($this->first)->putJson($url, array_replace($input, ['lock_version' => $version]))
            ->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertSame(1, $record->fresh()->lock_version);
    }

    public function test_database_rejects_nonpositive_administrative_versions(): void
    {
        [$record] = $this->fixture('placements');
        try {
            DB::transaction(fn () => DB::table($record->getTable())->where('id', $record->id)->update(['lock_version' => 0]));
            $this->fail('La base aceptó una versión no positiva.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame(1, $record->fresh()->lock_version);
    }

    public static function invalidVersions(): array
    {
        return [[null], [0], [-1], [true], [false], [1.5], ['01'], ['1e0'], ['+1'], ['1 0'], ['2147483647'], ['99999999999999999999']];
    }

    public static function editableKinds(): array
    {
        return array_map(fn (string $kind): array => [$kind], [
            'placements', 'establishment-origins', 'biogeographic-origins', 'growth-habits',
            'maturity-states', 'evidence-types', 'maintenance-types', 'deletion-reasons', 'fauna-categories',
            'families', 'species', 'common-names', 'categories', 'items', 'classification', 'organization',
        ]);
    }

    /** @return array{Model, string, string, array, string} Registro, rutas, entrada válida y etiqueta editable. */
    private function fixture(string $kind): array
    {
        if (in_array($kind, array_map(fn ($entry) => $entry->key, app(CatalogRegistry::class)->all()), true)) {
            $input = ['code' => 'VERSION_TEST', 'name' => 'Valor original', 'active' => true, 'sort_order' => 0];
            $record = app(CatalogService::class)->create($this->first, $kind, $input);
            $url = route('catalogs.update', [$kind, $record->id]);

            return [$record, $url, route('catalogs.edit', [$kind, $record->id]), $input, 'name'];
        }
        if (in_array($kind, ['families', 'species', 'common-names'], true)) {
            $service = app(TaxonomyService::class);
            $input = ['code' => 'VERSION_FAMILY', 'scientific_name' => 'Familia original'];
            $record = $service->create($this->first, 'families', $input);
            if ($kind !== 'families') {
                $input = ['code' => 'VERSION_SPECIES', 'scientific_name' => 'Especie original', 'family_id' => $record->id];
                $record = $service->create($this->first, 'species', $input);
            }
            if ($kind === 'common-names') {
                $input = ['code' => 'VERSION_COMMON', 'name' => 'Nombre original', 'species_id' => $record->id];
                $record = $service->create($this->first, 'common-names', $input);
            }

            return [$record, route('taxonomy.update', [$kind, $record->id]), route('taxonomy.edit', [$kind, $record->id]), $input, $kind === 'common-names' ? 'name' : 'scientific_name'];
        }
        if (in_array($kind, ['categories', 'items'], true)) {
            $service = app(ConditionCatalogService::class);
            $input = ['code' => 'VERSION_CATEGORY', 'name' => 'Categoría original', 'assessment_type' => 'physical_mechanical'];
            $record = $service->create($this->first, 'categories', $input);
            if ($kind === 'items') {
                $input = ['code' => 'VERSION_ITEM', 'name' => 'Condición original', 'assessment_type' => 'physical_mechanical', 'condition_category_id' => $record->id];
                $record = $service->create($this->first, 'items', $input);
            }

            return [$record, route('conditions.update', [$kind, $record->id]), route('conditions.edit', [$kind, $record->id]), $input, 'name'];
        }
        if ($kind === 'classification') {
            $this->seed([ForestryCatalogSeeder::class, ClassificationRuleSeeder::class]);
            $record = app(ClassificationRuleRepository::class)->findByCode('EXCEL_V1');
            $input = ['name' => 'Clasificación original', 'active' => true, 'sort_order' => 0];

            return [$record, route('classification.update', $record->id), route('classification.edit', $record->id), $input, 'name'];
        }
        $input = $this->organizationInput($this->pilot());
        $record = app(OrganizationService::class)->create($this->first, $input);
        $url = '/administration/organizations/'.$record->id;

        return [$record, $url, $url.'/edit', $input, 'name'];
    }
}

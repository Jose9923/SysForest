<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ImportTemplateSchema;
use App\Models\FaunaCategory;
use App\Models\Species;
use App\Reports\InventoryExportSchema;
use App\Repositories\ImportCatalogRepository;
use App\Services\DendrometryCalculationService;
use App\Services\ImportRowValidationService;
use App\Services\TreeLifecycleService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\InspectionTestCase;

class ImportRowValidationTest extends InspectionTestCase
{
    public function test_preview_is_read_only_and_preserves_exact_measurements_and_legacy_number(): void
    {
        $counts = $this->counts();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $values = $this->values(['code' => $this->organization->tree_code_prefix.'-000200',
            'external_number' => '00007-A', 'cap_cm' => '154.000001', 'number_of_stems' => '9223372036854775807',
            'coordinate_raw' => '4°15\'19.2" N, 73°34\'19.1" W', 'datum' => 'WGS84',
            'captured_at' => '2026-09-10T10:30:00-05:00', 'accuracy_m' => '0', 'is_protected' => '1']);
        $result = $this->validateRow($values);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $result['errors']);
        $this->assertSame($counts, $this->counts());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete|truncate|alter)\b/i', $query['query']);
        }
        $this->assertSame('00007-A', $result['tree']['external_number']);
        $this->assertSame('154.000001', $result['inspection']['cap_cm']);
        $this->assertSame(PHP_INT_MAX, $result['inspection']['number_of_stems']);
        $this->assertSame($values['coordinate_raw'], $result['tree']['coordinate_raw']);
        $this->assertSame('2026-09-10T15:30:00.000000+00:00', $result['tree']['captured_at']);
        $this->assertTrue($result['tree']['is_protected']);
        $this->assertSame(app(DendrometryCalculationService::class)->calculate($result['inspection']), $result['calculations']);
    }

    public function test_minimal_row_keeps_unknown_fields_null_without_inventing_observations(): void
    {
        $result = $this->validateRow($this->values());
        $this->assertSame([], $result['errors']);
        $this->assertNull($result['requested_code']);
        $this->assertNull($result['tree']['species_id']);
        $this->assertNull($result['tree']['coordinate_raw']);
        $this->assertNull($result['inspection']['cap_cm']);
        $this->assertNull($result['calculations']['dap_m']);
        $this->assertSame([], $result['inspection']['physical_condition_ids']);
        $this->assertSame([], $result['inspection']['fauna_category_ids']);
    }

    public function test_all_65_original_condition_columns_resolve_without_replacing_their_organs(): void
    {
        $values = $this->values();
        foreach (array_keys(InventoryExportSchema::ORIGINAL_CONDITIONS) as $code) {
            $values['condition_'.$code] = '1';
        }
        $result = $this->validateRow($values);
        $this->assertSame([], $result['errors']);
        $this->assertCount(23, $result['inspection']['physical_condition_ids']);
        $this->assertCount(42, $result['inspection']['phytosanitary_condition_ids']);
        $expected = DB::table('conditions')->whereIn('code', array_keys(InventoryExportSchema::ORIGINAL_CONDITIONS))->pluck('id')->all();
        $this->assertEqualsCanonicalizing($expected, array_merge($result['inspection']['physical_condition_ids'], $result['inspection']['phytosanitary_condition_ids']));
    }

    public function test_zero_and_blank_condition_cells_do_not_create_observations(): void
    {
        $result = $this->validateRow($this->values(['condition_PHY_STEM_STRAIGHT' => '0', 'condition_PHT_GENERAL_HEALTHY' => '']));
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['inspection']['physical_condition_ids']);
        $this->assertSame([], $result['inspection']['phytosanitary_condition_ids']);
    }

    public function test_catalogs_require_exact_available_codes_and_available_parents(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $valid = $this->validateRow($this->values(['species_code' => $species->code]));
        $this->assertSame($species->id, $valid['tree']['species_id']);
        foreach ([$species->scientific_name, strtolower($species->code), ' '.$species->code, 'UNKNOWN'] as $code) {
            $this->assertArrayHasKey('species_code', $this->validateRow($this->values(['species_code' => $code]))['errors']);
        }
        $species->family->update(['active' => false]);
        $this->assertArrayHasKey('species_code', $this->validateRow($this->values(['species_code' => $species->code]))['errors']);
    }

    public function test_condition_category_and_fauna_deactivation_are_reported_at_the_source_column(): void
    {
        $condition = $this->condition('PHY_STEM_STRAIGHT');
        $condition->category->update(['active' => false]);
        $fauna = FaunaCategory::query()->firstOrFail();
        $fauna->update(['active' => false]);
        $result = $this->validateRow($this->values(['condition_PHY_STEM_STRAIGHT' => '1', 'fauna_category_codes' => $fauna->code]));
        $this->assertArrayHasKey('condition_PHY_STEM_STRAIGHT', $result['errors']);
        $this->assertArrayHasKey('fauna_category_codes', $result['errors']);
    }

    public function test_fauna_codes_are_distinct_exact_tokens_and_preserve_independent_text(): void
    {
        $fauna = FaunaCategory::query()->orderBy('code')->take(2)->get();
        $result = $this->validateRow($this->values(['fauna_category_codes' => $fauna->pluck('code')->implode('|'),
            'fauna_observations' => '  Aves en copa.  ', 'general_observations' => 'Observación distinta.']));
        $this->assertSame([], $result['errors']);
        $this->assertSame($fauna->modelKeys(), $result['inspection']['fauna_category_ids']);
        $this->assertSame('  Aves en copa.  ', $result['inspection']['fauna_observations']);
        foreach ([$fauna[0]->code.'|'.$fauna[0]->code, $fauna[0]->code.'|', $fauna[0]->code.' |'.$fauna[1]->code] as $codes) {
            $this->assertArrayHasKey('fauna_category_codes', $this->validateRow($this->values(['fauna_category_codes' => $codes]))['errors']);
        }
    }

    public function test_derived_taxonomy_and_photo_cells_cannot_be_silently_discarded(): void
    {
        $values = $this->values();
        $reserved = [];
        foreach (app(ImportTemplateSchema::class)->columns() as $field => $definition) {
            if ($definition['mode'] !== 'input') {
                $values[$field] = $definition['mode'] === 'evidence' ? 'https://example.invalid/image.jpg' : '123';
                $reserved[] = $field;
            }
        }
        $result = $this->validateRow($values);
        $this->assertCount(13, $reserved);
        foreach ($reserved as $field) {
            $this->assertArrayHasKey($field, $result['errors']);
            $this->assertArrayNotHasKey($field, $result['tree']);
            $this->assertArrayNotHasKey($field, $result['inspection']);
        }
    }

    public function test_reader_errors_survive_even_when_formula_values_are_null(): void
    {
        $result = app(ImportRowValidationService::class)->validate(
            $this->organization,
            $this->inventory,
            $this->values(['cap_cm' => null]),
            ['cap_cm' => ['No se admiten fórmulas.']]
        );
        $this->assertSame(['No se admiten fórmulas.'], $result['errors']['cap_cm']);
    }

    public function test_closed_or_foreign_inventory_is_rejected_without_writes(): void
    {
        $inventory = clone $this->inventory;
        $inventory->status = 'closed';
        $result = app(ImportRowValidationService::class)->validate($this->organization, $inventory, $this->values());
        $this->assertArrayHasKey('inventory_id', $result['errors']);
        $inventory->status = 'active';
        $inventory->organization_id = $this->anotherOrganization()->id;
        $result = app(ImportRowValidationService::class)->validate($this->organization, $inventory, $this->values());
        $this->assertArrayHasKey('inventory_id', $result['errors']);
    }

    public function test_existing_and_soft_deleted_codes_remain_reserved(): void
    {
        $this->assertArrayHasKey('code', $this->validateRow($this->values(['code' => $this->tree->code]))['errors']);
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, ['lock_version' => 1,
            'deletion_reason_id' => DB::table('deletion_reasons')->where('code', 'DEL_DUPLICATE')->value('id')]);
        $this->assertArrayHasKey('code', $this->validateRow($this->values(['code' => $this->tree->code]))['errors']);
    }

    public function test_code_aliases_wrong_prefix_and_exhaustion_are_not_accepted(): void
    {
        $prefix = $this->organization->tree_code_prefix.'-';
        foreach ([$prefix.'000000', $prefix.'2', $prefix.'0000002', strtolower($prefix).'000002',
            'OTHER-000002', $prefix.'9223372036854775807', $prefix.'000002 '] as $code) {
            $this->assertArrayHasKey('code', $this->validateRow($this->values(['code' => $code]))['errors']);
        }
    }

    public function test_template_catalog_has_exact_names_and_excludes_inactive_items(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $rows = app(ImportCatalogRepository::class)->templateOptions();
        $row = collect($rows)->firstWhere('code', $species->code);
        $this->assertSame($species->scientific_name, $row['label']);
        $this->assertStringContainsString($species->family->scientific_name, $row['details']);
        $species->update(['active' => false]);
        $this->assertNull(collect(app(ImportCatalogRepository::class)->templateOptions())->firstWhere('code', $species->code));
    }

    #[DataProvider('invalidCells')]
    public function test_invalid_cells_have_field_errors(string $field, mixed $value, array $extra = []): void
    {
        $result = $this->validateRow($this->values(array_replace($extra, [$field => $value])));
        $this->assertArrayHasKey($field, $result['errors']);
    }

    public static function invalidCells(): array
    {
        return [
            ['inspection_date', null], ['inspection_date', '2026-02-30'], ['inspection_date', '28/09/2026'],
            ['inspection_date', '46300'], ['flowering_date', '2026-09-28T12:00:00'],
            ['current_status', 'Activo'], ['cap_cm', '1,25'], ['cap_cm', '1e2'], ['cap_cm', '154.0000001'],
            ['cap_cm', '0'], ['cap_cm', 1.25], ['cap_cm', true], ['cap_cm', []],
            ['number_of_stems', '1.5'], ['number_of_stems', '9223372036854775808'],
            ['commercial_height_m', '12', ['total_height_m' => '10']], ['form_factor', '0.71'],
            ['condition_PHY_STEM_STRAIGHT', 'x'], ['is_protected', 'sí'], ['is_heritage', true],
            ['coordinate_raw', '91, -73'], ['datum', 'MAGNA-SIRGAS'], ['accuracy_m', '0.1234'],
            ['captured_at', '2026-09-28'], ['captured_at', '2026-09-28T10:30:00'], ['organization_id', 'ignored?'], ['species_id', 'uuid?'],
        ];
    }

    private function values(array $extra = []): array
    {
        return array_replace(['current_status' => 'active', 'inspection_date' => '2026-09-28'], $extra);
    }

    private function validateRow(array $values): array
    {
        return app(ImportRowValidationService::class)->validate($this->organization, $this->inventory, $values);
    }

    private function counts(): array
    {
        return array_map(
            fn (string $table): int => DB::table($table)->count(),
            ['trees', 'tree_inspections', 'inventory_trees', 'tree_status_histories', 'tree_location_histories', 'inspection_revisions', 'security_events', 'organization_sequences']
        );
    }
}

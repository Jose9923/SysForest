<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Imports\ImportTemplateSchema;
use App\Imports\ImportWorkbookReader;
use Illuminate\Validation\ValidationException;
use Tests\Support\ImportWorkbookFactory;
use Tests\TestCase;

class ImportWorkbookTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_schema_preserves_all_94_original_variables_and_65_conditions(): void
    {
        $schema = new ImportTemplateSchema();
        $columns = $schema->columns();
        $sources = array_unique(array_filter(array_column($columns, 'source')));
        $this->assertCount(94, $sources);
        $this->assertCount(65, array_filter(array_keys($columns), static fn (string $key) => str_starts_with($key, 'condition_')));
        $this->assertSame(array_keys($columns), $schema->headers());
        $this->assertSame('backend', $columns['dap_m']['mode']);
        $this->assertSame('decimal', $columns['dap_m']['type']);
        $this->assertSame('integer', $columns['diameter_class_number']['type']);
        $this->assertSame('integer', $columns['height_class_number']['type']);
        $this->assertSame('text', $columns['maturity_state']['type']);
        $this->assertSame('backend', $columns['family']['mode']);
        $this->assertSame('evidence', $columns['tree_photo_urls']['mode']);
        $this->assertTrue($columns['inspection_date']['required']);
        $this->assertArrayNotHasKey('organization_id', $columns);
        $this->assertArrayNotHasKey('inventory_id', $columns);
    }

    public function test_reader_preserves_exact_numeric_xml_and_text_without_float_conversion(): void
    {
        $rows = $this->read([2 => ['code' => 'ARB-000001', 'external_number' => '0009',
            'cap_cm' => ['type' => 'n', 'value' => '999999999999.123456'],
            'number_of_stems' => ['type' => 'n', 'value' => '9223372036854775807'],
            'form_factor' => '0.450000', 'is_protected' => ['type' => 'b', 'value' => '0'],
            'inspection_date' => '2026-01-31', 'coordinate_raw' => '4°15\'19.2" N, 73°34\'19.1" W',
            'general_observations' => "Árbol ñandú\nsegunda línea"]]);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['row_number']);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame('999999999999.123456', $rows[0]['values']['cap_cm']);
        $this->assertSame('9223372036854775807', $rows[0]['values']['number_of_stems']);
        $this->assertSame('0009', $rows[0]['values']['external_number']);
        $this->assertSame('0', $rows[0]['values']['is_protected']);
        $this->assertNull($rows[0]['values']['crown_x_m']);
    }

    public function test_blank_rows_are_omitted_and_original_row_numbers_are_preserved(): void
    {
        $rows = $this->read([2 => ['external_number' => ''], 7 => ['external_number' => 'N-1']]);
        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['row_number']);
        $this->assertSame([], $this->read([]));
    }

    public function test_empty_inline_cells_saved_by_an_editor_are_omitted_in_data_and_auxiliary_sheets(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $blank = '<row r="2"><c r="A2" s="0" t="inlineStr"/><c r="B2" t="inlineStr"></c></row>';
        $overrides = [];
        for ($sheet = 1; $sheet <= 4; $sheet++) {
            $path = 'xl/worksheets/sheet'.$sheet.'.xml';
            $overrides[$path] = str_replace('</sheetData>', $blank.'</sheetData>', $entries[$path]);
        }
        $this->assertSame([], $this->read([], $overrides));
    }

    public function test_empty_inline_cells_beside_values_preserve_exact_text_and_original_row_numbers(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $blank = '<row r="2"><c r="B2" t="inlineStr"/></row>';
        $populated = '<row r="7"><c r="A7" t="inlineStr"/>'
            .ImportWorkbookFactory::cell('B7', '000123')
            .ImportWorkbookFactory::cell('O7', '2026-09-28')
            .ImportWorkbookFactory::cell('P7', '123.450000')
            .ImportWorkbookFactory::cell('CZ7', 'Árbol en revisión').'</row>';
        $rows = $this->read([], ['xl/worksheets/sheet1.xml' => str_replace('</sheetData>', $blank.$populated.'</sheetData>', $entries['xl/worksheets/sheet1.xml'])]);
        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['row_number']);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertNull($rows[0]['values']['code']);
        $this->assertSame('000123', $rows[0]['values']['external_number']);
        $this->assertSame('2026-09-28', $rows[0]['values']['inspection_date']);
        $this->assertSame('123.450000', $rows[0]['values']['cap_cm']);
        $this->assertSame('Árbol en revisión', $rows[0]['values']['general_observations']);
    }

    public function test_formulas_error_cells_extra_columns_and_native_serial_dates_are_visible_errors(): void
    {
        $rows = $this->read([2 => ['cap_cm' => ['formula' => 'SUM(1,2)', 'value' => '3'],
            'general_observations' => ['type' => 'e', 'value' => '#VALUE!'],
            'inspection_date' => ['type' => 'n', 'value' => '45688'], 'unexpected' => 'PRIVATE']]);
        foreach (['cap_cm', 'general_observations', 'inspection_date', '_row'] as $field) {
            $this->assertArrayHasKey($field, $rows[0]['errors']);
        }
        $this->assertNull($rows[0]['values']['cap_cm']);
        $this->assertArrayNotHasKey('unexpected', $rows[0]['values']);
    }

    public function test_shared_strings_rich_text_and_literal_escape_sequences_are_preserved(): void
    {
        $rows = $this->read([2 => ['external_number' => ['type' => 's', 'value' => '0'], 'general_observations' => '_x005F_x0000_']], [
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Ár</t></r><r><t>bol</t></r></si></sst>',
        ]);
        $this->assertSame('Árbol', $rows[0]['values']['external_number']);
        $this->assertSame('_x0000_', $rows[0]['values']['general_observations']);
    }

    public function test_bad_shared_string_reference_is_an_error_in_its_row(): void
    {
        $rows = $this->read([2 => ['external_number' => ['type' => 's', 'value' => '999']]]);
        $this->assertArrayHasKey('external_number', $rows[0]['errors']);
    }

    public function test_escaped_controls_and_invalid_surrogates_are_errors_but_valid_surrogate_pairs_survive(): void
    {
        $rows = $this->read([2 => ['general_observations' => '_x0000_', 'external_number' => '_xD83D_',
            'location_description' => '_xD83D__xDE00_', 'is_protected' => ['type' => 'b', 'value' => '2']]]);
        $this->assertArrayHasKey('general_observations', $rows[0]['errors']);
        $this->assertArrayHasKey('external_number', $rows[0]['errors']);
        $this->assertArrayHasKey('is_protected', $rows[0]['errors']);
        $this->assertSame('😀', $rows[0]['values']['location_description']);
    }

    public function test_scientific_notation_is_preserved_for_domain_rejection_and_never_expanded(): void
    {
        $rows = $this->read([2 => ['cap_cm' => ['type' => 'n', 'value' => '1.9999999999999999E+19']]]);
        $this->assertSame('1.9999999999999999E+19', $rows[0]['values']['cap_cm']);
    }

    public function test_excessive_cell_length_is_never_truncated_and_imported(): void
    {
        config(['imports.max_cell_chars' => 100]);
        $rows = $this->read([2 => ['general_observations' => str_repeat('a', 101)]]);
        $this->assertArrayHasKey('general_observations', $rows[0]['errors']);
        $this->assertNull($rows[0]['values']['general_observations']);
    }

    public function test_more_than_maximum_records_rejects_the_entire_workbook(): void
    {
        config(['imports.max_rows' => 1]);
        $this->assertRejected([2 => ['external_number' => '1'], 3 => ['external_number' => '2']], [], 'máximo');
    }

    public function test_headers_and_order_cannot_be_changed(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('>code<', '>organization_id<', $entries['xl/worksheets/sheet1.xml'])], 'encabezados');
    }

    public function test_version_and_sheet_visibility_are_strict(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $this->assertRejected([], ['xl/worksheets/sheet4.xml' => str_replace(ImportTemplateSchema::VERSION, 'V0', $entries['xl/worksheets/sheet4.xml'])], 'versión');
        $this->assertRejected([], ['xl/workbook.xml' => str_replace('name="Datos"', 'name="Datos" state="hidden"', $entries['xl/workbook.xml'])], 'cuatro hojas');
    }

    public function test_macros_external_parts_and_traversal_paths_are_rejected_before_reading(): void
    {
        foreach (['xl/vbaProject.bin', 'xl/embeddings/oleObject1.bin', '../outside.xml', '/absolute.xml'] as $name) {
            $this->assertRejected([], [$name => '<x/>'], 'componentes');
        }
    }

    public function test_external_relationships_and_dtd_are_rejected_without_resolution(): void
    {
        $this->assertRejected([], ['_rels/.rels' => '<Relationships><Relationship Id="bad" TargetMode="External" Target="http://127.0.0.1/private"/></Relationships>'], 'externos');
        $this->assertRejected([], ['docProps/core.xml' => '<!DOCTYPE x [<!ENTITY bad SYSTEM "file:///etc/passwd">]><x>&bad;</x>'], 'entidades');
    }

    public function test_malformed_xml_case_duplicate_paths_and_extra_sheet_parts_are_rejected(): void
    {
        $this->assertRejected([], ['docProps/core.xml' => '<broken>'], 'XML');
        $this->assertRejected([], ['xl/WORKBOOK.xml' => '<x/>'], 'componentes');
        $this->assertRejected([], ['xl/worksheets/sheet5.xml' => ImportWorkbookFactory::sheet('')], 'adicionales');
    }

    public function test_duplicate_cells_and_rows_are_not_silently_overwritten(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $header = $entries['xl/worksheets/sheet1.xml'];
        $row = '<row r="2">'.ImportWorkbookFactory::cell('A2', 'A').ImportWorkbookFactory::cell('A2', 'B').'</row>';
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</sheetData>', $row.'</sheetData>', $header)], 'repite');
        $row = ImportWorkbookFactory::row(2, ['external_number' => '1']);
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</sheetData>', $row.$row.'</sheetData>', $header)], 'numeración');
    }

    public function test_auxiliary_formulas_and_merged_cells_are_not_accepted(): void
    {
        $this->assertRejected([], ['xl/worksheets/sheet2.xml' => ImportWorkbookFactory::sheet('<row r="1">'.ImportWorkbookFactory::cell('A1', ['formula' => '1+1']).'</row>')], 'auxiliares');
        $entries = ImportWorkbookFactory::entries();
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</worksheet>', '<mergeCells/></worksheet>', $entries['xl/worksheets/sheet1.xml'])], 'combinadas');
    }

    public function test_rows_or_cells_outside_sheet_data_are_rejected(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</worksheet>', '<other>'.ImportWorkbookFactory::row(2, ['external_number' => '1']).'</other></worksheet>', $entries['xl/worksheets/sheet1.xml'])], 'fuera');
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</worksheet>', ImportWorkbookFactory::cell('A2', 'lost').'</worksheet>', $entries['xl/worksheets/sheet1.xml'])], 'fuera');
    }

    public function test_foreign_namespace_rows_are_rejected_instead_of_silently_skipped(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $row = '<x:row xmlns:x="urn:foreign" r="2">'.ImportWorkbookFactory::cell('A2', 'dato').'</x:row>';
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</sheetData>', $row.'</sheetData>', $entries['xl/worksheets/sheet1.xml'])], 'nombres');
        $this->assertRejected([], ['xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:si xmlns:x="urn:foreign"><t>dato</t></x:si></sst>'], 'ambiguos');
    }

    public function test_duplicate_or_mixed_cell_payloads_are_errors_for_every_storage_type(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $payloads = [
            't="inlineStr">texto oculto',
            't="inlineStr"><unexpected/>',
            't="inlineStr"><f/>',
            't="inlineStr"><v/>',
            't="s"><v>0</v><v>1</v>',
            't="s"><v>0</v><is><t>perdido</t></is>',
            't="inlineStr"><v>123</v><is><t>visible</t></is>',
            't="inlineStr"><is><t>uno</t></is><is><t>dos</t></is>',
            't="inlineStr"><is><t>uno</t><t>dos</t></is>',
            't="inlineStr"><is><t>uno</t><r><t>dos</t></r></is>',
            't="inlineStr"><is><r><t>uno</t><t>dos</t></r></is>',
            't="inlineStr"><is><r><rPr><t>oculto</t></rPr><t>visible</t></r></is>',
            't="inlineStr"><is><r><rPr><r/></rPr><t>visible</t></r></is>',
            't="n"><v>1</v><v>2</v>',
            't="n"><v>1</v><is><t>perdido</t></is>',
        ];
        foreach ($payloads as $payload) {
            $row = '<row r="2"><c r="A2" '.$payload.'</c></row>';
            $rows = $this->read([], [
                'xl/worksheets/sheet1.xml' => str_replace('</sheetData>', $row.'</sheetData>', $entries['xl/worksheets/sheet1.xml']),
                'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>uno</t></si><si><t>dos</t></si></sst>',
            ]);
            $this->assertCount(1, $rows);
            $this->assertArrayHasKey('code', $rows[0]['errors'], $payload);
        }
        $this->assertRejected([], ['xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>uno</t><t>dos</t></si></sst>'], 'duplicados');
    }

    public function test_raw_row_text_is_rejected_and_rich_text_reads_only_its_official_positions(): void
    {
        $entries = ImportWorkbookFactory::entries();
        $this->assertRejected([], ['xl/worksheets/sheet1.xml' => str_replace('</sheetData>', '<row r="2">dato perdido</row></sheetData>', $entries['xl/worksheets/sheet1.xml'])], 'texto fuera');
        $row = '<row r="2"><c r="A2" t="inlineStr"><is><r><rPr><b/></rPr><t>000</t></r><r><t>001</t></r></is></c></row>';
        $rows = $this->read([], ['xl/worksheets/sheet1.xml' => str_replace('</sheetData>', $row.'</sheetData>', $entries['xl/worksheets/sheet1.xml'])]);
        $this->assertSame('000001', $rows[0]['values']['code']);
        $this->assertSame([], $rows[0]['errors']);
    }

    public function test_zip_size_entry_and_inflated_limits_are_checked(): void
    {
        config(['imports.max_upload_kb' => 1]);
        $this->assertRejected([], [], 'tamaño');
        config(['imports.max_upload_kb' => 5120, 'imports.max_zip_entries' => 1]);
        $this->assertRejected([], [], 'componentes');
        config(['imports.max_zip_entries' => 200, 'imports.max_uncompressed_bytes' => 100]);
        $this->assertRejected([], [], 'descomprimido');
    }

    private function read(array $rows, array $overrides = []): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sysforest-import-test-');
        $this->paths[] = $path;
        ImportWorkbookFactory::write($path, $rows, $overrides);

        return app(ImportWorkbookReader::class)->read($path);
    }

    private function assertRejected(array $rows, array $overrides, string $message): void
    {
        try {
            $this->read($rows, $overrides);
            $this->fail('El libro inválido debe rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
            $this->assertStringContainsString($message, implode(' ', $exception->errors()['file']));
        }
    }
}

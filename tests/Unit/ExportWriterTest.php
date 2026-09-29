<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Reports\Writers\CsvExportWriter;
use App\Reports\Writers\GeoJsonExportWriter;
use App\Reports\Writers\XlsxExportWriter;
use DOMDocument;
use DOMXPath;
use Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** Prueba bytes y XML reales sin iniciar Laravel ni usar una base de datos. */
class ExportWriterTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_csv_neutralizes_formula_prefixes_including_whitespace_controls_and_full_width_characters(): void
    {
        $values = ['=SUM(1;2)', '+1+1', '-1+1', '@SUM(A1)', "\t=HYPERLINK(\"https://example.invalid\")",
            "\r=1", "\n@SUM(A1)", '  +1', "\u{00A0}=1", "\u{200B}=1", '＝1+1', '＋1+1', '－1+1', '＠SUM(A1)'];
        $path = $this->temporary();
        $count = (new CsvExportWriter())->write(
            $path,
            [['key' => 'text', 'label' => '=Cabecera', 'type' => 'text']],
            array_map(fn (string $value) => ['text' => $value], $values)
        );
        $bytes = file_get_contents($path);
        $rows = $this->csv($bytes);

        $this->assertSame(count($values), $count);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $bytes);
        $this->assertSame(["'=Cabecera"], $rows[0]);
        foreach ($values as $index => $value) {
            $this->assertSame(["'".$value], $rows[$index + 1]);
        }
    }

    public function test_csv_preserves_valid_numbers_null_false_unicode_and_quoted_multiline_values(): void
    {
        $path = $this->temporary();
        $columns = [['key' => 'longitude', 'label' => 'Longitud', 'type' => 'decimal', 'unit' => '°'],
            ['key' => 'precise', 'label' => 'Volumen', 'type' => 'decimal', 'unit' => 'm³'],
            ['key' => 'null', 'label' => 'Ausente'], ['key' => 'zero', 'label' => 'Cero', 'type' => 'integer'],
            ['key' => 'flag', 'label' => 'Protegido', 'type' => 'boolean'], ['key' => 'text', 'label' => 'Descripción']];
        $text = "Ñandú;\"texto\"\n=esto permanece dentro de la misma celda";
        $precision = '123456789012345678901234567890.123456789012';
        (new CsvExportWriter())->write($path, $columns, [['longitude' => '-73.500000000000', 'precise' => $precision,
            'null' => null, 'zero' => 0, 'flag' => false, 'text' => $text]]);
        $rows = $this->csv(file_get_contents($path));

        $this->assertSame(['Longitud (°)', 'Volumen (m³)', 'Ausente', 'Cero', 'Protegido', 'Descripción'], $rows[0]);
        $this->assertSame(['-73.500000000000', $precision, '', '0', 'No', $text], $rows[1]);
        $this->assertCount(2, $rows);
    }

    public function test_csv_does_not_trust_a_declared_numeric_type_to_allow_an_expression(): void
    {
        $path = $this->temporary();
        (new CsvExportWriter())->write(
            $path,
            [['key' => 'value', 'label' => 'Valor', 'type' => 'decimal']],
            [['value' => '-1+1'], ['value' => '=1'], ['value' => '-1.25']]
        );
        $this->assertSame([['Valor'], ["'-1+1"], ["'=1"], ['-1.25']], $this->csv(file_get_contents($path)));
    }

    public function test_csv_consumes_a_single_pass_generator_and_ignores_non_schema_properties(): void
    {
        $path = $this->temporary();
        $rows = (static function (): Generator {
            yield ['code' => 'ARB-1', '_source' => ['tree_id' => 'PRIVATE'], 'secret' => 'NO EXPORTAR'];
            yield ['code' => 'ARB-2'];
        })();
        $count = (new CsvExportWriter())->write($path, [['key' => 'code', 'label' => 'Código']], $rows);

        $this->assertSame(2, $count);
        $this->assertSame([['Código'], ['ARB-1'], ['ARB-2']], $this->csv(file_get_contents($path)));
        $this->assertStringNotContainsString('PRIVATE', file_get_contents($path));
    }

    public function test_xlsx_uses_string_cells_preserves_precision_and_creates_no_formulas_or_hyperlinks(): void
    {
        $path = $this->temporary();
        $values = ['=HYPERLINK("https://example.invalid","x")', '001234', '123456789012345678901234.123456789012',
            '-73.500000000000', 0, false, null, "árbol\nsegunda línea"];
        $columns = [];
        $row = [];
        foreach ($values as $index => $value) {
            $columns[] = ['key' => 'field_'.$index, 'label' => 'Campo '.$index, 'type' => $index === 2 ? 'decimal' : 'text'];
            $row['field_'.$index] = $value;
        }
        $count = (new XlsxExportWriter())->write($path, $columns, [$row], ['title' => 'Consulta forestal',
            'generatedAt' => '2026-09-28T12:00:00Z', 'scopeLabel' => 'Información publicada',
            'appliedFilters' => [['label' => 'Búsqueda', 'value' => '=SUM(A1)']]]);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        try {
            $sheet = $this->xml($zip->getFromName('xl/worksheets/sheet1.xml'));
            $dataCells = $sheet->query('//*[local-name()="row"][@r="2"]/*[local-name()="c"]');
            $this->assertSame(count($values), $dataCells->length);
            $actual = [];
            foreach ($dataCells as $cell) {
                $this->assertSame('inlineStr', $cell->getAttribute('t'));
                $actual[] = $cell->textContent;
            }
            $this->assertSame([$values[0], $values[1], $values[2], $values[3], '0', 'No', '', $values[7]], $actual);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!str_ends_with($name, '.xml') && !str_ends_with($name, '.rels')) {
                    continue;
                }
                $xpath = $this->xml($zip->getFromIndex($index));
                $this->assertSame(0, $xpath->query('//*[local-name()="f" or local-name()="hyperlink" or local-name()="externalLink"]')->length, $name);
            }
            $workbook = $zip->getFromName('xl/workbook.xml');
            $this->assertStringContainsString('name="Datos"', $workbook);
            $this->assertStringContainsString('name="Diccionario"', $workbook);
            $this->assertStringContainsString('name="Criterios"', $workbook);
            $core = $this->xml($zip->getFromName('docProps/core.xml'));
            $this->assertSame('SYSFOREST', $core->query('//*[local-name()="creator"]')->item(0)->textContent);
            $this->assertSame('SYSFOREST', $core->query('//*[local-name()="lastModifiedBy"]')->item(0)->textContent);
        } finally {
            $zip->close();
        }
        $this->assertSame(1, $count);
        $this->assertDirectoryDoesNotExist($path.'.work');
    }

    public function test_xlsx_uses_a_private_workspace_and_removes_it_when_row_generation_fails(): void
    {
        $path = $this->temporary();
        $rows = (function () use ($path): Generator {
            $this->assertDirectoryExists($path.'.work');
            $this->assertSame(0700, fileperms($path.'.work') & 0777);
            $this->assertNotEmpty(glob($path.'.work/xlsx*'));
            yield ['code' => 'ARB-1'];
            throw new RuntimeException('Fallo controlado del lector.');
        })();

        try {
            (new XlsxExportWriter())->write($path, [['key' => 'code', 'label' => 'Código']], $rows);
            $this->fail('La generación debe propagar el fallo para impedir una descarga parcial.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo controlado del lector.', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($path.'.work');
    }

    public function test_xlsx_rejects_an_existing_workspace_link_without_touching_its_target(): void
    {
        $path = $this->temporary();
        $target = $this->temporary();
        file_put_contents($target, 'contenido ajeno');
        $this->assertTrue(symlink($target, $path.'.work'));
        try {
            try {
                (new XlsxExportWriter())->write($path, [['key' => 'code', 'label' => 'Código']], []);
                $this->fail('El espacio preexistente debe rechazarse.');
            } catch (RuntimeException $exception) {
                $this->assertSame('No fue posible preparar el espacio temporal de Excel.', $exception->getMessage());
            }
            $this->assertTrue(is_link($path.'.work'));
            $this->assertSame('contenido ajeno', file_get_contents($target));
        } finally {
            unlink($path.'.work');
        }
    }

    public function test_geojson_has_only_allowed_properties_preserves_geometry_null_and_decimal_strings(): void
    {
        $path = $this->temporary();
        $precise = '123456789012345678901234.123456789012';
        $rows = [['code' => 'ARB-1', 'decimal' => $precise, 'flag' => false,
            '_identity' => 'ORG-PUBLICA/ARB-1', '_geometry' => ['type' => 'Point', 'coordinates' => [-73.5, 4.0]],
            '_source' => ['tree_id' => 'SECRET-UUID'], 'created_by' => 999, 'gps_model' => 'SECRET-GPS', 'notes' => 'SECRET-NOTES'],
            ['code' => 'ARB-2', 'decimal' => null, 'flag' => true, '_identity' => 'ORG-PUBLICA/ARB-2', '_geometry' => null]];
        $count = (new GeoJsonExportWriter())->write(
            $path,
            [['key' => 'code'], ['key' => 'decimal'], ['key' => 'flag']],
            $rows,
            ['title' => 'Consulta pública']
        );
        $bytes = file_get_contents($path);
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $count);
        $this->assertSame('FeatureCollection', $data['type']);
        $this->assertSame(['title' => 'Consulta pública'], $data['metadata']);
        $this->assertSame([-73.5, 4.0], $data['features'][0]['geometry']['coordinates']);
        $this->assertSame(['code' => 'ARB-1', 'decimal' => $precise, 'flag' => false], $data['features'][0]['properties']);
        $this->assertNull($data['features'][1]['geometry']);
        $this->assertNull($data['features'][1]['properties']['decimal']);
        foreach (['SECRET-', '_source', 'created_by', 'gps_model', 'notes'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $bytes);
        }
    }

    public function test_empty_writers_create_valid_files_with_headers_or_an_empty_feature_collection(): void
    {
        $csv = $this->temporary();
        $geojson = $this->temporary();
        $xlsx = $this->temporary();
        $columns = [['key' => 'code', 'label' => 'Código']];

        $this->assertSame(0, (new CsvExportWriter())->write($csv, $columns, []));
        $this->assertSame(0, (new GeoJsonExportWriter())->write($geojson, $columns, []));
        $this->assertSame(0, (new XlsxExportWriter())->write($xlsx, $columns, []));
        $this->assertDirectoryDoesNotExist($xlsx.'.work');
        $this->assertSame([['Código']], $this->csv(file_get_contents($csv)));
        $this->assertSame([], json_decode(file_get_contents($geojson), true, flags: JSON_THROW_ON_ERROR)['features']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsx));
        try {
            $this->assertSame(1, $this->xml($zip->getFromName('xl/worksheets/sheet1.xml'))->query('//*[local-name()="row"]')->length);
        } finally {
            $zip->close();
        }
    }

    private function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sysforest-writer-');
        $this->assertNotFalse($path);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function csv(string $bytes): array
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function xml(string $contents): DOMXPath
    {
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($contents, LIBXML_NONET));

        return new DOMXPath($document);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ImportTemplateSchema;
use App\Imports\ImportWorkbookReader;
use App\Repositories\ImportCatalogRepository;
use App\Services\ImportTemplateService;
use DOMDocument;
use DOMXPath;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Genera y vuelve a leer el XLSX real con catálogos simulados; no utiliza la base de datos. */
class ImportTemplateTest extends TestCase
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

    public function test_template_round_trip_has_four_visible_sheets_blank_text_rows_and_exact_official_columns(): void
    {
        config(['imports.max_rows' => 5]);
        $path = $this->path();
        $catalogs = Mockery::mock(ImportCatalogRepository::class);
        $catalogs->shouldReceive('templateOptions')->once()->andReturn([
            ['catalog' => 'species_code', 'code' => 'SPECIES_TEST', 'label' => 'Especie de prueba', 'details' => 'Familia: familia de prueba'],
            ['catalog' => 'placement_code', 'code' => 'PLC_TEST', 'label' => '=1+1', 'details' => 'Texto literal, no fórmula'],
        ]);
        (new ImportTemplateService(new ImportTemplateSchema(), $catalogs))->write($path);
        $this->assertSame([], app(ImportWorkbookReader::class)->read($path));
        $this->assertDirectoryDoesNotExist($path.'.work');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        try {
            $workbook = $this->xpath($zip->getFromName('xl/workbook.xml'));
            $this->assertCount(4, $workbook->query('//*[local-name()="sheet"]'));
            $data = $this->xpath($zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertCount(6, $data->query('//*[local-name()="row"]'));
            $this->assertCount(count((new ImportTemplateSchema())->columns()), $data->query('//*[local-name()="row"][@r="1"]/*[local-name()="c"]'));
            $this->assertCount(1, $data->query('//*[local-name()="pane"][@state="frozen"][@ySplit="1"]'));
            $styles = $this->xpath($zip->getFromName('xl/styles.xml'));
            $this->assertGreaterThan(0, $styles->query('//*[local-name()="cellXfs"]/*[local-name()="xf"][@numFmtId="49"]')->length);
            $this->assertStringContainsString('SPECIES_TEST', $zip->getFromName('xl/worksheets/sheet3.xml'));
            $this->assertStringContainsString('under_observation', $zip->getFromName('xl/worksheets/sheet3.xml'));
            $this->assertStringContainsString('=1+1', $zip->getFromName('xl/worksheets/sheet3.xml'));
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '.xml')) {
                    $this->assertCount(0, $this->xpath($zip->getFromIndex($index))->query('//*[local-name()="f"]'));
                }
            }
        } finally {
            $zip->close();
        }
    }

    public function test_every_prepared_data_cell_resolves_to_text_format_in_the_saved_workbook(): void
    {
        config(['imports.max_rows' => 5]);
        $path = $this->path();
        $catalogs = Mockery::mock(ImportCatalogRepository::class);
        $catalogs->shouldReceive('templateOptions')->once()->andReturn([]);
        $schema = new ImportTemplateSchema();
        (new ImportTemplateService($schema, $catalogs))->write($path);
        $this->assertSame([], app(ImportWorkbookReader::class)->read($path));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        try {
            $styles = $this->xpath($zip->getFromName('xl/styles.xml'));
            $formats = $styles->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]');
            $data = $this->xpath($zip->getFromName('xl/worksheets/sheet1.xml'));
            for ($number = 2; $number <= 6; $number++) {
                $cells = $data->query('//*[local-name()="row"][@r="'.$number.'"]/*[local-name()="c"]');
                $this->assertCount(count($schema->headers()), $cells);
                foreach ($cells as $cell) {
                    $format = $formats->item((int) $cell->getAttribute('s'));
                    $this->assertSame('49', $format?->getAttribute('numFmtId'), 'La celda '.$cell->getAttribute('r').' debe conservar Texto al editarla.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    public function test_template_removes_private_workspace_after_catalog_failure(): void
    {
        config(['imports.max_rows' => 1]);
        $path = $this->path();
        $catalogs = Mockery::mock(ImportCatalogRepository::class);
        $catalogs->shouldReceive('templateOptions')->once()->andReturnUsing(function () use ($path): never {
            $this->assertSame(0700, fileperms($path.'.work') & 0777);
            throw new RuntimeException('Fallo de catálogo controlado');
        });
        try {
            (new ImportTemplateService(new ImportTemplateSchema(), $catalogs))->write($path);
            $this->fail('Debe propagarse el fallo para impedir una descarga parcial.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de catálogo controlado', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($path.'.work');
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sysforest-template-test-');
        $this->paths[] = $path;

        return $path;
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));

        return new DOMXPath($document);
    }
}

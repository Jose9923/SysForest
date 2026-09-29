<?php

declare(strict_types=1);

namespace Tests;

use App\Imports\ImportTemplateSchema;
use App\Services\ImportTemplateService;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/** Construye la plantilla real y solo llena Datos; todos los fixtures viven en la base transaccional de pruebas. */
abstract class ImportTestCase extends InspectionTestCase
{
    private array $workbooks = [];

    protected function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sysforest-import-test-');
        $this->workbooks[] = $path;
        app(ImportTemplateService::class)->write($path);
        $schema = app(ImportTemplateSchema::class);
        $headers = $schema->headers();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        array_unshift($rows, array_combine($headers, $headers));
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $xml .= '<row r="'.$number.'">';
            foreach ($headers as $index => $key) {
                if (!array_key_exists($key, $row) || $row[$key] === null) {
                    continue;
                }
                $column = '';
                for ($value = $index + 1; $value > 0; $value = intdiv($value - 1, 26)) {
                    $column = chr(65 + ($value - 1) % 26).$column;
                }
                $xml .= '<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'
                    .htmlspecialchars((string) $row[$key], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return new UploadedFile($path, 'plantilla-oficial.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function importInput(array $overrides = []): array
    {
        return array_replace(['current_status' => 'active', 'inspection_date' => '2026-09-12',
            'external_number' => '000015', 'cap_cm' => '154', 'number_of_stems' => '3',
            'form_factor' => '0.5', 'total_height_m' => '12', 'commercial_height_m' => '0.8',
            'coordinate_raw' => '4.154, -73.633', 'general_observations' => 'Observación de prueba.'], $overrides);
    }

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }
}

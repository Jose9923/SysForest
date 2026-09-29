<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Imports\ImportTemplateSchema;
use RuntimeException;
use ZipArchive;

/** Fábrica OOXML mínima: permite probar contratos y ataques sin catálogos ni datos operativos. */
final class ImportWorkbookFactory
{
    public static function write(string $path, array $rows = [], array $overrides = []): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el libro de prueba.');
        }
        foreach (array_replace(self::entries($rows), $overrides) as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
    }

    public static function entries(array $rows = []): array
    {
        $schema = new ImportTemplateSchema();
        $data = self::row(1, array_combine($schema->headers(), $schema->headers()));
        foreach ($rows as $number => $values) {
            $data .= self::row(is_int($number) && $number >= 2 ? $number : $number + 2, $values);
        }
        $workbook = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $relationships = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $entries = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        ];
        foreach (['Datos', 'Diccionario', 'Catalogos', 'Instrucciones'] as $index => $name) {
            $id = $index + 1;
            $workbook .= '<sheet name="'.$name.'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $relationships .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $contents = $name === 'Datos' ? $data : '';
            if ($name === 'Instrucciones') {
                $contents = '<row r="1">'.self::cell('A1', 'Versión').self::cell('B1', ImportTemplateSchema::VERSION).'</row>';
            }
            $entries['xl/worksheets/sheet'.$id.'.xml'] = self::sheet($contents);
        }
        $entries['xl/workbook.xml'] = $workbook.'</sheets></workbook>';
        $entries['xl/_rels/workbook.xml.rels'] = $relationships.'</Relationships>';

        return $entries;
    }

    public static function row(int $number, array $values): string
    {
        $headers = (new ImportTemplateSchema())->headers();
        $cells = [];
        foreach ($values as $key => $value) {
            $index = array_search($key, $headers, true);
            if ($index === false) {
                $index = count($headers);
            }
            $cells[$index] = self::cell(self::letters($index).$number, $value);
        }
        ksort($cells);

        return '<row r="'.$number.'">'.implode('', $cells).'</row>';
    }

    public static function sheet(string $rows): string
    {
        return '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    public static function cell(string $reference, mixed $value): string
    {
        if (is_array($value)) {
            return '<c r="'.$reference.'" t="'.($value['type'] ?? 'n').'">'.(isset($value['formula']) ? '<f>'.htmlspecialchars($value['formula'], ENT_XML1).'</f>' : '').'<v>'.htmlspecialchars((string) ($value['value'] ?? ''), ENT_XML1).'</v></c>';
        }

        return '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
    }

    public static function letters(int $index): string
    {
        $result = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $result = chr(65 + (($index - 1) % 26)).$result;
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Imports;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Generator;
use Illuminate\Validation\ValidationException;
use XMLReader;

/** Lee valores OOXML como cadenas: no pierde BIGINT/NUMERIC ni evalúa fórmulas almacenadas. */
final class ImportWorkbookReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const LEGACY_SHEETS = ['Datos', 'Diccionario', 'Catalogos', 'Instrucciones'];

    public function __construct(private ImportTemplateSchema $schema, private XlsxPackageGuard $guard)
    {
    }

    /** @return list<array{row_number:int,values:array<string,?string>,errors:array<string,list<string>>}> */
    public function read(string $path): array
    {
        $entries = $this->guard->inspect($path);
        $sheets = $this->sheets($entries);
        $strings = isset($entries['xl/sharedStrings.xml']) ? $this->sharedStrings($entries['xl/sharedStrings.xml']) : [];
        $instructions = iterator_to_array($this->rows($entries[$sheets['Instrucciones']], $strings, 100), false);
        $version = $instructions[0]['cells'][1]['value'] ?? null;
        if (($instructions[0]['number'] ?? null) !== 1 || ($instructions[0]['cells'][0]['value'] ?? null) !== 'Versión'
            || !in_array($version, [ImportTemplateSchema::VERSION, ImportTemplateSchema::LEGACY_VERSION], true)) {
            $this->fail('La versión de la plantilla no es compatible. Descargue la plantilla oficial vigente.');
        }
        $legacy = $version === ImportTemplateSchema::LEGACY_VERSION;
        if (array_keys($sheets) !== ($legacy ? self::LEGACY_SHEETS : $this->schema->sheetNames())) {
            $this->fail('Las hojas del libro no corresponden a la versión declarada. Descargue la plantilla oficial y conserve su estructura.');
        }
        foreach (array_keys($sheets) as $name) {
            if ($name === 'Datos') {
                continue;
            }
            foreach ($this->rows($entries[$sheets[$name]], $strings, 100000) as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['error'] !== null) {
                        $this->fail('Las hojas auxiliares deben conservar valores de texto, sin fórmulas ni errores.');
                    }
                }
            }
        }
        $result = [];
        $keys = $this->schema->headers();
        $headers = $legacy ? $keys : $this->schema->templateHeaders();
        $columns = $this->schema->columns();
        $headerFound = false;
        foreach ($this->rows($entries[$sheets['Datos']], $strings, 10000) as $row) {
            if (!$headerFound) {
                $actual = array_map(static fn (array $cell) => $cell['error'] === null ? $cell['value'] : null, $row['cells']);
                if ($row['number'] !== 1 || array_keys($actual) !== range(0, count($headers) - 1) || array_values($actual) !== $headers) {
                    $this->fail('Los encabezados de Datos deben coincidir exactamente con la plantilla, incluido su orden.');
                }
                $headerFound = true;
                continue;
            }
            $hasData = false;
            foreach ($row['cells'] as $cell) {
                $hasData = $hasData || $cell['value'] !== '' || $cell['error'] !== null;
            }
            if (!$hasData) {
                continue;
            }
            if (count($result) >= (int) config('imports.max_rows', 100)) {
                $this->fail('El libro supera el máximo de '.config('imports.max_rows', 100).' filas con datos. No se importó ninguna fila.');
            }
            $values = array_fill_keys($keys, null);
            $errors = [];
            foreach ($row['cells'] as $index => $cell) {
                if (!isset($keys[$index])) {
                    if ($cell['value'] !== '' || $cell['error'] !== null) {
                        $errors['_row'][] = 'La fila contiene datos fuera de las columnas oficiales.';
                    }
                    continue;
                }
                $key = $keys[$index];
                $values[$key] = $cell['value'] === '' ? null : $cell['value'];
                if ($cell['error'] !== null) {
                    $errors[$key][] = $cell['error'];
                }
                if ($cell['value'] !== '' && $columns[$key]['type'] === 'date' && $cell['type'] === 'n') {
                    $errors[$key][] = 'Conserve la fecha como texto AAAA-MM-DD; no se aceptan fechas seriales de Excel.';
                }
            }
            $result[] = ['row_number' => $row['number'], 'values' => $values, 'errors' => $errors];
        }
        if (!$headerFound) {
            $this->fail('La hoja Datos no contiene los encabezados oficiales.');
        }

        return $result;
    }

    private function sheets(array $entries): array
    {
        $relationships = $this->xml($entries['xl/_rels/workbook.xml.rels']);
        $targets = [];
        foreach ($relationships->getElementsByTagName('Relationship') as $relation) {
            $id = $relation->getAttribute('Id');
            if (isset($targets[$id])) {
                $this->fail('El libro repite identificadores de relación.');
            }
            $target = $relation->getAttribute('Target');
            if (str_contains($target, '..') || str_contains($target, '\\')) {
                $this->fail('El libro contiene una relación interna no permitida.');
            }
            $targets[$id] = ['path' => str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target, 'type' => $relation->getAttribute('Type')];
        }
        $document = $this->xml($entries['xl/workbook.xml']);
        if ($document->documentElement?->localName !== 'workbook' || $document->documentElement?->namespaceURI !== self::NS) {
            $this->fail('El libro no utiliza el formato OOXML oficial.');
        }
        $sheets = [];
        $officialSheets = $this->schema->sheetNames();
        $allowedSheets = array_unique([...self::LEGACY_SHEETS, ...$officialSheets]);
        foreach ($document->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $target = $targets[$sheet->getAttributeNS(self::REL_NS, 'id')] ?? null;
            if (!in_array($name, $allowedSheets, true) || isset($sheets[$name]) || $target === null
                || !str_ends_with($target['type'], '/worksheet') || !isset($entries[$target['path']])
                || in_array($target['path'], $sheets, true) || !in_array($sheet->getAttribute('state'), ['', 'visible'], true)) {
                $this->fail('El libro debe contener exactamente las hojas visibles de una versión oficial de la plantilla.');
            }
            $sheets[$name] = $target['path'];
        }
        if (array_keys($sheets) !== self::LEGACY_SHEETS && array_keys($sheets) !== $officialSheets) {
            $this->fail('No cambie los nombres ni el orden de las hojas oficiales.');
        }
        $worksheetPaths = array_keys(array_filter($entries, static fn (string $bytes, string $name) => str_starts_with($name, 'xl/worksheets/'), ARRAY_FILTER_USE_BOTH));
        if (count($worksheetPaths) !== count($sheets)) {
            $this->fail('El libro contiene hojas adicionales fuera del contrato oficial.');
        }

        return $sheets;
    }

    /** Se limita el XML de una fila antes de construir su DOM; no se materializa la hoja completa. */
    private function rows(string $bytes, array $strings, int $limit): Generator
    {
        $reader = new XMLReader();
        $reader->XML($bytes, null, LIBXML_NONET | LIBXML_COMPACT);
        $last = 0;
        $count = 0;
        $root = false;
        $sheetData = false;
        $parents = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if (in_array($reader->localName, ['worksheet', 'sheetData', 'row', 'c', 'f', 'v', 'is', 't'], true)
                    && $reader->namespaceURI !== self::NS) {
                    $this->fail('La hoja contiene elementos de datos con un espacio de nombres no admitido.');
                }
                if ($reader->depth === 2 && ($parents[1] ?? '') === 'sheetData' && $reader->localName !== 'row') {
                    $this->fail('El bloque de datos solo puede contener filas oficiales.');
                }
                $parents[$reader->depth] = $reader->localName;
                if ($reader->depth === 0) {
                    $root = $reader->localName === 'worksheet' && $reader->namespaceURI === self::NS;
                    if (!$root) {
                        $this->fail('Una hoja del libro no tiene el formato requerido.');
                    }
                }
                if (in_array($reader->localName, ['mergeCells', 'drawing', 'legacyDrawing', 'oleObjects', 'hyperlinks'], true)) {
                    $this->fail('No se admiten celdas combinadas, enlaces, imágenes ni objetos en las hojas.');
                }
                if ($reader->localName === 'sheetData') {
                    if ($reader->depth !== 1 || $sheetData || $reader->namespaceURI !== self::NS) {
                        $this->fail('La hoja repite o desplaza el bloque oficial de datos.');
                    }
                    $sheetData = true;
                }
                if ($reader->localName === 'c' && ($reader->depth !== 3 || ($parents[2] ?? '') !== 'row' || ($parents[1] ?? '') !== 'sheetData')) {
                    $this->fail('La hoja contiene celdas fuera de sus filas.');
                }
                if ($reader->localName === 'f' && ($reader->depth !== 4 || ($parents[3] ?? '') !== 'c')) {
                    $this->fail('La hoja contiene fórmulas fuera de sus celdas.');
                }
                if ($reader->localName !== 'row' || $reader->namespaceURI !== self::NS) {
                    continue;
                }
                if (!$sheetData || $reader->depth !== 2 || ($parents[1] ?? '') !== 'sheetData') {
                    $this->fail('La hoja contiene filas fuera del bloque de datos.');
                }
                $number = $reader->getAttribute('r') ?? '';
                if (!preg_match('/\A[1-9][0-9]{0,6}\z/D', $number) || (int) $number <= $last || (int) $number > 1048576 || ++$count > $limit) {
                    $this->fail('La numeración o cantidad física de filas no es válida.');
                }
                $last = (int) $number;
                $rowXml = $reader->readOuterXml();
                if (strlen($rowXml) > 4194304) {
                    $this->fail('Una fila excede el tamaño de lectura permitido.');
                }
                $row = $this->xml($rowXml);
                $cells = [];
                foreach ($row->documentElement->childNodes as $node) {
                    if (!$node instanceof DOMElement) {
                        if (trim($node->textContent) !== '') {
                            $this->fail('La fila contiene texto fuera de sus celdas oficiales.');
                        }
                        continue;
                    }
                    if ($node->localName !== 'c' || $node->namespaceURI !== self::NS
                        || !preg_match('/\A([A-Z]{1,3})([1-9][0-9]{0,6})\z/D', $node->getAttribute('r'), $match)
                        || (int) $match[2] !== $last) {
                        $this->fail('Una fila contiene referencias de celda inválidas.');
                    }
                    $index = $this->columnIndex($match[1]);
                    if ($index >= 16384 || isset($cells[$index])) {
                        $this->fail('La fila repite una celda o excede el número de columnas permitido.');
                    }
                    $cells[$index] = $this->cell($node, $strings);
                }
                ksort($cells);
                yield ['number' => $last, 'cells' => $cells];
            }
            if (!$root) {
                $this->fail('Una hoja del libro está vacía o es inválida.');
            }
        } finally {
            $reader->close();
        }
    }

    private function cell(DOMElement $cell, array $strings): array
    {
        $type = $cell->getAttribute('t') ?: 'n';
        $valueNodes = $cell->getElementsByTagNameNS(self::NS, 'v');
        $inlineNodes = $cell->getElementsByTagNameNS(self::NS, 'is');
        $value = $valueNodes->item(0)?->textContent ?? '';
        $error = null;
        foreach ($cell->childNodes as $node) {
            if ($node instanceof DOMElement && ($node->namespaceURI !== self::NS || !in_array($node->localName, ['v', 'f', 'is'], true))) {
                $error = 'La celda contiene una estructura no admitida.';
            } elseif (!$node instanceof DOMElement && trim($node->textContent) !== '') {
                $error = 'La celda contiene texto fuera de su valor oficial.';
            }
        }
        if ($cell->getElementsByTagNameNS(self::NS, 'f')->length > 0) {
            return ['value' => '', 'type' => $type, 'error' => 'No se admiten fórmulas. Escriba el valor observado como texto; los derivados se calculan en SYSFOREST.'];
        }
        if ($type === 'inlineStr') {
            // Un editor puede guardar un blanco con estilo sin crear el bloque <is>.
            if (!$cell->hasChildNodes()) {
                return ['value' => '', 'type' => $type, 'error' => null];
            }
            if ($inlineNodes->length !== 1 || $valueNodes->length !== 0 || !$this->validRichText($inlineNodes->item(0))) {
                return ['value' => '', 'type' => $type, 'error' => 'La celda de texto debe contener un único bloque de texto, sin valores duplicados.'];
            }
            $value = $this->text($cell);
        } elseif ($type === 's') {
            if ($valueNodes->length !== 1 || $inlineNodes->length !== 0) {
                return ['value' => '', 'type' => $type, 'error' => 'La celda compartida debe contener una única referencia, sin texto inline.'];
            }
            if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || !array_key_exists((int) $value, $strings)) {
                $error = 'La referencia de texto compartido no existe.';
                $value = '';
            } else {
                $value = $strings[(int) $value];
            }
        } elseif ($type === 'e' || !in_array($type, ['n', 'b', 'str', 'd'], true) || $valueNodes->length > 1 || $inlineNodes->length !== 0) {
            $error = 'La celda contiene un error de Excel o un tipo no admitido.';
        }
        if ($type === 'b' && !in_array($value, ['0', '1'], true)) {
            $error = 'Una celda booleana solo admite 0 o 1.';
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) || !mb_check_encoding($value, 'UTF-8')) {
            $error = 'La celda contiene caracteres de control o Unicode inválido.';
            $value = '';
        }
        if (mb_strlen($value, 'UTF-8') > (int) config('imports.max_cell_chars', 32767)) {
            $error = 'La celda supera el límite de caracteres permitido.';
            $value = '';
        }

        return compact('value', 'type', 'error');
    }

    private function sharedStrings(string $bytes): array
    {
        $reader = new XMLReader();
        $reader->XML($bytes, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $root = false;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->depth === 0) {
                    $root = $reader->localName === 'sst' && $reader->namespaceURI === self::NS;
                    if (!$root) {
                        $this->fail('El índice de textos compartidos no tiene el formato oficial.');
                    }
                }
                if ($reader->localName === 'si') {
                    if ($reader->depth !== 1 || $reader->namespaceURI !== self::NS) {
                        $this->fail('El índice de textos compartidos contiene elementos ambiguos.');
                    }
                    if (count($strings) >= 100000) {
                        $this->fail('El libro excede el límite de textos compartidos.');
                    }
                    $document = $this->xml($reader->readOuterXml());
                    if (!$this->validRichText($document->documentElement)) {
                        $this->fail('Un texto compartido contiene valores duplicados o una estructura no admitida.');
                    }
                    $value = $this->text($document->documentElement);
                    if (mb_strlen($value, 'UTF-8') > (int) config('imports.max_cell_chars', 32767)) {
                        $this->fail('Un texto compartido supera el límite de caracteres permitido.');
                    }
                    $strings[] = $value;
                }
            }
            if (!$root) {
                $this->fail('El índice de textos compartidos está vacío o es inválido.');
            }

            return $strings;
        } finally {
            $reader->close();
        }
    }

    /** Un texto simple y una secuencia rica son alternativas; no se concatenan estructuras inválidas. */
    private function validRichText(DOMElement $element): bool
    {
        $plain = 0;
        $runs = 0;
        foreach ($element->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                if (trim($node->textContent) !== '') {
                    return false;
                }
                continue;
            }
            if ($node->namespaceURI !== self::NS || !in_array($node->localName, ['t', 'r', 'rPh', 'phoneticPr'], true)) {
                return false;
            }
            if ($node->localName === 't') {
                $plain++;
                if ($node->getElementsByTagName('*')->length !== 0) {
                    return false;
                }
            }
            if ($node->localName === 'r') {
                $runs++;
                $textNodes = 0;
                $properties = 0;
                foreach ($node->childNodes as $part) {
                    if (!$part instanceof DOMElement) {
                        if (trim($part->textContent) !== '') {
                            return false;
                        }
                        continue;
                    }
                    if ($part->namespaceURI !== self::NS || !in_array($part->localName, ['t', 'rPr'], true)) {
                        return false;
                    }
                    if ($part->localName === 't') {
                        $textNodes++;
                        if ($part->getElementsByTagName('*')->length !== 0) {
                            return false;
                        }
                    } else {
                        $properties++;
                        if (trim($part->textContent) !== '') {
                            return false;
                        }
                        foreach ($part->getElementsByTagName('*') as $property) {
                            if (in_array($property->localName, ['t', 'r', 'is', 'v', 'f', 'si', 'c', 'row'], true)) {
                                return false;
                            }
                        }
                    }
                }
                if ($textNodes !== 1 || $properties > 1) {
                    return false;
                }
            }
        }

        return $plain <= 1 && !($plain > 0 && $runs > 0);
    }

    private function text(DOMElement $element): string
    {
        $xpath = new DOMXPath($element->ownerDocument);
        $xpath->registerNamespace('s', self::NS);
        $text = '';
        foreach ($xpath->query('./s:t | ./s:r/s:t | ./s:is/s:t | ./s:is/s:r/s:t', $element) as $node) {
            $text .= $node->textContent;
        }

        // Secuencia de escape definida por OOXML; _x005F_ preserva el guion bajo literal siguiente.
        return preg_replace_callback('/(?:_x[0-9A-Fa-f]{4}_)+/', static function (array $match): string {
            preg_match_all('/_x([0-9A-Fa-f]{4})_/', $match[0], $parts);
            $bytes = implode('', array_map(static fn (string $hex) => pack('n', hexdec($hex)), $parts[1]));
            if (!mb_check_encoding($bytes, 'UTF-16BE')) {
                return "\0";
            }

            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }, $text);
    }

    private function xml(string $bytes): DOMDocument
    {
        $document = new DOMDocument();
        if (!$document->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT)) {
            $this->fail('El libro contiene XML inválido.');
        }

        return $document;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

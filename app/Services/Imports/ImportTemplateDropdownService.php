<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Imports\ImportTemplateSchema;
use DOMDocument;
use DOMElement;
use OpenSpout\Writer\Common\Helper\CellHelper;
use RuntimeException;
use ZipArchive;

/** Agrega ayudas nativas de Excel con referencias internas fijas, sin fórmulas de celda ni macros. */
final class ImportTemplateDropdownService
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function __construct(private ImportTemplateSchema $schema)
    {
    }

    /** @param array<string, int> $catalogCounts Cantidad de opciones escritas en cada catálogo. */
    public function apply(string $path, array $catalogCounts): void
    {
        $zip = new ZipArchive();
        if (!is_file($path) || is_link($path) || $zip->open($path) !== true) {
            throw new RuntimeException('No fue posible preparar las listas de la plantilla.');
        }
        try {
            $workbook = $this->document($zip, 'xl/workbook.xml', 'workbook');
            $data = $this->document($zip, 'xl/worksheets/sheet1.xml', 'worksheet');
            $sheets = $workbook->getElementsByTagNameNS(self::NS, 'sheets')->item(0);
            if (!$sheets instanceof DOMElement || $workbook->getElementsByTagNameNS(self::NS, 'definedNames')->length !== 0
                || $data->getElementsByTagNameNS(self::NS, 'dataValidations')->length !== 0) {
                throw new RuntimeException('La plantilla contiene una estructura de listas no prevista.');
            }
            $sheetNames = [];
            foreach ($sheets->childNodes as $sheet) {
                if ($sheet instanceof DOMElement) {
                    $sheetNames[] = $sheet->getAttribute('name');
                }
            }
            if ($sheetNames !== $this->schema->sheetNames()) {
                throw new RuntimeException('Las listas requieren las hojas oficiales de la plantilla.');
            }
            $names = $workbook->createElementNS(self::NS, 'definedNames');
            $validations = $data->createElementNS(self::NS, 'dataValidations');
            $catalogSheets = $this->schema->catalogSheets();
            $columns = $this->schema->columns();
            $lastRow = 1 + (int) config('imports.max_rows', 100);
            foreach (array_keys($columns) as $index => $field) {
                $column = $columns[$field];
                if ($column['mode'] !== 'input') {
                    continue;
                }
                $catalog = in_array($column['type'], ['catalog', 'catalog_list'], true);
                if ($catalog) {
                    $count = $catalogCounts[$field] ?? 0;
                    if ($count === 0) {
                        continue;
                    }
                    if ($count < 0 || $count > 1048575 || !isset($catalogSheets[$field])) {
                        throw new RuntimeException('El tamaño de un catálogo no permite crear su lista.');
                    }
                    $name = 'sf_'.$field;
                    $definedName = $workbook->createElementNS(self::NS, 'definedName');
                    $definedName->setAttribute('name', $name);
                    $reference = "'".str_replace("'", "''", $catalogSheets[$field])."'!\$E\$2:\$E\$".($count + 1);
                    $definedName->appendChild($workbook->createTextNode($reference));
                    $names->appendChild($definedName);
                    $formula = $name;
                    $prompt = $field === 'fauna_category_codes'
                        ? 'La lista elige una categoría y reemplaza el contenido. Para varias, escriba sus códigos separados por |.'
                        : 'Elija una opción de la lista o escriba un código vigente del catálogo.';
                } elseif ($column['type'] === 'boolean') {
                    $formula = '"0,1"';
                    $prompt = str_starts_with($field, 'condition_')
                        ? 'Elija 1 para seleccionar; 0 o vacío no seleccionan. Un blanco no acredita ausencia examinada.'
                        : 'Elija 1 para sí o 0 para no. Si deja el campo vacío, se considera 0.';
                } else {
                    continue;
                }
                $letters = CellHelper::getColumnLettersFromColumnIndex($index);
                $validation = $data->createElementNS(self::NS, 'dataValidation');
                foreach ([
                    'type' => 'list', 'allowBlank' => '1', 'showDropDown' => '0', 'showInputMessage' => '1',
                    'showErrorMessage' => $catalog ? '0' : '1', 'errorStyle' => 'stop',
                    'promptTitle' => $catalog ? 'Opciones del catálogo' : 'Seleccione 0 o 1',
                    'prompt' => $prompt, 'errorTitle' => 'Valor no permitido',
                    'error' => 'Seleccione 0 o 1, o deje la celda vacía.', 'sqref' => $letters.'2:'.$letters.$lastRow,
                ] as $attribute => $value) {
                    $validation->setAttribute($attribute, $value);
                }
                $formulaNode = $data->createElementNS(self::NS, 'formula1');
                $formulaNode->appendChild($data->createTextNode($formula));
                $validation->appendChild($formulaNode);
                $validations->appendChild($validation);
            }
            if ($names->hasChildNodes()) {
                $sheets->parentNode->insertBefore($names, $sheets->nextSibling);
            }
            if ($validations->hasChildNodes()) {
                $validations->setAttribute('count', (string) $validations->childNodes->length);
                // OOXML sitúa las validaciones antes de enlaces y opciones de impresión.
                $afterValidation = ['hyperlinks', 'printOptions', 'pageMargins', 'pageSetup', 'headerFooter',
                    'rowBreaks', 'colBreaks', 'customProperties', 'cellWatches', 'ignoredErrors', 'smartTags',
                    'drawing', 'legacyDrawing', 'legacyDrawingHF', 'drawingHF', 'picture', 'oleObjects',
                    'controls', 'webPublishItems', 'tableParts', 'extLst'];
                $before = null;
                foreach ($data->documentElement->childNodes as $child) {
                    if ($child instanceof DOMElement && in_array($child->localName, $afterValidation, true)) {
                        $before = $child;
                        break;
                    }
                }
                $data->documentElement->insertBefore($validations, $before);
            }
            if (!$zip->addFromString('xl/workbook.xml', $workbook->saveXML())
                || !$zip->addFromString('xl/worksheets/sheet1.xml', $data->saveXML())) {
                throw new RuntimeException('No fue posible guardar las listas de la plantilla.');
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('No fue posible finalizar las listas de la plantilla.');
            }
        }
    }

    private function document(ZipArchive $zip, string $path, string $root): DOMDocument
    {
        $bytes = $zip->getFromName($path);
        $document = new DOMDocument();
        if ($bytes === false || !$document->loadXML($bytes, LIBXML_NONET)
            || $document->documentElement?->namespaceURI !== self::NS || $document->documentElement?->localName !== $root) {
            throw new RuntimeException('Las listas requieren un libro de Excel válido.');
        }

        return $document;
    }
}

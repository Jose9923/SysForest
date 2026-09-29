<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreeStatus;
use App\Imports\ImportTemplateSchema;
use App\Repositories\ImportCatalogRepository;
use DOMDocument;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/** Produce una plantilla vacía; los catálogos son ayudas y se revalidan al cargar y confirmar. */
final class ImportTemplateService
{
    public function __construct(private ImportTemplateSchema $schema, private ImportCatalogRepository $catalogs)
    {
    }

    public function write(string $path): void
    {
        $workspace = $path.'.work';
        if (!is_file($path) || is_link($path) || realpath(dirname($path)) !== dirname($path)
            || file_exists($workspace) || is_link($workspace) || !mkdir($workspace, 0700)) {
            throw new RuntimeException('No fue posible preparar el espacio privado de la plantilla.');
        }
        $text = new Style(format: '@');
        $wrapped = new Style(shouldWrapText: true, format: '@');
        $reserved = new Style(fontColor: '65736C', backgroundColor: 'EDF0EE', format: '@');
        $header = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '246447', shouldWrapText: true, format: '@');
        $writer = new Writer(new Options(
            // OpenSpout exporta el estilo cero como General: Texto debe registrarse por separado.
            FALLBACK_STYLE: new Style(),
            DEFAULT_COLUMN_WIDTH: 24,
            tempFolder: $workspace,
            properties: new Properties(title: 'SYSFOREST · Plantilla oficial de importación', application: 'SYSFOREST', creator: 'SYSFOREST', language: 'es'),
        ));
        try {
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet()->setName('Datos')->setSheetView(new SheetView(freezeRow: 2, freezeColumn: 'C'));
            $writer->addRow($this->row($this->schema->headers(), $header, 64));
            $emptyCells = [];
            foreach (array_values($this->schema->columns()) as $index => $column) {
                $sheet->setColumnWidth($column['group'] === 'Condiciones' ? 30 : 25, $index + 1);
                $emptyCells[] = new StringCell('', $column['mode'] === 'input' ? $text : $reserved);
            }
            for ($row = 0; $row < (int) config('imports.max_rows', 100); $row++) {
                $writer->addRow(new Row($emptyCells));
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Diccionario')->setSheetView(new SheetView(freezeRow: 2));
            $sheet->setColumnWidth(34, 1, 2);
            $sheet->setColumnWidth(20, 3, 4, 5, 6, 7);
            $sheet->setColumnWidth(90, 8);
            $writer->addRow($this->row(['Columna técnica', 'Etiqueta', 'Grupo', 'Modo', 'Tipo', 'Obligatorio', 'Fuente original', 'Instrucción'], $header, 32));
            foreach ($this->schema->columns() as $key => $column) {
                $writer->addRow($this->row([$key, $column['label'], $column['group'], $column['mode'], $column['type'],
                    $column['required'] ? 'Sí' : 'No', $column['source'] ?? '', $column['description']], $wrapped, 52));
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Catalogos')->setSheetView(new SheetView(freezeRow: 2));
            $sheet->setColumnWidth(28, 1);
            $sheet->setColumnWidth(60, 2);
            $sheet->setColumnWidth(45, 3);
            $sheet->setColumnWidth(85, 4);
            $writer->addRow($this->row(['Catálogo', 'Código', 'Nombre', 'Detalle'], $header, 32));
            foreach (TreeStatus::cases() as $status) {
                $writer->addRow($this->row(['current_status', $status->value, $status->label(), 'Estado actual del individuo.'], $text, 24));
            }
            foreach ($this->catalogs->templateOptions() as $option) {
                $writer->addRow($this->row([$option['catalog'], $option['code'], $option['label'], $option['details']], $wrapped, 48));
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
            $sheet->setColumnWidth(34, 1);
            $sheet->setColumnWidth(125, 2);
            $writer->addRow($this->row(['Versión', ImportTemplateSchema::VERSION], $header, 36));
            foreach ($this->instructions() as $label => $description) {
                $writer->addRow($this->row([$label, $description], $wrapped, 56));
            }
        } finally {
            try {
                $writer->close();
            } finally {
                if (is_dir($workspace) && !is_link($workspace) && scandir($workspace) === ['.', '..']) {
                    rmdir($workspace);
                }
            }
        }
        $this->removeEmptyAnnotations($path);
    }

    /** OpenSpout emite VML y comentarios vacíos por hoja; retirarlos evita introducir objetos en el contrato. */
    private function removeEmptyAnnotations(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No fue posible finalizar la plantilla.');
        }
        try {
            $remove = [];
            $replace = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!preg_match('~\A(?:xl/comments[0-9]+\.xml|xl/drawings/vmlDrawing[0-9]+\.vml|xl/worksheets/_rels/sheet[0-9]+\.xml\.rels|xl/worksheets/sheet[0-9]+\.xml|\[Content_Types\]\.xml)\z~D', $name)) {
                    continue;
                }
                $document = new DOMDocument();
                if (!$document->loadXML($zip->getFromIndex($index), LIBXML_NONET)) {
                    throw new RuntimeException('La plantilla generada contiene XML inválido.');
                }
                if (str_starts_with($name, 'xl/comments') || str_starts_with($name, 'xl/drawings/')) {
                    if ($document->getElementsByTagName('comment')->length > 0 || $document->getElementsByTagName('shape')->length > 0) {
                        throw new RuntimeException('La plantilla no debe contener anotaciones ni objetos.');
                    }
                    $remove[] = $name;
                } elseif (str_contains($name, '/_rels/')) {
                    foreach ($document->getElementsByTagName('Relationship') as $relation) {
                        if (!preg_match('~/(?:comments|vmlDrawing)\z~D', $relation->getAttribute('Type'))) {
                            throw new RuntimeException('La plantilla generó una relación no prevista.');
                        }
                    }
                    $remove[] = $name;
                } else {
                    $nodes = [];
                    foreach ($document->getElementsByTagName('*') as $node) {
                        if ($node->localName === 'legacyDrawing'
                            || ($node->localName === 'Override' && str_starts_with($node->getAttribute('PartName'), '/xl/comments'))
                            || ($node->localName === 'Default' && $node->getAttribute('Extension') === 'vml')) {
                            $nodes[] = $node;
                        }
                    }
                    foreach ($nodes as $node) {
                        $node->parentNode->removeChild($node);
                    }
                    $replace[$name] = $document->saveXML();
                }
            }
            foreach ($remove as $name) {
                if (!$zip->deleteName($name)) {
                    throw new RuntimeException('No fue posible limpiar los componentes vacíos de Excel.');
                }
            }
            foreach ($replace as $name => $bytes) {
                if (!$zip->addFromString($name, $bytes)) {
                    throw new RuntimeException('No fue posible finalizar las hojas de la plantilla.');
                }
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('No fue posible guardar la plantilla oficial.');
            }
        }
    }

    private function row(array $values, Style $style, float $height): Row
    {
        return new Row(array_map(static fn (string $value) => new StringCell($value, $style), $values), height: $height);
    }

    private function instructions(): array
    {
        return [
            'Uso' => 'Complete solo Datos desde la fila 2. Cada fila crea un individuo nuevo y una inspección en el inventario elegido en la pantalla. No se actualizan individuos existentes.',
            'Estructura' => 'No cambie encabezados ni su orden, nombres de hojas ni la versión. No agregue hojas, fórmulas, macros, enlaces externos, imágenes u objetos.',
            'Organización e inventario' => 'Se seleccionan en SYSFOREST. No añada identificadores institucionales al archivo. Los registros se crean privados.',
            'Códigos' => 'code vacío genera código. Un código informado debe respetar el prefijo actual de la organización y un sufijo canónico de al menos seis dígitos; cualquier colisión bloquea el lote. external_number conserva el número legado y no determina coincidencias.',
            'Catálogos' => 'Copie los códigos de Catalogos, no las etiquetas. Los códigos se revalidan frente al catálogo vigente. fauna_category_codes admite varios códigos separados por |.',
            'Fechas y números' => 'Conserve formato Texto. Fechas AAAA-MM-DD. Decimales con punto, sin separadores de miles ni notación científica. Se conservan valores exactos; no se redondean entradas inválidas.',
            'Campos obligatorios' => 'current_status e inspection_date. Consulte Diccionario. La ausencia de mediciones no se reemplaza por cero.',
            'Columnas grises' => 'Deben quedar vacías. Nombre común, científico y familia se obtienen de species_code. Los derivados forestales y madurez se recalculan en backend. Las fotografías se gestionan en Evidencias después de importar.',
            'Condiciones' => 'Las 65 columnas condition_ aceptan 1, 0 o vacío. 1 selecciona; 0/vacío no seleccionan y no acreditan una ausencia examinada. Se conserva cada órgano y tipo de evaluación.',
            'Coordenadas' => 'coordinate_raw acepta latitud, longitud decimal o DMS. Declare el datum cuando se conozca. La posición normalizada se guarda en PostGIS SRID 4326.',
            'Validación y confirmación' => 'Primero cargue y revise los errores por fila. La previsualización no crea árboles. Confirme solo un lote válido; la confirmación revalida permisos, inventario y catálogos y se ejecuta en una transacción completa.',
            'Límites' => 'Máximo '.config('imports.max_rows', 100).' filas con datos y '.config('imports.max_upload_kb', 5120).' KiB. No se truncan filas. Las filas totalmente vacías se omiten.',
            'Privacidad' => 'El archivo se utiliza para una operación institucional. No incluya contraseñas ni información ajena al inventario. Las URLs no se consultan ni descargan.',
        ];
    }
}

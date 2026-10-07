<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreeStatus;
use App\Imports\ImportCatalogSelection;
use App\Imports\ImportTemplateSchema;
use App\Repositories\ImportCatalogRepository;
use DOMDocument;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
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
    public function __construct(
        private ImportTemplateSchema $schema,
        private ImportCatalogRepository $catalogs,
        private ImportTemplateDropdownService $dropdowns,
    ) {
    }

    public function write(string $path): void
    {
        $workspace = $path.'.work';
        if (!is_file($path) || is_link($path) || realpath(dirname($path)) !== dirname($path)
            || file_exists($workspace) || is_link($workspace) || !mkdir($workspace, 0700)) {
            throw new RuntimeException('No fue posible preparar el espacio privado de la plantilla.');
        }
        $text = new Style(cellAlignment: CellAlignment::LEFT, cellVerticalAlignment: CellVerticalAlignment::CENTER, format: '@');
        $wrapped = new Style(cellAlignment: CellAlignment::LEFT, cellVerticalAlignment: CellVerticalAlignment::CENTER, shouldWrapText: true, format: '@');
        $alternate = new Style(backgroundColor: 'F2F7F4', cellAlignment: CellAlignment::LEFT, cellVerticalAlignment: CellVerticalAlignment::CENTER, shouldWrapText: true, format: '@');
        $reserved = new Style(fontColor: '65736C', backgroundColor: 'EDF0EE', cellAlignment: CellAlignment::LEFT, cellVerticalAlignment: CellVerticalAlignment::CENTER, format: '@');
        $reservedHeader = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '697970', cellAlignment: CellAlignment::LEFT, cellVerticalAlignment: CellVerticalAlignment::CENTER, shouldWrapText: true, format: '@');
        $header = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '246447', cellAlignment: CellAlignment::LEFT, cellVerticalAlignment: CellVerticalAlignment::CENTER, shouldWrapText: true, format: '@');
        $catalogCounts = [];
        $writer = new Writer(new Options(
            // OpenSpout exporta el estilo cero como General: Texto debe registrarse por separado.
            FALLBACK_STYLE: new Style(),
            DEFAULT_COLUMN_WIDTH: 24,
            tempFolder: $workspace,
            properties: new Properties(title: 'SYSFOREST · Plantilla oficial de importación en español', application: 'SYSFOREST', creator: 'SYSFOREST', language: 'es'),
        ));
        try {
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet()->setName('Datos')->setSheetView(new SheetView(freezeRow: 2, freezeColumn: 'C'));
            $headerCells = [];
            $emptyCells = [];
            foreach (array_keys($this->schema->columns()) as $index => $key) {
                $column = $this->schema->columns()[$key];
                $catalog = in_array($column['type'], ['catalog', 'catalog_list'], true);
                $sheet->setColumnWidth($key === 'species_code' ? 52 : ($catalog ? 40 : ($column['group'] === 'Condiciones' ? 32 : 27)), $index + 1);
                $headerCells[] = new StringCell($column['label'], $column['mode'] === 'input' ? $header : $reservedHeader);
                $emptyCells[] = new StringCell('', $column['mode'] === 'input' ? ($catalog ? $wrapped : $text) : $reserved);
            }
            $writer->addRow(new Row($headerCells, height: 72));
            for ($row = 0; $row < (int) config('imports.max_rows', 100); $row++) {
                $writer->addRow(new Row($emptyCells, height: 40));
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Guía de campos')->setSheetView(new SheetView(freezeRow: 2, freezeColumn: 'B'));
            $sheet->setColumnWidth(38, 1);
            $sheet->setColumnWidth(18, 2);
            $sheet->setColumnWidth(14, 3);
            $sheet->setColumnWidth(26, 4);
            $sheet->setColumnWidth(28, 5);
            $sheet->setColumnWidth(70, 6);
            $sheet->setColumnWidth(34, 7);
            $sheet->setColumnWidth(30, 8);
            $writer->addRow($this->row(['Campo en Datos', 'Grupo', 'Obligatorio', 'Cómo diligenciar', 'Formato de entrada', 'Explicación del campo', 'Ejemplo orientativo', 'Hoja de catálogo'], $header, 36));
            $catalogSheets = $this->schema->catalogSheets();
            $index = 0;
            foreach ($this->schema->columns() as $key => $column) {
                $catalog = str_starts_with($key, 'condition_') ? 'Condiciones' : ($catalogSheets[$key] ?? '');
                $mode = match ($column['mode']) {
                    'backend' => 'Dejar vacío · lo completa SYSFOREST',
                    'evidence' => 'Dejar vacío · cargar después en Evidencias',
                    default => 'Diligenciar en Datos',
                };
                $writer->addRow($this->row([
                    $column['label'], $column['group'], $column['required'] ? 'Sí' : 'No', $mode,
                    $this->formatLabel($column), $column['description'], $this->example($key, $column), $catalog,
                ], $index++ % 2 === 0 ? $wrapped : $alternate, 76));
            }

            $options = $this->catalogs->templateOptions();
            foreach ($catalogSheets as $field => $name) {
                $sheet = $writer->addNewSheetAndMakeItCurrent()->setName($name)->setSheetView(new SheetView(freezeRow: 2));
                $sheet->setColumnWidth(36, 1);
                $sheet->setColumnWidth(44, 2);
                $sheet->setColumnWidth(78, 3);
                $sheet->setColumnWidth(52, 4);
                $sheet->setColumnWidth(66, 5);
                $condition = $field === 'condition';
                $writer->addRow($this->row(
                    $condition
                        ? ['Código de referencia', 'Condición', 'Evaluación y órgano', 'Columna en Datos', 'Cómo diligenciar']
                        : ['Código para copiar', 'Nombre', 'Información adicional', 'Campo en Datos', 'Opción en lista'],
                    $header,
                    36,
                ));
                $index = 0;
                if ($field === 'current_status') {
                    foreach (TreeStatus::cases() as $status) {
                        $writer->addRow($this->row([$status->value, $status->label(), 'Seleccione la opción en Datos o copie el código de esta fila.', 'Estado actual', ImportCatalogSelection::label($status->value, $status->label())], $index++ % 2 === 0 ? $wrapped : $alternate, 48));
                    }
                    $catalogCounts[$field] = $index;
                    continue;
                }
                foreach ($options as $option) {
                    if ($option['catalog'] !== $field) {
                        continue;
                    }
                    if ($condition) {
                        $column = $this->schema->columns()['condition_'.$option['code']] ?? null;
                        $values = [$option['code'], $option['label'], $option['details'], $column['label'] ?? 'Sin columna en esta plantilla',
                            $column === null ? 'Referencia de catálogo; esta plantilla no permite importarla.' : 'En la columna indicada escriba 1 para seleccionar; 0 o vacío no seleccionan. No escriba el código de referencia.'];
                    } else {
                        $values = [$option['code'], $option['label'], $option['details'], $this->schema->columns()[$field]['label'], ImportCatalogSelection::label($option['code'], $option['label'])];
                    }
                    $writer->addRow($this->row($values, $index++ % 2 === 0 ? $wrapped : $alternate, $condition ? 72 : 60));
                }
                if ($index === 0) {
                    $writer->addRow($this->row(['', 'No hay opciones vigentes en este catálogo.', 'Solicite la revisión del catálogo antes de diligenciar este campo. No hay una lista desplegable disponible.', '', ''], $wrapped, 60));
                }
                if (!$condition) {
                    $catalogCounts[$field] = $index;
                }
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones')->setSheetView(new SheetView(freezeRow: 2));
            $sheet->setColumnWidth(34, 1);
            $sheet->setColumnWidth(125, 2);
            $writer->addRow($this->row(['Versión', ImportTemplateSchema::VERSION], $header, 36));
            $index = 0;
            foreach ($this->instructions() as $label => $description) {
                $writer->addRow($this->row([$label, $description], $index++ % 2 === 0 ? $wrapped : $alternate, 72));
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
        $this->dropdowns->apply($path, $catalogCounts);
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

    private function formatLabel(array $column): string
    {
        if ($column['mode'] !== 'input') {
            return 'Celda vacía, sin fórmulas';
        }

        return match ($column['type']) {
            'catalog' => 'Lista desplegable o código vigente',
            'catalog_list' => 'Lista para una categoría; códigos con | para varias',
            'boolean' => 'Lista: 1, 0 o vacío',
            'integer' => 'Número entero escrito como texto',
            'decimal' => 'Número decimal como texto, con punto',
            'date' => 'Texto: AAAA-MM-DD',
            'datetime' => 'Texto: fecha y hora con zona horaria',
            default => 'Texto',
        };
    }

    private function example(string $key, array $column): string
    {
        if ($column['mode'] !== 'input') {
            return 'Dejar vacío';
        }
        if ($column['type'] === 'catalog' || $column['type'] === 'catalog_list') {
            return $key === 'current_status' ? 'Sano [active]' : 'Elegir una opción de la lista en Datos';
        }
        if ($column['type'] === 'boolean') {
            return '1';
        }

        return match ($key) {
            'code' => 'Dejar vacío para generar el código',
            'external_number' => '000123',
            'coordinate_raw' => '4.154, -73.633',
            'number_of_stems' => '1',
            'form_factor' => '0.65',
            'cap_cm' => '120.5',
            'total_height_m' => '12.5',
            'commercial_height_m' => '8',
            'crown_x_m', 'crown_y_m' => '4.2',
            'accuracy_m' => '3.5',
            'inspection_date', 'flowering_date', 'fruiting_date' => '2026-01-31',
            'captured_at' => '2026-01-31T10:30:00-05:00',
            'datum' => 'WGS84 (solo si corresponde)',
            'location_description' => 'Costado norte del parque',
            default => 'Describir únicamente lo observado o conocido',
        };
    }

    private function instructions(): array
    {
        return [
            'Por dónde empezar' => 'Lea estas instrucciones y la Guía de campos. Diligencie únicamente la hoja Datos desde la fila 2. Los ejemplos de la guía solo explican el formato: no son datos que deba copiar a su inventario.',
            'Qué crea cada fila' => 'Cada fila crea un árbol nuevo y su inspección en el inventario elegido en la pantalla. La importación no edita árboles existentes. No vuelva a cargar un lote ya confirmado para actualizarlo.',
            'Hojas de catálogo' => 'Estados, Especies, Emplazamientos, Hábitos de crecimiento, Origen de establecimiento, Origen biogeográfico, Fauna y Condiciones contienen las referencias disponibles al descargar. Consulte la hoja indicada en la Guía de campos.',
            'Estructura del archivo' => 'Conserve los encabezados en español, su orden, las listas, los nombres de las hojas y la versión. No agregue hojas, fórmulas en las celdas, macros, enlaces externos, imágenes u objetos. Las referencias internas de las listas ya vienen configuradas. Guarde como libro de Excel (.xlsx).',
            'Organización e inventario' => 'Se seleccionan en SYSFOREST. No añada identificadores institucionales al archivo. Los registros se crean privados.',
            'Código del árbol' => 'Deje Código SYSFOREST vacío para generar el código. Si lo indica, debe usar el prefijo vigente de la organización y un sufijo de al menos seis dígitos, sin ceros adicionales. Un código repetido bloquea todo el lote. N. ÁRBOL / número legado conserva el identificador previo y no se usa para buscar coincidencias.',
            'Cómo usar los catálogos' => 'En Datos, abra la lista de cada campo de catálogo y seleccione Nombre [código]. También puede escribir el código exacto de la hoja de catálogo; no escriba solo el nombre. Las opciones son una ayuda: SYSFOREST revalida los códigos vigentes al cargar y confirmar.',
            'Fauna asociada' => 'La lista permite elegir una categoría; cada nueva elección reemplaza el contenido, no acumula selecciones. Para varias categorías, escriba sus códigos exactos separados por | y sin repetir. Si no tiene información, deje el campo vacío.',
            'Campos obligatorios' => 'Estado actual y Fecha de inspección deben estar diligenciados en cada fila. Puede seleccionar Sano [active] en la lista del estado del árbol. Las filas vacías no requieren ninguna selección. La ausencia de mediciones no se reemplaza por cero.',
            'Fechas y números' => 'Conserve el formato Texto de las celdas. Escriba las fechas como AAAA-MM-DD. Use punto decimal, sin separadores de miles ni notación científica. SYSFOREST conserva los valores exactos y no redondea entradas inválidas.',
            'Columnas grises' => 'Deben quedar vacías. SYSFOREST obtiene el nombre común, el nombre científico y la familia a partir del Código de especie, y calcula las medidas derivadas y la madurez con las reglas forestales vigentes. Las fotografías se cargan después en Evidencias.',
            'Condiciones del árbol' => 'Las 65 columnas de condiciones se distinguen por evaluación y órgano. Elija 1 para seleccionar; 0 o vacío no seleccionan y no acreditan que se haya examinado la ausencia. La hoja Condiciones señala la columna correspondiente: no copie sus códigos en Datos.',
            'Protegido y Patrimonial' => 'Use sus listas para elegir 1 (sí) o 0 (no). Un campo vacío se considera 0. No modifique las columnas grises para intentar completar un dato que SYSFOREST calcula.',
            'Alcance de las listas' => 'Las listas cubren las '.config('imports.max_rows', 100).' filas disponibles de Datos. Las hojas de catálogo contienen opciones vigentes al descargar; un catálogo vacío no muestra lista. Volver a elegir una opción reemplaza la anterior. No se usan macros ni selección múltiple automática.',
            'Coordenadas' => 'Georreferenciación (coordenadas) admite latitud, longitud en grados decimales o en grados, minutos y segundos. Declare el datum solo cuando lo conozca; SYSFOREST no infiere ni transforma automáticamente otros sistemas de referencia.',
            'Validar y confirmar' => 'Cargue el archivo y revise los errores por fila. La previsualización no crea árboles. Confirme únicamente un lote válido: SYSFOREST vuelve a verificar permisos, inventario y catálogos antes de guardar el lote completo.',
            'Límites' => 'Máximo '.config('imports.max_rows', 100).' filas con datos y '.config('imports.max_upload_kb', 5120).' KiB por archivo. Las filas totalmente vacías se omiten y las que exceden el límite se rechazan; nunca se truncan.',
            'Privacidad' => 'No incluya contraseñas ni información ajena al inventario. No pegue fotografías ni enlaces para descargarlas. Las hojas de catálogo son de consulta y no actualizan los catálogos de SYSFOREST.',
        ];
    }
}

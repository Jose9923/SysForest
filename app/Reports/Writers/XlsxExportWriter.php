<?php

declare(strict_types=1);

namespace App\Reports\Writers;

use App\Reports\ExportCell;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

final class XlsxExportWriter
{
    private const MAX_CELL_LENGTH = 32767;

    /** Celdas StringCell explícitas: nunca fórmulas ni redondeo a los 15 dígitos de Excel. */
    public function write(string $path, array $columns, iterable $rows, array $metadata = []): int
    {
        $workspace = $this->workspace($path);
        $writer = new Writer(new Options(
            DEFAULT_COLUMN_WIDTH: 22,
            tempFolder: $workspace,
            properties: new Properties(
                title: $metadata['title'] ?? 'Inventario forestal',
                application: 'SYSFOREST',
                creator: 'SYSFOREST',
                lastModifiedBy: 'SYSFOREST',
                language: 'es'
            ),
        ));
        $header = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '246447', shouldWrapText: true);
        try {
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Datos');
            $writer->addRow($this->row(array_map(ExportCell::header(...), $columns), $header));
            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow($this->row(array_map(fn ($column) => ExportCell::text($row[$column['key']] ?? null), $columns)));
                $count++;
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Diccionario');
            $writer->addRow($this->row(['Columna', 'Nombre técnico', 'Unidad', 'Tipo', 'Grupo'], $header));
            foreach ($columns as $column) {
                $writer->addRow($this->row([$column['label'], $column['key'], $column['unit'] ?? '',
                    $column['type'] ?? 'text', $column['group'] ?? '']));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Criterios');
            $writer->addRow($this->row(['Criterio', 'Valor'], $header));
            foreach (['title' => 'Reporte', 'generatedAt' => 'Generado', 'scopeLabel' => 'Alcance'] as $key => $label) {
                $writer->addRow($this->row([$label, $metadata[$key] ?? '']));
            }
            $writer->addRow($this->row(['Filas', (string) $count]));
            foreach ($metadata['appliedFilters'] ?? [] as $filter) {
                $writer->addRow($this->row([$filter['label'], $filter['value']]));
            }
            $writer->addRow($this->row(['Precisión', 'Los valores se guardan como texto para conservar decimales y códigos exactos. No hay fórmulas.']));
            $writer->addRow($this->row(['Uso', 'Exportación de consulta; no es una plantilla de importación.']));

            return $count;
        } finally {
            try {
                $writer->close();
            } finally {
                // close borra los XML; si el proceso muere, el repositorio recupera el workspace por TTL.
                if (is_dir($workspace) && !is_link($workspace) && scandir($workspace) === ['.', '..']) {
                    rmdir($workspace);
                }
            }
        }
    }

    private function workspace(string $path): string
    {
        $workspace = $path.'.work';
        if (!is_file($path) || is_link($path) || realpath(dirname($path)) !== dirname($path)
            || file_exists($workspace) || is_link($workspace) || !mkdir($workspace, 0700)) {
            throw new RuntimeException('No fue posible preparar el espacio temporal de Excel.');
        }

        return $workspace;
    }

    private function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(function ($value) use ($style): StringCell {
            $text = ExportCell::text($value);
            // Excel almacena texto UTF-16: cuenta también los pares sustitutos, sin cortar información.
            $length = intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
            if ($length > self::MAX_CELL_LENGTH) {
                throw ValidationException::withMessages([
                    'format' => 'Una celda supera el límite de 32.767 caracteres de Excel. Selecciona CSV para conservar el contenido completo.',
                ]);
            }

            return new StringCell($text, $style);
        }, array_values($values)));
    }
}

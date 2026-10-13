<?php

declare(strict_types=1);

namespace App\Reports\Writers;

use App\Reports\ExportCell;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/** Libro privado en español; cada celda se escribe como texto, incluso si empieza por =, +, - o @. */
final class AuditXlsxWriter
{
    private const MAX_CELL_LENGTH = 32767;
    private const MAX_DATA_ROWS = 1048575;

    public function write(string $path, iterable $entries, array $criteria): int
    {
        $workspace = $this->workspace($path);
        $writer = new Writer(new Options(
            FALLBACK_STYLE: new Style(fontSize: 11, shouldWrapText: true),
            SHOULD_CREATE_NEW_SHEETS_AUTOMATICALLY: false,
            DEFAULT_COLUMN_WIDTH: 24,
            tempFolder: $workspace,
            properties: new Properties(title: 'Auditoría de actividad', application: 'SYSFOREST', creator: 'SYSFOREST', lastModifiedBy: 'SYSFOREST', language: 'es'),
        ));
        $header = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '246447', shouldWrapText: true);

        try {
            $writer->openToFile($path);
            $events = $writer->getCurrentSheet()->setName('Eventos');
            $this->prepareSheet($events);
            $events->setColumnWidth(29, 1, 5, 6);
            $events->setColumnWidth(35, 3, 4, 7, 10);
            $events->setColumnWidth(39, 8, 11, 13);
            $events->setColumnWidth(58, 15);
            $writer->addRow($this->row([
                'Fecha y hora (UTC)', 'Módulo', 'Acción', 'Código de acción', 'Usuario', 'Organización',
                'Tipo de recurso', 'Identificador del recurso', 'Identificador del usuario', 'Origen del registro',
                'Identificador del registro', 'Versión', 'Identificador de petición', 'Valores antes y después', 'Información adicional',
            ], $header));

            $changes = $writer->addNewSheetAndMakeItCurrent()->setName('Cambios');
            $this->prepareSheet($changes);
            $changes->setColumnWidth(39, 1);
            $changes->setColumnWidth(29, 2);
            $changes->setColumnWidth(38, 3);
            $changes->setColumnWidth(65, 4, 5);
            $writer->addRow($this->row(['Identificador del registro', 'Fecha y hora (UTC)', 'Campo', 'Valor anterior', 'Valor posterior'], $header));

            $count = 0;
            $changeCount = 0;
            foreach ($entries as $entry) {
                if ($count >= self::MAX_DATA_ROWS) {
                    throw ValidationException::withMessages(['export' => 'Los eventos superan el límite de filas de Excel. Reduce el rango de fechas o aplica más filtros.']);
                }
                $id = $entry['source'].':'.$entry['id'];
                $writer->setCurrentSheet($events);
                $writer->addRow($this->row([
                    $entry['occurred_at'], $entry['module_label'], $entry['event_label'], $entry['event'],
                    $entry['actor_name'], $entry['organization_name'], $entry['resource_label'], $entry['resource_id'],
                    $entry['user_id'] ?? '', $entry['source_label'], $id, $entry['version'], $entry['request_id'],
                    $entry['has_snapshots'] ? 'Disponibles' : 'No registrados', $this->metadata($entry['metadata']),
                ]));
                $writer->setCurrentSheet($changes);
                foreach ($entry['changes'] as $change) {
                    if ($changeCount >= self::MAX_DATA_ROWS) {
                        throw ValidationException::withMessages(['export' => 'Los cambios superan el límite de filas de Excel. Reduce el rango de fechas o aplica más filtros.']);
                    }
                    $writer->addRow($this->row([$id, $entry['occurred_at'], $change['label'], $change['before'], $change['after']]));
                    $changeCount++;
                }
                $count++;
            }
            $events->setAutoFilter(new AutoFilter(0, 1, 14, $count + 1));
            $changes->setAutoFilter(new AutoFilter(0, 1, 4, $changeCount + 1));

            $criteriaSheet = $writer->addNewSheetAndMakeItCurrent()->setName('Criterios');
            $this->prepareSheet($criteriaSheet);
            $criteriaSheet->setColumnWidth(32, 1);
            $criteriaSheet->setColumnWidth(105, 2);
            $writer->addRow($this->row(['Criterio', 'Valor'], $header));
            $writer->addRow($this->row(['Eventos exportados', $count]));
            $writer->addRow($this->row(['Cambios exportados', $changeCount]));
            foreach ($criteria as $label => $value) {
                $writer->addRow($this->row([$label, $value]));
            }

            return $count;
        } finally {
            try {
                $writer->close();
            } finally {
                if (is_dir($workspace) && !is_link($workspace) && scandir($workspace) === ['.', '..']) {
                    rmdir($workspace);
                }
            }
        }
    }

    private function prepareSheet(Sheet $sheet): void
    {
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setPrintTitleRows('1:1');
    }

    private function metadata(array $metadata): string
    {
        $values = [];
        foreach ($metadata as $label => $value) {
            $values[] = $label.': '.ExportCell::text($value);
        }

        return implode("\n", $values);
    }

    private function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(function ($value) use ($style): StringCell {
            $text = ExportCell::text($value);
            $length = intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
            if ($length > self::MAX_CELL_LENGTH) {
                throw ValidationException::withMessages([
                    'export' => 'Un valor supera los 32.767 caracteres admitidos por Excel. Reduce la selección y consulta ese registro desde el detalle de auditoría.',
                ]);
            }

            return new StringCell($text, $style);
        }, array_values($values)));
    }

    private function workspace(string $path): string
    {
        $workspace = $path.'.work';
        if (!is_file($path) || is_link($path) || realpath(dirname($path)) !== dirname($path)
            || file_exists($workspace) || is_link($workspace) || !mkdir($workspace, 0700)) {
            throw new RuntimeException('No fue posible preparar el espacio temporal del Excel de auditoría.');
        }

        return $workspace;
    }
}

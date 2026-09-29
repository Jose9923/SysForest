<?php

declare(strict_types=1);

namespace App\Reports\Writers;

use App\Reports\ExportCell;
use RuntimeException;

final class CsvExportWriter
{
    /** Escribe UTF-8 con BOM y punto y coma, conservando saltos de línea dentro de celdas. */
    public function write(string $path, array $columns, iterable $rows, array $metadata = []): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('No fue posible preparar el archivo de exportación.');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            $this->row($handle, array_map(fn ($column) => ExportCell::csv(ExportCell::header($column)), $columns));
            $count = 0;
            foreach ($rows as $row) {
                $this->row($handle, array_map(fn ($column) => ExportCell::csv($row[$column['key']] ?? null, $column['type'] ?? 'text'), $columns));
                $count++;
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    private function row($handle, array $values): void
    {
        if (fputcsv($handle, $values, ';', '"', '', "\r\n") === false) {
            throw new RuntimeException('No fue posible completar el archivo de exportación.');
        }
    }
}

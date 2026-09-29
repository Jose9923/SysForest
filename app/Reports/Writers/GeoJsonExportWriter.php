<?php

declare(strict_types=1);

namespace App\Reports\Writers;

use RuntimeException;

final class GeoJsonExportWriter
{
    /** WGS84, orden longitud/latitud y geometry:null para conservar individuos sin punto. */
    public function write(string $path, array $columns, iterable $rows, array $metadata = []): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('No fue posible preparar el archivo de exportación.');
        }
        try {
            $this->put($handle, '{"type":"FeatureCollection","metadata":'.$this->json($metadata).',"features":[');
            $count = 0;
            foreach ($rows as $row) {
                $properties = [];
                foreach ($columns as $column) {
                    $properties[$column['key']] = $row[$column['key']] ?? null;
                }
                $feature = ['type' => 'Feature', 'id' => $row['_identity'],
                    'geometry' => $row['_geometry'] ?? null, 'properties' => $properties];
                $this->put($handle, ($count ? ',' : '').$this->json($feature));
                $count++;
            }
            $this->put($handle, ']}');

            return $count;
        } finally {
            fclose($handle);
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function put($handle, string $value): void
    {
        if (fwrite($handle, $value) !== strlen($value)) {
            throw new RuntimeException('No fue posible completar el archivo de exportación.');
        }
    }
}

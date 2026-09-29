<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory;
use App\Models\Organization;

/** Combina errores de estructura y dominio sin escribir árboles ni consumir consecutivos. */
class ImportValidationService
{
    public function __construct(private ImportRowValidationService $validator)
    {
    }

    public function validate(Organization $organization, Inventory $inventory, array $sources, bool $lock = false): array
    {
        $results = [];
        $codes = [];
        foreach ($sources as $source) {
            $normalized = $this->validator->validate($organization, $inventory, $source['values'], $source['errors'] ?? [], $lock);
            $errors = $normalized['errors'];
            unset($normalized['errors']);
            $results[] = ['row_number' => $source['row_number'], 'raw_values' => $source['values'],
                'reader_errors' => $source['errors'] ?? [], 'normalized_values' => $normalized, 'errors' => $errors];
            $code = $source['values']['code'] ?? null;
            if (is_string($code) && $code !== '') {
                $codes[$code][] = array_key_last($results);
            }
        }
        foreach ($codes as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $results[$index]['errors']['code'][] = 'El código aparece más de una vez en este archivo. Cada individuo debe ocupar una sola fila.';
                }
            }
        }

        return $results;
    }
}

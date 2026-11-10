<?php

declare(strict_types=1);

namespace App\Support\Conditions;

/** Etiquetas de gráficas privadas; conserva nombres canónicos, filas y conteos. */
final class ConditionChartPresentation
{
    /** El órgano puede figurar una sola vez junto al título cuando describe todas las series elegidas. */
    public static function context(array $rows, bool $selected): ?string
    {
        if (! $selected || $rows === []) {
            return null;
        }

        $categories = array_values(array_unique(array_map(fn (array $row): string => (string) ($row['category'] ?? ''), $rows)));
        if (count($categories) !== 1 || trim($categories[0]) === '') {
            return null;
        }

        // El catálogo impide duplicados por órgano; no acortamos datos ambiguos recibidos de otro consumidor.
        $names = array_map(fn (array $row): string => mb_strtolower(trim((string) ($row['group'] ?? ''))), $rows);
        if (in_array('', $names, true) || count(array_unique($names)) !== count($names)) {
            return null;
        }

        return $categories[0];
    }

    public static function label(array $row, ?string $context): string
    {
        $category = (string) ($row['category'] ?? '');
        $condition = (string) ($row['group'] ?? '');

        return $context !== null && $context === $category
            ? $condition
            : $category.' · '.$condition;
    }
}

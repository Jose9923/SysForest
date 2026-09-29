<?php

declare(strict_types=1);

namespace App\Reports;

use Normalizer;

/** Conserva precisión decimal y distingue cero, falso y ausencia de una observación. */
final class ExportCell
{
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Sí' : 'No',
            default => (string) $value,
        };
    }

    /** CSV no tiene tipos: neutraliza fórmulas sin alterar decimales estrictamente válidos. */
    public static function csv(mixed $value, string $type = 'text'): string
    {
        $text = self::text($value);
        if (in_array($type, ['decimal', 'integer'], true) && preg_match('/\A-?\d+(?:\.\d+)?\z/D', $text)) {
            return $text;
        }
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $normalized = preg_replace('/\A[\p{Z}\p{C}\s]+/u', '', $normalized) ?? $normalized;

        return preg_match('/\A[=+@-]/u', $normalized) ? "'".$text : $text;
    }

    public static function header(array $column): string
    {
        return $column['label'].(empty($column['unit']) ? '' : ' ('.$column['unit'].')');
    }
}

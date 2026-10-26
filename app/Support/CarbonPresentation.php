<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CarbonEstimate;
use App\Models\TreeInspection;

class CarbonPresentation
{
    public static function labels(): array
    {
        return ['missing_density_reference' => 'Pendiente de densidad específica respaldada',
            'unresolved_identification' => 'Identificación taxonómica pendiente de revisión',
            'missing_habit' => 'Falta identificar el hábito de crecimiento',
            'unsupported_habit' => 'Hábito distinto de árbol: requiere otro método',
            'estimated' => 'Estimado', 'missing_species' => 'Falta identificar la especie',
            'missing_profile' => 'Pendiente de referencia y validación por especie', 'not_applicable' => 'Método no aplicable a esta especie',
            'missing_stems' => 'Falta registrar el número de fustes', 'multiple_stems' => 'Varios fustes: requiere otro método',
            'missing_measurements' => 'Faltan CAP o altura total', 'small_diameter' => 'DAP menor de 5 cm: fuera del método',
            'outside_range' => 'Fuera del intervalo validado', 'unprocessed' => 'Pendiente de cálculo',
            'stale_species' => 'La especie cambió: actualizar la estimación', 'excluded_status' => 'Estado actual excluido del stock vivo'];
    }

    public static function label(?string $status): string
    {
        return self::labels()[$status ?? 'unprocessed'] ?? 'Pendiente de revisión';
    }

    public static function referenceLabel(?string $status): string
    {
        return match ($status) {
            'bibliographic' => 'Bibliográfica provisional',
            'professional' => 'Revisada por profesional',
            'pending_density' => 'Densidad pendiente',
            'pending_identification' => 'Identificación pendiente',
            'unsupported' => 'Requiere otro método',
            default => 'Sin referencia',
        };
    }

    public static function coverageNotice(array $rows): string
    {
        $total = array_sum(array_column($rows, 'trees'));
        $estimated = array_sum(array_column($rows, 'estimated'));
        $provisional = array_sum(array_column($rows, 'provisional'));
        $reviewed = array_sum(array_column($rows, 'reviewed'));
        $pending = array_sum(array_column($rows, 'pending'));
        $percentage = $total > 0 ? number_format(100 * $estimated / $total, 1, ',', '.') : '0';

        return 'Cobertura del stock: '.$estimated.' de '.$total.' árboles seleccionados ('.$percentage.' %). '
            .$provisional.' con referencia bibliográfica provisional; '.$reviewed.' con referencia revisada por profesional. '
            .$pending.' pendientes o excluidos. Los valores sin estimación no se consideran cero.';
    }

    public static function current(?TreeInspection $inspection): ?CarbonEstimate
    {
        $estimate = $inspection?->getRelation('carbonEstimate');

        return $estimate?->inspection_version === $inspection?->lock_version ? $estimate : null;
    }
}

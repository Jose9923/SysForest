<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/** Cobertura de las condiciones registradas, no exhaustividad de la inspección de órganos. */
final class RiskCoverage
{
    public static function fromCodes(array $inputCodes, array $evaluatedCodes): array
    {
        $evaluatedCodes = array_values(array_unique($evaluatedCodes));
        $excludedCodes = array_values(array_diff(array_unique($inputCodes), $evaluatedCodes));
        $status = $evaluatedCodes === [] ? 'missing' : ($excludedCodes === [] ? 'complete' : 'partial');
        $warnings = [];
        if ($status === 'missing') {
            $warnings[] = 'No hay condiciones registradas incluidas en esta metodología. La captura es insuficiente para sugerir una prioridad; no equivale a un árbol sano.';
        }
        if ($excludedCodes !== []) {
            $warnings[] = 'Hay condiciones registradas excluidas de esta metodología: se conservan en la inspección, pero no puntúan.';
        }

        return ['coverage_status' => $status, 'evaluated_condition_count' => count($evaluatedCodes),
            'excluded_codes' => $excludedCodes, 'warnings' => $warnings];
    }
}

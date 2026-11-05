<?php

declare(strict_types=1);

namespace App\Support\Risk;

use App\Domain\Risk\RiskCoverage;
use App\Enums\Risk\RiskPriority;
use App\Models\Risk\RiskEvaluation;
use App\Support\Imports\ExcelReferencePresentation;

/** Etiquetas privadas compartidas por fichas, tablas y exportaciones. */
final class RiskPresentation
{
    public static function label(?RiskEvaluation $evaluation): string
    {
        if ($evaluation === null) {
            return 'Sin evaluar';
        }
        if (($evaluation->result['status'] ?? '') === 'inconsistent') {
            return 'Inconsistente';
        }

        if ($evaluation->priority === null && self::result($evaluation->result)['coverage_status'] === 'missing') {
            return 'Captura insuficiente · pendiente de confirmación';
        }

        return $evaluation->priority === null ? 'Pendiente de confirmación' : RiskPriority::from($evaluation->priority)->label();
    }
    public static function condition(array $condition): string
    {
        $component = self::components()[$condition['component'] ?? ''] ?? null;

        return ($component === null ? '' : $component.' / ').$condition['label'];
    }

    public static function components(): array
    {
        return ['PHT_ROOT' => 'Sistema radicular', 'PHT_STEM' => 'Fuste o estípite', 'PHT_BRANCH' => 'Ramas',
            'PHT_FOLIAGE' => 'Follaje', 'PHT_FLOWER_FRUIT' => 'Flores y frutos', 'PHT_GENERAL' => 'Estado general'];
    }

    /** Adapta también evaluaciones anteriores que conservan direcciones en sus explicaciones. */
    public static function result(array $result, array $configuration = []): array
    {
        $coverage = RiskCoverage::fromCodes($result['input_codes'] ?? $result['selected_codes'] ?? [], $result['selected_codes'] ?? []);
        $result = array_replace($result, array_diff_key($coverage, ['warnings' => true]));
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'] ?? [], $coverage['warnings'])));
        // Una sugerencia antigua sin observaciones queda identificada, sin reescribir el registro.
        if ($coverage['coverage_status'] === 'missing' && ($result['priority'] ?? null) !== null) {
            $result['historical_suggestion'] = array_intersect_key($result, array_fill_keys([
                'priority', 'priority_label', 'severity_max', 'extension_class',
            ], true));
            $result = array_replace($result, ['priority' => null, 'priority_label' => null,
                'severity_max' => null, 'extension_class' => null, 'management_message' => null]);
            $result['warnings'][] = 'La sugerencia histórica se calculó sin condiciones evaluadas y se conserva solo como antecedente; no acredita que el árbol estuviera sano.';
        }
        $excluded = array_column($result['excluded_conditions'] ?? [], null, 'code');
        $result['excluded_conditions'] = array_map(function (string $code) use ($excluded): array {
            $condition = $excluded[$code] ?? ['code' => $code, 'label' => $code, 'component' => null];
            unset($condition['column']);
            $condition['display_label'] = self::condition($condition);

            return $condition;
        }, $coverage['excluded_codes']);
        $replacements = [];
        foreach ($configuration['conditions'] ?? [] as $condition) {
            if (!empty($condition['column'])) {
                $replacements[$condition['column'].' · '.$condition['label']] = self::condition($condition);
            }
        }
        $result['errors'] = array_map(fn (string $message): string => ExcelReferencePresentation::riskMessage(strtr($message, $replacements)), $result['errors']);
        $result['warnings'] = array_map(ExcelReferencePresentation::riskMessage(...), $result['warnings']);
        $conditions = $configuration['conditions'] ?? [];
        foreach ($result['determinants'] as &$item) {
            unset($item['column']);
            $item['display_label'] = self::condition($item);
            $conditions[$item['code']] = $item;
        }
        unset($item);
        foreach ($result['overrides'] as &$override) {
            $condition = $conditions[$override['code']] ?? null;
            $override['label'] = $condition === null ? 'Condición observada' : self::condition($condition);
        }
        unset($override);

        return $result;
    }

    public static function explanation(RiskEvaluation $evaluation): string
    {
        $configuration = $evaluation->relationLoaded('methodology') ? $evaluation->getRelation('methodology')?->configuration ?? [] : [];
        $result = self::result($evaluation->result, $configuration);
        $parts = ['Condiciones registradas incluidas en la metodología: '.$result['evaluated_condition_count'],
            'Severidad máxima: '.($result['severity_max'] ?? 'Sin clasificación'),
            'Extensión: '.($result['extension_class'] ?? 'Sin clasificación'),
            'Componentes afectados: '.$result['affected_component_count']];
        foreach ($result['determinants'] as $item) {
            $parts[] = 'Determinante: '.$item['display_label'].' (peso '.$item['weight'].')';
        }
        foreach ($result['overrides'] as $override) {
            $parts[] = 'Prioridad mínima por '.$override['label'].': '.RiskPriority::from($override['minimum'])->label();
        }
        foreach ($result['excluded_conditions'] as $condition) {
            $parts[] = 'Condición registrada que no puntúa: '.$condition['display_label'];
        }
        if (isset($result['historical_suggestion'])) {
            $parts[] = 'Sugerencia histórica conservada: '.$result['historical_suggestion']['priority_label'];
        }

        return implode('; ', array_merge($parts, $result['errors'], $result['warnings']));
    }
}

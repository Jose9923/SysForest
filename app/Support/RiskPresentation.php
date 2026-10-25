<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RiskPriority;
use App\Models\RiskEvaluation;

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
        $parts = ['Severidad máxima: '.($result['severity_max'] ?? 'Sin clasificación'),
            'Extensión: '.($result['extension_class'] ?? 'Sin clasificación'),
            'Componentes afectados: '.$result['affected_component_count']];
        foreach ($result['determinants'] as $item) {
            $parts[] = 'Determinante: '.$item['display_label'].' (peso '.$item['weight'].')';
        }
        foreach ($result['overrides'] as $override) {
            $parts[] = 'Prioridad mínima por '.$override['label'].': '.RiskPriority::from($override['minimum'])->label();
        }

        return implode('; ', array_merge($parts, $result['errors'], $result['warnings']));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Forestry;

use App\Enums\RiskPriority;

/** Motor puro: usa códigos del catálogo, jamás medidas, nombres visibles o SUM de pesos. */
final class PhytosanitaryRisk
{
    public const COMPONENTS = ['PHT_ROOT', 'PHT_STEM', 'PHT_BRANCH', 'PHT_FOLIAGE', 'PHT_FLOWER_FRUIT'];

    public function evaluate(array $configuration, array $selectedCodes): array
    {
        $selected = array_fill_keys($selectedCodes, true);
        $conditions = $configuration['conditions'];
        $marked = array_intersect_key($conditions, $selected);
        $errors = [];
        foreach ($configuration['conflicts'] as $rule) {
            if (!isset($selected[$rule['left']])) {
                continue;
            }
            foreach ($rule['right'] as $right) {
                if (isset($selected[$right])) {
                    $describe = fn (string $code): string => \App\Support\RiskPresentation::condition($conditions[$code]);
                    $errors[] = 'Selecciones incompatibles: '.$describe($rule['left']).' y '.$describe($right).'.';
                }
            }
        }
        $warnings = [];
        foreach ($configuration['warnings'] as $rule) {
            if (!isset($selected[$rule['condition']])) {
                continue;
            }
            $applies = $rule['kind'] === 'healthy_severe'
                ? count(array_filter($marked, fn (array $row) => $row['component'] !== 'PHT_GENERAL' && $row['weight'] >= 3)) > 0
                : array_intersect_key($selected, array_fill_keys($rule['unless'] ?? [], true)) === [];
            if ($applies) {
                $warnings[] = $rule['message'];
            }
        }
        $result = ['status' => $errors === [] ? 'valid' : 'inconsistent', 'errors' => $errors,
            'warnings' => $warnings, 'methodology_code' => $configuration['code'],
            'input_codes' => array_values(array_unique($selectedCodes)), 'selected_codes' => array_keys($marked), 'severity_max' => null, 'extension_class' => null,
            'determinants' => [], 'affected_components' => [], 'affected_component_count' => 0,
            'priority' => null, 'priority_label' => null, 'management_message' => null, 'overrides' => []];
        if ($errors !== []) {
            return $result;
        }
        $severity = max([0, ...array_column($marked, 'weight')]);
        $components = [];
        foreach ($marked as $code => $row) {
            if ($row['weight'] > 0 && in_array($row['component'], self::COMPONENTS, true)) {
                $components[$row['component']] = true;
            }
            if ($severity > 0 && $row['weight'] === $severity) {
                $result['determinants'][] = ['code' => $code] + $row;
            }
        }
        $count = count($components);
        $extension = match (true) {
            $count === 0 => 0, $count === 1 => 1, $count <= 3 => 2, default => 3
        };
        $priority = $configuration['matrix'][$severity][$extension];
        foreach ($marked as $code => $row) {
            if ($row['minimum'] > 0) {
                $priority = max($priority, $row['minimum']);
                $result['overrides'][] = ['code' => $code, 'minimum' => $row['minimum']];
            }
        }
        $level = RiskPriority::from($priority);

        return array_replace($result, ['severity_max' => $severity, 'extension_class' => 'E'.$extension,
            'affected_components' => array_keys($components), 'affected_component_count' => $count,
            'priority' => $priority, 'priority_label' => $level->label(), 'management_message' => $level->message()]);
    }
}

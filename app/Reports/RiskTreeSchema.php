<?php

declare(strict_types=1);

namespace App\Reports;

/** Esquema privado por individuo; los metadatos de navegación nunca son columnas descargables. */
class RiskTreeSchema
{
    public function columns(bool $detail = false): array
    {
        $labels = [
            'code' => 'Código del árbol',
            'organization' => 'Organización',
            'common_names' => 'Nombres comunes',
            'scientific_name' => 'Nombre científico',
            'location_description' => 'Ubicación',
            'inventory' => 'Campaña de la última inspección',
            'current_status_label' => 'Estado del árbol',
            'latest_inspection_date' => 'Última inspección',
            'risk_priority' => 'Prioridad profesional',
            'risk_suggestion' => 'Sugerencia automática',
            'risk_methodology' => 'Metodología de la evaluación',
        ];
        if ($detail) {
            $labels += [
                'municipality' => 'Municipio',
                'risk_confirmed_by' => 'Prioridad confirmada por',
                'risk_confirmed_at' => 'Confirmación de prioridad (Bogotá)',
                'risk_explanation' => 'Explicación de la sugerencia',
                'risk_observations' => 'Observaciones de la evaluación',
            ];
        }

        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label, 'type' => 'text', 'group' => 'gestión del riesgo'], array_keys($labels), $labels);
    }

    public function project(array $values): array
    {
        return array_intersect_key($values, array_column($this->columns(true), 'label', 'key'));
    }

    public function notices(): array
    {
        return [
            'Una fila por árbol y su última inspección. La prioridad profesional y la sugerencia automática son datos distintos; una sugerencia no confirma una decisión.',
            'Sin evaluación o sin condiciones registradas no equivale a riesgo bajo. Cada institución conserva su metodología y versión de evaluación.',
            'El filtro de inventario selecciona los árboles que pertenecen a la campaña. La campaña mostrada corresponde a la última visita y puede ser diferente.',
        ];
    }
}

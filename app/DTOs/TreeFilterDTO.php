<?php

declare(strict_types=1);

namespace App\DTOs;

/** Filtros ya validados; no representa una autorización ni contiene viewport o modo de dibujo. */
final readonly class TreeFilterDTO
{
    private function __construct(private array $filters)
    {
    }

    public static function fromValidated(array $filters): self
    {
        foreach ($filters as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $filters[$key] = null;
            }
        }
        foreach (['is_protected', 'is_heritage', 'has_pending_maintenance'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = in_array($filters[$key], [true, 1, '1'], true);
            }
        }

        return new self($filters);
    }

    public function values(): array
    {
        return $this->filters;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /** Conserva false, cero y el texto «0» como criterios expresamente indicados. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->filters) && $this->filters[$key] !== null
            && $this->filters[$key] !== '' && $this->filters[$key] !== [];
    }

    public function hasInspectionFilters(): bool
    {
        foreach (['date_from', 'date_to', 'maturity_state_id', 'diameter_class_number', 'height_class_number',
            'physical_condition_ids', 'phytosanitary_condition_ids', 'cap_cm_min', 'cap_cm_max',
            'dap_m_min', 'dap_m_max', 'total_height_m_min', 'total_height_m_max'] as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    public function hasMaintenanceFilters(): bool
    {
        return $this->has('maintenance_type_id') || $this->has('maintenance_status')
            || $this->has('maintenance_priority') || $this->get('has_pending_maintenance') === true;
    }
}

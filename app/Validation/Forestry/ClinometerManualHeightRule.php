<?php

declare(strict_types=1);

namespace App\Validation\Forestry;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/** Las alturas enviadas por el cliente no son operandos cuando está activo el inclinómetro. */
final class ClinometerManualHeightRule implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(private Closure $manualRule)
    {
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $offset = strrpos($attribute, '.');
        $prefix = $offset === false ? '' : substr($attribute, 0, $offset + 1);
        if (!ClinometerInput::enabled(data_get($this->data, $prefix.'uses_clinometer'))) {
            $field = $offset === false ? $attribute : substr($attribute, $offset + 1);
            ($this->manualRule)($field, $value, $fail);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use App\Repositories\OrganizationRepository;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueOrganizationValue implements ValidationRule
{
    public function __construct(private string $column, private ?string $exceptId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(OrganizationRepository::class)->valueExists($this->column, $value, $this->exceptId)) {
            $fail('Este código o prefijo ya está reservado por otra organización.');
        }
    }
}

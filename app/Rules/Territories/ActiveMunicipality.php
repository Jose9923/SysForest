<?php

declare(strict_types=1);

namespace App\Rules\Territories;

use App\Services\Territories\TerritoryService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ActiveMunicipality implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !\App\Support\Identifiers\RecordIdentifier::isValid($value)) {
            $fail('Selecciona un municipio válido.');

            return;
        }

        try {
            app(TerritoryService::class)->requireActiveMunicipality($value);
        } catch (ModelNotFoundException | ValidationException) {
            $fail('El municipio debe existir y su jerarquía territorial debe estar activa.');
        }
    }
}

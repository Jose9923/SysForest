<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\TreeFilterDTO;
use App\Repositories\InspectionConditionRepository;
use App\Validation\TreeFilterInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Valida semántica compartida; cada consumidor autoriza el scope y los permisos correspondientes. */
class TreeFilterService
{
    public function __construct(private InspectionConditionRepository $conditions)
    {
    }

    public function validate(array $input): TreeFilterDTO
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $input);
        $filters = Validator::make($input, TreeFilterInput::rules(), TreeFilterInput::messages())->validate();
        foreach (['physical_condition_ids' => 'physical_mechanical', 'phytosanitary_condition_ids' => 'phytosanitary'] as $field => $type) {
            if (!$this->conditions->validateForFilter($filters[$field] ?? [], $type)) {
                throw ValidationException::withMessages([$field => 'Seleccione condiciones existentes del grupo correspondiente.']);
            }
        }
        if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final no puede preceder a la inicial.']);
        }
        foreach (['cap_cm', 'dap_m', 'total_height_m'] as $field) {
            if (isset($filters[$field.'_min'], $filters[$field.'_max'])
                && bccomp((string) $filters[$field.'_min'], (string) $filters[$field.'_max'], 12) > 0) {
                throw ValidationException::withMessages([$field.'_max' => 'El máximo debe ser mayor o igual que el mínimo.']);
            }
        }

        return TreeFilterDTO::fromValidated($filters);
    }
}

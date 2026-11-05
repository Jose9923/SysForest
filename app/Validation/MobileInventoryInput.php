<?php

declare(strict_types=1);

namespace App\Validation;

use App\Forestry\InventoryInput;

/** El alta móvil crea una campaña activa; identidad y autoría proceden del JWT. */
final class MobileInventoryInput
{
    public static function rules(): array
    {
        $fields = array_filter(InventoryInput::rules(), fn (array $rules) => !in_array('prohibited', $rules, true));
        $fields['status'] = ['sometimes', 'string', 'in:active'];
        $fields['active'] = ['sometimes', 'boolean', 'accepted'];
        $rules = ['operation_id' => ['required', 'uuid'],
            'inventory' => ['required', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $field => $constraints) {
            $rules['inventory.'.$field] = $constraints;
        }

        return $rules;
    }

    public static function messages(): array
    {
        return ['uuid' => 'operation_id debe ser el UUID permanente de esta operación.',
            'array' => 'El inventario debe contener únicamente los campos permitidos.',
            'accepted' => 'Los inventarios nuevos del móvil deben estar activos.'] + InventoryInput::messages();
    }
}

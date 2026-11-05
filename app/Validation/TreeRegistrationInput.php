<?php

declare(strict_types=1);

namespace App\Validation;

/** Alta web completa; la participación de la visita la resuelve el servidor después de crear el árbol. */
class TreeRegistrationInput
{
    public static function rules(): array
    {
        return array_replace(TreeInput::rules(), TreeInspectionInput::rules(), [
            'inventory_tree_id' => ['prohibited'],
            'first_inspection_inventory_id' => ['nullable', 'uuid'],
        ]);
    }

    public static function messages(): array
    {
        return ['inspection_date.required' => 'Indica la fecha de la primera inspección.']
            + TreeInspectionInput::messages() + TreeInput::messages();
    }
}

<?php

declare(strict_types=1);

namespace App\Validation\Mobile;

use Illuminate\Contracts\Validation\Validator;
use App\Validation\Evidences\EvidenceInput;

/** Una fotografía por operación; la identidad y la ubicación física las determina el servidor. */
class MobileTreePhotoInput
{
    public static function rejectUnexpected(Validator $validator, array $input): void
    {
        foreach (array_diff(array_keys($input), array_keys(self::rules())) as $field) {
            $validator->errors()->add($field, 'Este campo no forma parte del contrato de fotografías. Envía una imagen en file.');
        }
    }

    public static function rules(): array
    {
        $rules = array_fill_keys(array_keys(EvidenceInput::guards()), ['missing']);

        return $rules + [
            'client_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:'.((int) config('evidence.max_size_mb') * 1024)],
            'evidence_type_id' => ['required', 'uuid'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'captured_at' => ['nullable', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D'],
            'user_id' => ['missing'], 'created_by' => ['missing'], 'inventory_id' => ['missing'],
            'inventory_tree_id' => ['missing'], 'lock_version' => ['missing'], 'items' => ['missing'],
        ];
    }

    public static function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'uuid' => 'El campo :attribute debe ser un UUID válido.',
            'missing' => 'El campo :attribute lo determina el servidor y debe omitirse.',
            'file.file' => 'Adjunta una fotografía válida.',
            'file.uploaded' => 'La imagen no pudo cargarse o supera el tamaño máximo permitido.',
            'file.max' => 'La imagen supera el tamaño máximo permitido.',
            'captured_at.regex' => 'Envía una fecha ISO 8601 con segundos y zona horaria (Z o desplazamiento).',
            'captured_at.date' => 'La fecha de captura no es válida.',
            'max' => 'El campo :attribute supera el límite permitido.',
        ];
    }
}

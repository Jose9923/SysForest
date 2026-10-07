<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileTreeSyncRequest extends FormRequest
{
    public function validationData(): array
    {
        return $this->json()->all();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Solo el sobre es atómico; cada captura se valida dentro de su propia transacción. */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:client_id,tree,inspection'],
            'items.*.client_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'organization_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'inventory_id' => ['prohibited'], 'created_by' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'array' => 'El campo :attribute debe ser un objeto o lista con las claves permitidas.',
            'list' => 'Los árboles deben enviarse en una lista JSON.',
            'uuid' => 'client_id debe ser un UUID generado al capturar el árbol.',
            'distinct' => 'No repitas client_id dentro del mismo lote.',
            'max' => 'El lote admite hasta 100 árboles.',
            'prohibited' => 'El campo :attribute lo determina el servidor.',
        ];
    }
}

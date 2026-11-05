<?php

declare(strict_types=1);

namespace App\Http\Requests\Imports;

use App\Services\Imports\ImportAccessService;
use Illuminate\Foundation\Http\FormRequest;

/** La confirmación requiere una revisión consciente y la versión del lote presentada al usuario. */
class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ImportAccessService::class)->allows($this->user(), 'imports.create');
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'accepted'], 'lock_version' => ['required', 'integer', 'min:1'],
            'organization_id' => ['prohibited'], 'inventory_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'created_by' => ['prohibited'], 'path' => ['prohibited'], 'disk' => ['prohibited'],
            'rows' => ['prohibited'], 'raw_values' => ['prohibited'], 'normalized_values' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Confirma que revisaste todas las filas y el inventario de destino.',
            'confirmation.accepted' => 'Confirma que revisaste todas las filas y el inventario de destino.',
            'lock_version.*' => 'Actualiza la página para confirmar la versión vigente del lote.',
            '*.prohibited' => 'Los datos del lote revisado no pueden modificarse desde la confirmación.',
        ];
    }
}

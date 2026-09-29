<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ImportAccessService;
use Illuminate\Foundation\Http\FormRequest;

class CancelImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ImportAccessService::class)->allows($this->user(), 'imports.create');
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'organization_id' => ['prohibited'], 'inventory_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'created_by' => ['prohibited'], 'path' => ['prohibited'], 'disk' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'lock_version.*' => 'Actualiza la página para cancelar la versión vigente del lote.',
            '*.prohibited' => 'Los datos del lote no pueden modificarse desde esta operación.',
        ];
    }
}

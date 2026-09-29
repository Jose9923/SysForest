<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ImportAccessService;
use Illuminate\Foundation\Http\FormRequest;

/** El cliente selecciona el inventario; la institución y la autoría se resuelven en el servidor. */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ImportAccessService::class)->allows($this->user(), 'imports.create');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx', 'max:'.(int) config('imports.max_upload_kb', 5120)],
            'inventory_id' => ['required', 'uuid'],
            'organization_id' => ['prohibited'], 'user_id' => ['prohibited'], 'created_by' => ['prohibited'],
            'path' => ['prohibited'], 'disk' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona la plantilla oficial diligenciada.',
            'file.file' => 'No se recibió un archivo válido.',
            'file.extensions' => 'Carga un libro Excel con extensión .xlsx de la plantilla oficial.',
            'file.max' => 'El archivo supera el tamaño permitido.',
            'inventory_id.required' => 'Selecciona el inventario de destino.',
            'inventory_id.uuid' => 'El inventario seleccionado no es válido.',
            '*.prohibited' => 'Este dato lo determina el servidor y no puede enviarse desde el formulario.',
        ];
    }
}

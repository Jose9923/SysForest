<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/** Los controles de navegación se validan aquí; los filtros forestales pasan al servicio común. */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['report' => ['sometimes', 'required', 'string', 'max:60'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'format' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['report.required' => 'Selecciona un reporte.', 'report.string' => 'El reporte no es válido.',
            'report.max' => 'El reporte no es válido.', 'page.integer' => 'La página debe ser un entero.',
            'page.min' => 'La página debe ser positiva.', 'page.max' => 'La página excede el límite permitido.',
            'format.prohibited' => 'El formato se elige mediante el enlace de descarga.'];
    }
}

<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Contrato común para HTTP y llamadas internas: la procedencia nunca procede del cliente. */
class EvidenceInput
{
    public static function guards(): array
    {
        return array_fill_keys(['id', 'organization_id', 'tree_id', 'tree_inspection_id', 'maintenance_id', 'disk', 'path',
            'original_name', 'mime_type', 'extension', 'size_bytes', 'width', 'height', 'checksum_sha256',
            'metadata', 'uploaded_by', 'updated_by', 'deleted_by', 'deleted_at', 'created_at', 'updated_at', 'is_public', 'publication_version', 'public_id', 'public_caption'], ['prohibited']);
    }

    public static function metadataRules(): array
    {
        return ['evidence_type_id' => ['required', 'uuid'], 'caption' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'captured_at' => ['nullable', 'date_format:Y-m-d\TH:i']];
    }

    public static function storeRules(): array
    {
        $rules = self::guards() + ['lock_version' => ['prohibited'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.config('evidence.max_files')],
            'items.*' => ['required', 'array:file,evidence_type_id,caption,notes,captured_at'],
            'items.*.file' => ['required', 'file', 'max:'.((int) config('evidence.max_size_mb') * 1024)]];
        foreach (self::metadataRules() as $key => $rule) {
            $rules['items.*.'.$key] = $rule;
        }

        return $rules;
    }

    public static function updateRules(): array
    {
        return self::guards() + self::metadataRules() + self::versionRules() + ['file' => ['prohibited'], 'items' => ['prohibited']];
    }

    public static function lifecycleRules(): array
    {
        return self::guards() + self::versionRules() + ['deletion_notes' => ['nullable', 'string', 'max:2000']];
    }

    public static function versionRules(): array
    {
        return ['lock_version' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
            if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
                $fail('La versión debe ser un entero positivo.');
            }
        }, 'integer', 'min:1', 'max:2147483646']];
    }

    public static function filters(): array
    {
        return ['evidence_type_id' => ['nullable', 'uuid'], 'trashed' => ['nullable', 'in:only'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']];
    }

    public static function messages(): array
    {
        return ['items.required' => 'Selecciona al menos una imagen.', 'items.max' => 'El lote supera la cantidad máxima de imágenes.',
            'items.list' => 'La lista de archivos no es válida.', 'items.*.array' => 'Los datos del archivo no son válidos.',
            'items.*.file.required' => 'Selecciona una imagen.', 'items.*.file.file' => 'El archivo no es una imagen válida.',
            'items.*.file.uploaded' => 'La imagen no pudo cargarse o supera el tamaño máximo permitido.',
            'items.*.file.max' => 'La imagen supera el tamaño máximo permitido.',
            'items.*.evidence_type_id.required' => 'El tipo de evidencia es obligatorio.',
            'evidence_type_id.required' => 'El tipo de evidencia es obligatorio.',
            '*.uuid' => 'Selecciona una referencia válida.', '*.prohibited' => 'Este dato se determina en el servidor.',
            'captured_at.date_format' => 'Indica una fecha de captura válida en UTC.',
            'items.*.captured_at.date_format' => 'Indica una fecha de captura válida en UTC.',
            '*.max' => 'El valor supera el límite permitido.', 'lock_version.required' => 'La versión del registro es obligatoria.'];
    }

    public static function owner(?string $treeId, ?string $inspectionId, ?string $maintenanceId = null): void
    {
        if (count(array_filter([$treeId, $inspectionId, $maintenanceId], fn ($id) => $id !== null)) !== 1) {
            throw ValidationException::withMessages(['owner' => 'La evidencia debe pertenecer exactamente a un árbol, una inspección o un mantenimiento.']);
        }
        Validator::make(['tree_id' => $treeId, 'tree_inspection_id' => $inspectionId, 'maintenance_id' => $maintenanceId], [
            'tree_id' => ['nullable', 'uuid'], 'tree_inspection_id' => ['nullable', 'uuid'], 'maintenance_id' => ['nullable', 'uuid'],
        ], self::messages())->validate();
    }
}

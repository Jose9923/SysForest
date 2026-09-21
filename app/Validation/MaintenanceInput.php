<?php

declare(strict_types=1);

namespace App\Validation;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use Closure;
use Illuminate\Validation\Rule;

class MaintenanceInput
{
    public static function guards(): array
    {
        return array_fill_keys(['id', 'organization_id', 'tree_id', 'code', 'created_by', 'updated_by',
            'created_at', 'updated_at', 'deleted_at', 'deleted_by'], ['prohibited']);
    }

    public static function rules(bool $updating = false): array
    {
        return self::guards() + [
            'maintenance_type_id' => ['required', 'uuid'],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', ...self::userIdRules()],
            'notes' => ['nullable', 'string', 'max:20000'],
            'status' => ['prohibited'], 'result' => ['prohibited'], 'performed_date' => ['prohibited'],
            'deletion_notes' => ['prohibited'], 'reason' => ['prohibited'],
        ] + ($updating ? self::versionRules() : ['lock_version' => ['prohibited']]);
    }

    public static function transitionRules(): array
    {
        return self::guards() + self::versionRules() + [
            'status' => ['required', Rule::enum(MaintenanceStatus::class)],
            'reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:2000'],
            'scheduled_date' => ['nullable', 'prohibited_unless:status,scheduled', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', 'prohibited_unless:status,assigned', ...self::userIdRules()],
            'performed_date' => ['required_if:status,executed', 'nullable', 'prohibited_unless:status,executed',
                'date_format:Y-m-d', 'before_or_equal:'.now('UTC')->format('Y-m-d')],
            'result' => ['required_if:status,executed', 'nullable', 'prohibited_unless:status,executed', 'string', 'max:20000'],
            'maintenance_type_id' => ['prohibited'], 'priority' => ['prohibited'],
            'notes' => ['prohibited'], 'deletion_notes' => ['prohibited'],
        ];
    }

    public static function lifecycleRules(): array
    {
        return self::guards() + self::versionRules() + [
            'deletion_notes' => ['nullable', 'string', 'max:2000'], 'reason' => ['nullable', 'string', 'max:2000'],
            'maintenance_type_id' => ['prohibited'], 'priority' => ['prohibited'], 'status' => ['prohibited'],
            'scheduled_date' => ['prohibited'], 'assigned_to' => ['prohibited'],
            'performed_date' => ['prohibited'], 'result' => ['prohibited'], 'notes' => ['prohibited'],
        ];
    }

    public static function filters(): array
    {
        return ['q' => ['nullable', 'string', 'max:200'], 'tree_id' => ['nullable', 'uuid'],
            'maintenance_type_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::enum(MaintenanceStatus::class)],
            'priority' => ['nullable', Rule::enum(MaintenancePriority::class)],
            'assigned_to' => ['nullable', ...self::userIdRules()], 'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'], 'trashed' => ['nullable', 'in:only']];
    }

    public static function versionRules(): array
    {
        return ['lock_version' => ['required', self::canonicalInteger(), 'integer', 'min:1', 'max:2147483646']];
    }

    /** Alinea los servicios directos con la normalización de campos opcionales de HTTP. */
    public static function normalize(array $input): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $input);
    }

    public static function messages(): array
    {
        return ['required' => 'Este dato es obligatorio.', 'required_if' => 'Este dato es obligatorio para el estado elegido.',
            'uuid' => 'Selecciona una referencia válida.', 'integer' => 'Introduce un entero válido.',
            'min' => 'El valor está por debajo del mínimo permitido.', 'max' => 'El valor supera el límite permitido.',
            'enum' => 'Selecciona una opción válida.', 'in' => 'Selecciona una opción válida.', 'string' => 'Introduce un texto válido.',
            'date_format' => 'Introduce una fecha válida con formato AAAA-MM-DD.',
            'before_or_equal' => 'La ejecución no puede tener una fecha futura.',
            'prohibited' => 'Este dato se determina en el servidor o en una operación diferente.',
            'prohibited_unless' => 'Este dato no corresponde a la transición elegida.'];
    }

    private static function userIdRules(): array
    {
        return [self::canonicalInteger(), 'integer', 'min:1'];
    }

    private static function canonicalInteger(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
                $fail('Introduce un entero positivo sin decimales.');
            }
        };
    }
}

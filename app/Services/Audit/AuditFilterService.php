<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\AuditScope;
use App\Auditing\AuditCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Los mismos filtros tipados se aplican a todas las fuentes del historial privado. */
class AuditFilterService
{
    public const KEYS = ['scope', 'organization_id', 'actor', 'user_id', 'module', 'event', 'resource_type', 'resource_id', 'date_from', 'date_to', 'page'];

    public function validate(array $input): array
    {
        $input = array_map(fn ($value) => $value === '' ? null : $value, $input);
        if (array_diff(array_keys($input), self::KEYS) !== []) {
            throw ValidationException::withMessages(['filters' => 'La consulta contiene filtros no admitidos.']);
        }

        $values = Validator::make($input, [
            'scope' => ['nullable', Rule::in(['national', 'department', 'organization'])],
            'organization_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'module' => ['nullable', Rule::in(array_keys(AuditCatalog::modules()))],
            'actor' => ['nullable', 'string', 'max:120'],
            'event' => ['nullable', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9_.]{2,79}\z/D'],
            'resource_type' => ['nullable', Rule::in(array_keys(AuditPresentationService::TYPES))],
            'resource_id' => ['nullable', 'string', 'max:100', 'regex:/\A(?:[0-9]+|[a-fA-F0-9-]{36})\z/D'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:9999-12-31'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:9999-12-31'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [
            'string' => 'El filtro :attribute debe ser texto.',
            'regex' => 'El filtro :attribute no tiene un formato válido.',
            'uuid' => 'Selecciona una organización válida.',
            'date_format' => 'Usa una fecha válida con formato AAAA-MM-DD.',
            'after_or_equal' => 'La fecha debe pertenecer al calendario, desde el año 0001.',
            'before_or_equal' => 'La fecha debe estar dentro del año 9999 o anterior.',
            'integer' => 'La página debe ser un número entero.',
            'in' => 'El alcance solicitado no está disponible.',
        ])->validate();
        if (isset($values['user_id'])) {
            $values['user_id'] = (string) $values['user_id'];
        }
        if (isset($values['resource_id'])) {
            $id = $values['resource_id'];
            if (!\Illuminate\Support\Str::isUuid($id) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) !== 1) {
                throw ValidationException::withMessages(['resource_id' => 'Indica un UUID o un identificador numérico válido.']);
            }
            $values['resource_id'] = strtolower($id);
        }

        return $values;
    }

    public function resolve(array $input, AuditScope $scope): array
    {
        $values = $this->validate($input);
        if ($scope->kind === 'department' && !empty($values['organization_id'])
            && !in_array($values['organization_id'], $scope->organizationIds, true)) {
            throw ValidationException::withMessages(['organization_id' => 'Selecciona una institución de tus departamentos autorizados.']);
        }
        if ($scope->kind === 'organization' && !empty($values['organization_id'])
            && $values['organization_id'] !== $scope->organizationId) {
            throw ValidationException::withMessages(['organization_id' => 'La consulta está limitada a la organización seleccionada.']);
        }
        $from = CarbonImmutable::parse($values['date_from'] ?? CarbonImmutable::now('UTC')->subDays(29)->toDateString(), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($values['date_to'] ?? CarbonImmutable::now('UTC')->toDateString(), 'UTC')->startOfDay();
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }
        $values = array_filter($values, fn ($value): bool => $value !== null && $value !== '');
        $values['scope'] = $scope->kind;
        $values['date_from'] = $from->toDateString();
        $values['date_to'] = $to->toDateString();
        if ($scope->kind === 'organization') {
            unset($values['organization_id']);
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Trees;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Traduce el calendario institucional a UTC sin depender de la zona del navegador. */
class TreeCaptureDateService
{
    private const TIMEZONE = 'America/Bogota';

    public function inputValue(?DateTimeInterface $value): string
    {
        return $value === null ? '' : CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i:s.v');
    }

    public function displayValue(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE)->format('d/m/Y H:i:s.u').' (hora de Colombia)';
    }

    /**
     * El formulario usa hora colombiana; captured_at conserva su contrato ISO para integraciones.
     * Una fecha omitida no modifica el registro y un calendario vacío la elimina explícitamente.
     * Si el valor visible no cambia, conserva los microsegundos almacenados que HTML no representa.
     *
     * @throws ValidationException Si hay formatos simultáneos o una fecha local inválida.
     */
    public function normalizeInput(array $input, ?DateTimeInterface $current = null): array
    {
        if (!array_key_exists('captured_at_local', $input)) {
            return $input;
        }
        if (array_key_exists('captured_at', $input)) {
            throw ValidationException::withMessages(['captured_at_local' => 'Envía la fecha del calendario o la fecha ISO, no ambas.']);
        }
        $values = Validator::make($input, ['captured_at_local' => [
            'nullable', 'string', 'max:30', 'date',
            'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,3})?)?\z/D',
        ]], [
            'captured_at_local.date' => 'Selecciona una fecha y hora de captura válidas.',
            'captured_at_local.regex' => 'Selecciona la fecha y hora en el calendario, usando la hora de Colombia.',
            'captured_at_local.string' => 'La fecha y hora de captura no es válida.',
            'captured_at_local.max' => 'La fecha y hora de captura no es válida.',
        ])->validate();
        $value = trim((string) ($values['captured_at_local'] ?? ''));
        unset($input['captured_at_local']);
        if ($value === '') {
            $input['captured_at'] = null;

            return $input;
        }
        $date = CarbonImmutable::parse($value, self::TIMEZONE);
        if ($current !== null && $this->inputValue($date) === $this->inputValue($current)) {
            $date = CarbonImmutable::instance($current);
        }
        $input['captured_at'] = $date->utc()->format('Y-m-d\TH:i:s.uP');

        return $input;
    }
}

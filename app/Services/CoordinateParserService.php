<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ParsedCoordinates;
use Illuminate\Validation\ValidationException;

class CoordinateParserService
{
    /** El orden de entrada es latitud, longitud; PostGIS recibirá longitud, latitud. */
    public function parse(string $raw): ParsedCoordinates
    {
        $value = trim($raw);
        $decimal = '~\A([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\z~D';
        if (preg_match($decimal, $value, $matches) === 1) {
            $latitude = $this->decimalComponent($matches[1], 90);
            $longitude = $this->decimalComponent($matches[2], 180);

            return new ParsedCoordinates($latitude, $longitude, $raw, 'decimal');
        }

        $component = '(\d+)\s*[°º]\s*(\d+)\s*[\'′’]\s*(\d+(?:\.\d+)?)\s*["″”]\s*';
        $dms = '~\A'.$component.'([NS])(?:\s*,\s*|\s+)'.$component.'([EW])\z~iuD';
        if (preg_match($dms, $value, $matches) !== 1) {
            $this->invalid('Usa latitud, longitud con punto decimal, o grados ° minutos \' segundos " y hemisferios N/S y E/W. Ejemplo: 4.154, -73.633.');
        }

        $latitude = $this->dmsComponent($matches[1], $matches[2], $matches[3], strtoupper($matches[4]), 90);
        $longitude = $this->dmsComponent($matches[5], $matches[6], $matches[7], strtoupper($matches[8]), 180);

        return new ParsedCoordinates($latitude, $longitude, $raw, 'dms');
    }

    /** Comprueba también fracciones fuera del límite que un float podría redondear. */
    private function decimalComponent(string $value, int $maximum): float
    {
        $unsigned = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $limit = (string) $maximum;
        if (strlen($integer) > strlen($limit)
            || (strlen($integer) === strlen($limit) && strcmp($integer, $limit) > 0)
            || ($integer === $limit && trim($fraction, '0') !== '')) {
            $this->invalid('La latitud debe estar entre -90 y 90 y la longitud entre -180 y 180 grados.');
        }
        $number = (float) $value;
        if (! is_finite($number)) {
            $this->invalid('Las coordenadas deben ser números finitos.');
        }

        return $number == 0.0 ? 0.0 : $number;
    }

    private function dmsComponent(string $degrees, string $minutes, string $seconds, string $hemisphere, int $maximum): float
    {
        $degree = (float) $degrees;
        $minute = (float) $minutes;
        $second = (float) $seconds;
        if (! is_finite($degree) || ! is_finite($minute) || ! is_finite($second)
            || $degree > $maximum || $minute >= 60 || $second >= 60
            || ($degree === (float) $maximum && ($minute > 0 || $second > 0))) {
            $this->invalid('Revisa los grados y hemisferios: latitud hasta 90°, longitud hasta 180°, minutos y segundos menores de 60.');
        }
        $number = $degree + $minute / 60 + $second / 3600;

        return in_array($hemisphere, ['S', 'W'], true) && $number !== 0.0 ? -$number : $number;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['coordinate_raw' => $message]);
    }
}

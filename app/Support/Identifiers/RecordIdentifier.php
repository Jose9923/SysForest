<?php

declare(strict_types=1);

namespace App\Support\Identifiers;

/** Clave SQL BIGINT positiva, sin convertirla a float ni perder precisión en JSON. */
final class RecordIdentifier
{
    public const MAX = '9223372036854775807';

    public static function isValid(mixed $value): bool
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $value = (string) $value;

        return preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, self::MAX) <= 0);
    }
}

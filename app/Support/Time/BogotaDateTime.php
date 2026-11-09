<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Presentación colombiana de instantes; las fechas calendario conservan su valor. */
final class BogotaDateTime
{
    public const TIMEZONE = 'America/Bogota';

    public static function format(DateTimeInterface|string|null $value, string $format = 'd/m/Y H:i'): ?string
    {
        return self::local($value)?->format($format);
    }

    public static function iso(DateTimeInterface|string|null $value): ?string
    {
        return self::format($value, 'Y-m-d\TH:i:s.uP');
    }

    public static function input(DateTimeInterface|string|null $value): ?string
    {
        return self::format($value, 'Y-m-d\TH:i');
    }

    public static function startOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay()->utc();
    }

    /** Límite superior exclusivo de un día del calendario de Bogotá. */
    public static function afterDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay()->addDay()->utc();
    }

    private static function local(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Las marcas sin offset de la aplicación y sus columnas legacy se escriben en UTC.
        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return $instant->setTimezone(self::TIMEZONE);
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum TreeStatus: string
{
    case Active = 'active';
    case UnderObservation = 'under_observation';
    case Dead = 'dead';
    case Felled = 'felled';
    case Transplanted = 'transplanted';
    case Removed = 'removed';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Sano',
            self::UnderObservation => 'En observación',
            self::Dead => 'Muerto',
            self::Felled => 'Talado',
            self::Transplanted => 'Trasplantado',
            self::Removed => 'Retirado',
            self::Replaced => 'Reemplazado',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

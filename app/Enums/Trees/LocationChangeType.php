<?php

declare(strict_types=1);

namespace App\Enums\Trees;

enum LocationChangeType: string
{
    case Correction = 'correction';
    case Relocation = 'relocation';
    case Transplant = 'transplant';

    public function label(): string
    {
        return match ($this) {
            self::Correction => 'Corrección de coordenadas',
            self::Relocation => 'Traslado',
            self::Transplant => 'Trasplante',
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

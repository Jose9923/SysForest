<?php

declare(strict_types=1);

namespace App\Enums;

/** Prioridad ordinal de gestión, independiente del estado operativo del árbol. */
enum RiskPriority: int
{
    case Low = 1;
    case Moderate = 2;
    case High = 3;
    case Critical = 4;

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Bajo', self::Moderate => 'Moderado', self::High => 'Alto', self::Critical => 'Crítico',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => '#247858', self::Moderate => '#b87913', self::High => '#c65d16', self::Critical => '#b42318',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Low => 'Seguimiento rutinario.',
            self::Moderate => 'Seguimiento fitosanitario programado.',
            self::High => 'Evaluación profesional y manejo prioritario.',
            self::Critical => 'Revisión profesional prioritaria y definición de intervención.',
        };
    }
}

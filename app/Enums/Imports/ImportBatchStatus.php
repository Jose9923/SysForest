<?php

declare(strict_types=1);

namespace App\Enums\Imports;

enum ImportBatchStatus: string
{
    case Ready = 'ready';
    case Invalid = 'invalid';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Listo para confirmar',
            self::Invalid => 'Requiere correcciones',
            self::Completed => 'Importado',
            self::Cancelled => 'Cancelado',
        };
    }
}

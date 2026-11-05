<?php

declare(strict_types=1);

namespace App\Enums\Maintenances;

enum MaintenanceStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Executed = 'executed';
    case Verified = 'verified';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente', self::Scheduled => 'Programado', self::Assigned => 'Asignado',
            self::InProgress => 'En ejecución', self::Executed => 'Ejecutado',
            self::Verified => 'Verificado', self::Cancelled => 'Cancelado',
        };
    }

    public function allowsMetadataChanges(): bool
    {
        return in_array($this, [self::Pending, self::Scheduled, self::Assigned, self::InProgress], true);
    }
}

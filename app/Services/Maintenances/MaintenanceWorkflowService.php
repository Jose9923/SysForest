<?php

declare(strict_types=1);

namespace App\Services\Maintenances;

use App\Enums\Maintenances\MaintenanceStatus;
use App\Models\Maintenances\Maintenance;
use App\Models\User;
use App\Repositories\Maintenances\MaintenanceReferenceRepository;
use Illuminate\Validation\ValidationException;

/** Transiciones explícitas: ninguna cambia el estado, ubicación ni mediciones de Tree. */
class MaintenanceWorkflowService
{
    public function __construct(private MaintenanceReferenceRepository $references)
    {
    }

    public function allowedTransitions(Maintenance $maintenance): array
    {
        if ($maintenance->trashed() || $maintenance->tree->trashed()) {
            return [];
        }

        return match ($maintenance->status) {
            MaintenanceStatus::Pending => ['scheduled', 'assigned', 'cancelled'],
            MaintenanceStatus::Scheduled => ['assigned', 'cancelled'],
            MaintenanceStatus::Assigned => ['scheduled', 'in_progress', 'cancelled'],
            MaintenanceStatus::InProgress => ['executed', 'cancelled'],
            MaintenanceStatus::Executed => ['verified'],
            MaintenanceStatus::Verified, MaintenanceStatus::Cancelled => [],
        };
    }

    /** Devuelve exclusivamente los cambios de estado verificados; la escritura y auditoría son atómicas. */
    public function transition(Maintenance $maintenance, User $actor, array $data): array
    {
        $status = $data['status'];
        if (!in_array($status, $this->allowedTransitions($maintenance), true)) {
            throw ValidationException::withMessages(['status' => 'La transición no está permitida desde el estado actual.']);
        }
        $changes = ['status' => $status];
        if ($status === 'scheduled') {
            $date = $data['scheduled_date'] ?? $maintenance->scheduled_date?->format('Y-m-d');
            if ($date === null) {
                throw ValidationException::withMessages(['scheduled_date' => 'La programación requiere una fecha.']);
            }
            $changes += ['scheduled_date' => $date, 'assigned_to' => null];
        }
        if ($status === 'assigned') {
            $id = $data['assigned_to'] ?? $maintenance->assigned_to;
            if ($id === null || !$this->references->validAssignee($maintenance->organization_id, (int) $id, true)) {
                throw ValidationException::withMessages(['assigned_to' => 'Selecciona un responsable institucional vigente.']);
            }
            $changes['assigned_to'] = (int) $id;
        }
        if (in_array($status, ['in_progress', 'executed'], true)) {
            if ($maintenance->assigned_to === null || !$this->references->validAssignee($maintenance->organization_id, $maintenance->assigned_to, true)) {
                throw ValidationException::withMessages(['assigned_to' => 'El responsable debe mantener su cuenta, membresía y rol vigentes.']);
            }
        }
        if ($status === 'executed') {
            $changes += ['performed_date' => $data['performed_date'], 'result' => $data['result']];
        }

        return $changes;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MaintenanceStatus;
use App\Models\Maintenance;
use App\Models\User;
use App\Repositories\MaintenanceReferenceRepository;
use App\Repositories\MaintenanceRepository;
use App\Repositories\MaintenanceStatusHistoryRepository;
use App\Repositories\OrganizationSequenceRepository;
use App\Repositories\TreeRepository;
use App\Repositories\UserRepository;
use App\Validation\MaintenanceInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MaintenanceService
{
    public function __construct(
        private UserRepository $users,
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private MaintenanceRepository $maintenances,
        private MaintenanceReferenceRepository $references,
        private OrganizationSequenceRepository $sequences,
        private MaintenanceWriteService $writes,
        private MaintenanceAuditService $audit,
        private MaintenanceWorkflowService $workflow,
        private MaintenanceStatusHistoryRepository $histories
    ) {
    }

    public function create(User $actor, string $treeId, array $input): Maintenance
    {
        return DB::transaction(function () use ($actor, $treeId, $input): Maintenance {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $tree = $this->trees->findForTenant($organization->id, $treeId, true, true);
            Gate::forUser($actor)->authorize('create', [Maintenance::class, $tree]);
            $data = Validator::make(
                array_replace(['priority' => 'normal'], MaintenanceInput::normalize($input)),
                MaintenanceInput::rules(),
                MaintenanceInput::messages()
            )->validate();
            $this->validateReferences($organization->id, $data);
            $code = 'MNT-'.str_pad((string) $this->sequences->nextMaintenanceValue($organization->id), 6, '0', STR_PAD_LEFT);
            $maintenance = $this->maintenances->create($this->metadata($data) + [
                'organization_id' => $organization->id, 'tree_id' => $tree->id, 'code' => $code,
                'status' => MaintenanceStatus::Pending, 'created_by' => $actor->id,
                'updated_by' => $actor->id, 'lock_version' => 1,
            ]);
            $this->histories->record($organization->id, $maintenance->id, (int) $actor->id, 1, null, 'pending');
            $this->audit->record($actor, $maintenance, [], 'maintenance.created');

            return $maintenance;
        }, 3);
    }

    public function update(User $actor, string $id, array $input): Maintenance
    {
        return $this->writes->run($actor, $id, 'update', $input, function (Maintenance $maintenance, User $actor, array $version) use ($input): void {
            $current = ['maintenance_type_id' => $maintenance->maintenance_type_id, 'priority' => $maintenance->priority->value,
                'scheduled_date' => $maintenance->scheduled_date?->format('Y-m-d'),
                'assigned_to' => $maintenance->assigned_to, 'notes' => $maintenance->notes];
            $data = Validator::make(
                array_replace($current, MaintenanceInput::normalize($input)),
                MaintenanceInput::rules(true),
                MaintenanceInput::messages()
            )->validate();
            $this->validateReferences($maintenance->organization_id, $data, $maintenance);
            if ($maintenance->status === MaintenanceStatus::Scheduled && ($data['scheduled_date'] ?? null) === null) {
                throw ValidationException::withMessages(['scheduled_date' => 'Una orden programada requiere una fecha.']);
            }
            if (in_array($maintenance->status, [MaintenanceStatus::Assigned, MaintenanceStatus::InProgress], true)
                && ($data['assigned_to'] ?? null) === null) {
                throw ValidationException::withMessages(['assigned_to' => 'La orden requiere responsable. Usa la transición a programado para retirar una asignación.']);
            }
            $this->maintenances->update($maintenance, $this->metadata($data) + $version);
        }, 'maintenance.updated');
    }

    public function transition(User $actor, string $id, array $input): Maintenance
    {
        $ability = ($input['status'] ?? null) === 'verified' ? 'verify' : 'transition';

        return $this->writes->run($actor, $id, $ability, $input, function (Maintenance $maintenance, User $actor, array $version) use ($input): void {
            $data = Validator::make(MaintenanceInput::normalize($input), MaintenanceInput::transitionRules(), MaintenanceInput::messages())->validate();
            $oldStatus = $maintenance->status->value;
            $changes = $this->workflow->transition($maintenance, $actor, $data);
            $updated = $this->maintenances->update($maintenance, $changes + $version);
            $this->histories->record(
                $updated->organization_id,
                $updated->id,
                (int) $actor->id,
                $updated->lock_version,
                $oldStatus,
                $updated->status->value,
                $data['reason'] ?? null
            );
        }, 'maintenance.status_changed');
    }

    public function void(User $actor, string $id, array $input): Maintenance
    {
        return $this->writes->run($actor, $id, 'void', $input, function (Maintenance $maintenance, User $actor, array $version) use ($input): void {
            $data = Validator::make(MaintenanceInput::normalize($input), MaintenanceInput::lifecycleRules(), MaintenanceInput::messages())->validate();
            $reason = $data['deletion_notes'] ?? $data['reason'] ?? null;
            if ($reason === null || trim($reason) === '') {
                throw ValidationException::withMessages(['deletion_notes' => 'Indica el motivo de anulación del registro erróneo.']);
            }
            $this->maintenances->void($maintenance, $version + ['deleted_by' => $actor->id, 'deletion_notes' => $reason]);
        }, 'maintenance.voided');
    }

    public function restore(User $actor, string $id, array $input): Maintenance
    {
        return $this->writes->run($actor, $id, 'restore', $input, function (Maintenance $maintenance, User $actor, array $version) use ($input): void {
            Validator::make($input, MaintenanceInput::lifecycleRules(), MaintenanceInput::messages())->validate();
            $this->maintenances->restore($maintenance, $version);
        }, 'maintenance.restored');
    }

    private function validateReferences(string $organizationId, array $data, ?Maintenance $current = null): void
    {
        if (!$this->references->validType($data['maintenance_type_id'], $current?->maintenance_type_id, true)) {
            throw ValidationException::withMessages(['maintenance_type_id' => 'Selecciona un tipo de mantenimiento activo.']);
        }
        $assigned = $data['assigned_to'] ?? null;
        if ($assigned !== null && ($current === null || (int) $assigned !== $current->assigned_to)
            && !$this->references->validAssignee($organizationId, (int) $assigned, true)) {
            throw ValidationException::withMessages(['assigned_to' => 'Selecciona una cuenta activa, verificada y con rol vigente en esta organización.']);
        }
    }

    private function metadata(array $data): array
    {
        return ['maintenance_type_id' => $data['maintenance_type_id'], 'priority' => $data['priority'],
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'notes' => $data['notes'] ?? null];
    }
}

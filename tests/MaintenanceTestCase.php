<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Maintenance;
use App\Models\MaintenanceType;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\MaintenanceService;

/** Órdenes y fotografías de prueba: siempre dentro de sysforest_testing y su transacción. */
abstract class MaintenanceTestCase extends EvidenceTestCase
{
    protected function maintenanceInput(array $overrides = []): array
    {
        return array_replace([
            'maintenance_type_id' => MaintenanceType::query()->where('code', 'MNT_PRUNING')->sole()->id,
            'priority' => 'normal', 'scheduled_date' => '2026-10-01',
            'notes' => 'Orden manual de prueba',
        ], $overrides);
    }

    protected function order(array $overrides = [], ?User $actor = null, ?Tree $tree = null): Maintenance
    {
        return app(MaintenanceService::class)->create(
            $actor ?? $this->admin,
            ($tree ?? $this->tree)->id,
            $this->maintenanceInput($overrides)
        );
    }

    protected function advance(Maintenance $order, string $status, array $overrides = [], ?User $actor = null): Maintenance
    {
        return app(MaintenanceService::class)->transition($actor ?? $this->admin, $order->id, array_replace([
            'status' => $status, 'lock_version' => $order->lock_version,
        ], $overrides));
    }

    protected function executedOrder(): Maintenance
    {
        $order = $this->order(['assigned_to' => $this->admin->id]);
        $order = $this->advance($order, 'assigned');
        $order = $this->advance($order, 'in_progress');

        return $this->advance($order, 'executed', [
            'performed_date' => now()->toDateString(), 'result' => 'Intervención registrada durante la prueba.',
        ]);
    }

    protected function maintenanceActor(string $role): User
    {
        $actor = User::factory()->create();
        if ($role === 'SUPER_ADMIN') {
            $this->allowNationalManagement($actor);
        } elseif ($role === 'DEPARTMENT_ADMIN') {
            app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $this->organization->municipality->department_id);
        } else {
            $membership = $this->member($actor, $this->organization);
            app(RoleAssignmentRepository::class)->grantOrganization($membership, $role);
        }

        return $actor;
    }
}

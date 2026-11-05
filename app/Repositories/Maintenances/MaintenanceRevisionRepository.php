<?php

declare(strict_types=1);

namespace App\Repositories\Maintenances;

use App\Models\Maintenances\MaintenanceRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class MaintenanceRevisionRepository
{
    public function __construct(private MaintenanceRepository $maintenances)
    {
    }

    public function record(string $organizationId, string $id, int $actorId, int $version, array $old, array $new, string $event): MaintenanceRevision
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La revisión requiere la transacción de su orden.');
        }
        $this->maintenances->findForTenant($organizationId, $id, false, true);
        $revision = new MaintenanceRevision();
        $revision->forceFill(['organization_id' => $organizationId, 'maintenance_id' => $id, 'changed_by' => $actorId,
            'version' => $version, 'changed_at' => now(), 'old_values' => (object) $old,
            'new_values' => (object) $new, 'event' => $event])->save();

        return $revision;
    }

    public function forMaintenance(string $organizationId, string $id): Collection
    {
        $this->maintenances->findForTenant($organizationId, $id, false, true);

        return MaintenanceRevision::query()->where('organization_id', $organizationId)->where('maintenance_id', $id)
            ->with('changedBy')->orderByDesc('version')->get();
    }
}

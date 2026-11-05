<?php

declare(strict_types=1);

namespace App\Repositories\Maintenances;

use App\Models\Maintenances\MaintenanceStatusHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class MaintenanceStatusHistoryRepository
{
    public function __construct(private MaintenanceRepository $maintenances)
    {
    }

    public function record(string $organizationId, string $id, int $actorId, int $version, ?string $old, string $new, ?string $reason = null): MaintenanceStatusHistory
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La transición requiere la transacción de su orden.');
        }
        $this->maintenances->findForTenant($organizationId, $id, false, true);
        $history = new MaintenanceStatusHistory();
        $history->forceFill(['organization_id' => $organizationId, 'maintenance_id' => $id, 'changed_by' => $actorId,
            'version' => $version, 'changed_at' => now(), 'old_status' => $old, 'new_status' => $new, 'reason' => $reason])->save();

        return $history;
    }

    public function forMaintenance(string $organizationId, string $id): Collection
    {
        $this->maintenances->findForTenant($organizationId, $id, false, true);

        return MaintenanceStatusHistory::query()->where('organization_id', $organizationId)->where('maintenance_id', $id)
            ->with('changedBy')->orderByDesc('version')->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Maintenances;

use App\Models\Maintenances\Maintenance;
use App\Models\User;
use App\Services\Audit\AuditSnapshotService;
use App\Repositories\Maintenances\MaintenanceRevisionRepository;
use App\Repositories\Auth\SecurityEventRepository;

class MaintenanceAuditService
{
    public function __construct(private MaintenanceRevisionRepository $revisions, private SecurityEventRepository $events, private AuditSnapshotService $snapshots)
    {
    }

    public function snapshot(Maintenance $maintenance): array
    {
        return $this->snapshots->sanitize('maintenance', array_intersect_key($maintenance->attributesToArray(), array_flip([
            'tree_id', 'code', 'maintenance_type_id', 'status', 'priority', 'scheduled_date', 'performed_date',
            'assigned_to', 'notes', 'result', 'created_by', 'updated_by', 'created_at', 'lock_version',
            'deleted_at', 'deleted_by', 'deletion_notes',
        ])));
    }

    public function record(User $actor, Maintenance $maintenance, array $old, string $event): void
    {
        $revision = $this->revisions->record(
            $maintenance->organization_id,
            $maintenance->id,
            (int) $actor->id,
            $maintenance->lock_version,
            $old,
            $this->snapshot($maintenance),
            $event
        );
        $this->events->recordChange(
            $event,
            $actor,
            $maintenance->organization_id,
            'maintenance',
            $maintenance->id,
            $old,
            $this->snapshot($maintenance),
            ['resource_id' => $maintenance->id, 'revision_id' => $revision->id]
        );
    }
}

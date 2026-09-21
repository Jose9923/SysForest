<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Maintenance;
use App\Models\User;
use App\Repositories\MaintenanceRevisionRepository;
use App\Repositories\SecurityEventRepository;

class MaintenanceAuditService
{
    public function __construct(private MaintenanceRevisionRepository $revisions, private SecurityEventRepository $events)
    {
    }

    public function snapshot(Maintenance $maintenance): array
    {
        return array_intersect_key($maintenance->attributesToArray(), array_flip([
            'tree_id', 'code', 'maintenance_type_id', 'status', 'priority', 'scheduled_date', 'performed_date',
            'assigned_to', 'notes', 'result', 'created_by', 'updated_by', 'lock_version',
            'deleted_at', 'deleted_by', 'deletion_notes',
        ]));
    }

    public function record(User $actor, Maintenance $maintenance, array $old, string $event): void
    {
        $this->revisions->record(
            $maintenance->organization_id,
            $maintenance->id,
            (int) $actor->id,
            $maintenance->lock_version,
            $old,
            $this->snapshot($maintenance),
            $event
        );
        $this->events->record($event, $actor, $maintenance->organization_id, ['resource_id' => $maintenance->id]);
    }
}

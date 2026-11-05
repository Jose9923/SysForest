<?php

declare(strict_types=1);

namespace App\Services\Publication;

use App\Models\Evidences\Evidence;
use App\Models\Inventories\Inventory;
use App\Models\Organizations\Organization;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Publication\PublicationRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Services\Audit\AuditSnapshotService;

class PublicationAuditService
{
    public function __construct(private PublicationRepository $records, private SecurityEventRepository $events, private AuditSnapshotService $snapshots)
    {
    }

    public function snapshot(Organization|Inventory|Tree|TreeInspection|Evidence $record): array
    {
        $values = ['is_public' => $record->is_public, 'publication_version' => $record->publication_version];
        if ($record instanceof Organization) {
            $values['public_code'] = $record->public_code;
        }
        if ($record instanceof Evidence) {
            $values['public_id'] = $record->public_id;
            $values['public_caption'] = $record->public_caption;
        }

        return $values;
    }

    public function record(User $actor, Organization|Inventory|Tree|TreeInspection|Evidence $record, array $old): void
    {
        $revision = $this->records->recordRevision($record, (int) $actor->id, $old, $this->snapshot($record));
        $this->events->record(
            'publication.changed',
            $actor,
            $record instanceof Organization ? $record->id : $record->organization_id,
            ['resource_id' => $record->id, 'revision_id' => $revision->id],
            $this->snapshots->type($record),
            $record->id,
            $old,
            $this->snapshot($record)
        );
    }
}

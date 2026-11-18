<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Models\Evidences\Evidence;
use App\Models\User;
use App\Services\Audit\AuditSnapshotService;
use App\Repositories\Evidences\EvidenceRevisionRepository;
use App\Repositories\Auth\SecurityEventRepository;

/** La revisión pertenece a la misma transacción: nunca contiene bytes, credenciales ni rutas. */
class EvidenceAuditService
{
    public function __construct(private EvidenceRevisionRepository $revisions, private SecurityEventRepository $events, private AuditSnapshotService $snapshots)
    {
    }

    public function snapshot(Evidence $evidence): array
    {
        return $this->snapshots->sanitize('evidence', array_intersect_key($evidence->attributesToArray(), array_flip([
            'tree_id', 'tree_inspection_id', 'maintenance_id', 'evidence_type_id', 'caption', 'notes', 'captured_at',
            'uploaded_by', 'updated_by', 'created_at', 'deleted_at', 'deleted_by', 'deletion_notes', 'lock_version',
        ])));
    }

    public function record(User $actor, Evidence $evidence, array $old, string $event): void
    {
        $revision = $this->revisions->record(
            $evidence->organization_id,
            $evidence->id,
            (int) $actor->id,
            $evidence->lock_version,
            $old,
            $this->snapshot($evidence),
            $event
        );
        $this->events->recordChange(
            $event,
            $actor,
            $evidence->organization_id,
            'evidence',
            $evidence->id,
            $old,
            $this->snapshot($evidence),
            ['resource_id' => $evidence->id, 'revision_id' => $revision->id]
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Evidences;

use App\Models\Evidences\Evidence;
use App\Models\Evidences\EvidenceRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class EvidenceRevisionRepository
{
    public function record(
        string $organizationId,
        string $evidenceId,
        int $actorId,
        int $version,
        array $oldValues,
        array $newValues,
        string $event,
    ): EvidenceRevision {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La revisión debe confirmarse junto con la evidencia.');
        }
        $this->requireIds($organizationId, $evidenceId);
        Evidence::withTrashed()->where('organization_id', $organizationId)->whereKey($evidenceId)->firstOrFail();
        $revision = new EvidenceRevision();
        $revision->forceFill([
            'organization_id' => $organizationId, 'evidence_id' => $evidenceId,
            'changed_by' => $actorId, 'version' => $version, 'changed_at' => now(), 'event' => $event,
            'old_values' => (object) $oldValues, 'new_values' => (object) $newValues,
        ])->save();

        return $revision;
    }

    public function forEvidence(string $organizationId, string $evidenceId): Collection
    {
        $this->requireIds($organizationId, $evidenceId);

        return EvidenceRevision::query()->where('organization_id', $organizationId)->where('evidence_id', $evidenceId)
            ->with('changedBy')->orderByDesc('version')->get();
    }

    private function requireIds(string $organizationId, string $evidenceId): void
    {
        if (!Str::isUuid($organizationId) || !Str::isUuid($evidenceId)) {
            throw (new ModelNotFoundException())->setModel(EvidenceRevision::class);
        }
    }
}

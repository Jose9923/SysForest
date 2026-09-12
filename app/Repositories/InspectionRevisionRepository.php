<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\InspectionRevision;
use App\Models\TreeInspection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class InspectionRevisionRepository
{
    public function record(
        string $organizationId,
        string $inspectionId,
        int $actorId,
        int $version,
        array $oldValues,
        array $newValues,
        string $event,
    ): InspectionRevision {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La revisión debe confirmarse junto con su inspección.');
        }
        TreeInspection::query()->where('organization_id', $organizationId)->whereKey($inspectionId)->firstOrFail();
        $revision = new InspectionRevision();
        $revision->forceFill(['organization_id' => $organizationId, 'inspection_id' => $inspectionId,
            'changed_by' => $actorId, 'version' => $version, 'changed_at' => now(), 'event' => $event,
            'old_values' => (object) $oldValues, 'new_values' => (object) $newValues])->save();

        return $revision;
    }

    public function forInspection(string $organizationId, string $inspectionId): Collection
    {
        return InspectionRevision::query()->where('organization_id', $organizationId)->where('inspection_id', $inspectionId)
            ->with('changedBy')->orderByDesc('version')->get();
    }
}

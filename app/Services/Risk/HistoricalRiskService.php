<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Repositories\Risk\RiskRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Proyecta visitas existentes sin actualizar su versión, condiciones ni auditoría histórica. */
class HistoricalRiskService
{
    public function __construct(private RiskRepository $risks, private RiskEvaluationService $evaluations)
    {
    }

    public function backfill(?string $organizationId = null): int
    {
        if ($organizationId !== null && !\App\Support\Identifiers\RecordIdentifier::isValid($organizationId)) {
            throw ValidationException::withMessages(['organization' => 'Indica un UUID institucional válido.']);
        }
        $count = 0;
        foreach ($this->risks->organizations() as $organization) {
            if ($organizationId !== null && $organization->id !== $organizationId) {
                continue;
            }
            $this->risks->pendingHistory($organization->id)->select('tree_inspections.*')->chunkById(100, function ($rows) use ($organization, &$count): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($organization, $row, &$count): void {
                        $this->risks->lockOrganization($organization->id);
                        $inspection = $this->risks->lockHistoricalInspection($organization->id, $row->id);
                        if ($this->risks->evaluation($inspection) === null) {
                            $this->evaluations->record($inspection, null, [], 'historical');
                            $count++;
                        }
                    });
                }
            }, 'tree_inspections.id', 'id');
        }

        return $count;
    }
}

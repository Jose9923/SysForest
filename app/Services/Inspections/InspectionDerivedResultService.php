<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\Inspections\TreeInspection;
use App\Repositories\Inspections\InspectionDerivedResultRepository;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Un cambio de visibilidad conserva el cálculo y la decisión, sin volver a atribuirlos. */
class InspectionDerivedResultService
{
    public function __construct(private InspectionDerivedResultRepository $results)
    {
    }

    public function preserveAfterPublication(TreeInspection $inspection, int $previousVersion): void
    {
        if (DB::transactionLevel() < 1 || $inspection->lock_version !== $previousVersion + 1) {
            throw new LogicException('La conservación requiere la transacción de publicación y su revisión inmediata anterior.');
        }
        $risk = $this->results->riskAt($inspection, $previousVersion);
        if ($risk !== null) {
            $attributes = $risk->getAttributes();
            unset($attributes['id']);
            $attributes['inspection_version'] = $inspection->lock_version;
            $attributes['created_at'] = now();
            $attributes['result'] = array_replace($risk->result, ['carried_forward' => [
                'reason' => 'publication_change', 'source_id' => $risk->id,
                'source_inspection_version' => $previousVersion,
                'original_created_at' => $risk->result['carried_forward']['original_created_at'] ?? $risk->created_at->toISOString(),
            ]]);
            // confirmed_by, confirmed_at, origin y observaciones pertenecen a la decisión original.
            $this->results->appendRisk($attributes);
        }
        $carbon = $this->results->carbonAt($inspection, $previousVersion);
        if ($carbon !== null) {
            $attributes = $carbon->getAttributes();
            unset($attributes['id']);
            $attributes['inspection_version'] = $inspection->lock_version;
            $attributes['created_at'] = now();
            $attributes['inputs'] = $carbon->inputs;
            $attributes['method'] = array_replace($carbon->method, ['carried_forward' => [
                'reason' => 'publication_change', 'source_id' => $carbon->id,
                'source_inspection_version' => $previousVersion,
                'original_created_at' => $carbon->method['carried_forward']['original_created_at'] ?? $carbon->created_at->toISOString(),
            ]]);
            // La huella de entradas y el perfil se conservan: publicar no recalcula ni valida referencias.
            $this->results->appendCarbon($attributes);
        }
        $inspection->unsetRelation('riskEvaluation')->unsetRelation('carbonEstimate');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Forestry\PhytosanitaryRisk;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\RiskRepository;
use App\Repositories\TreeInspectionRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** El resultado automático y la decisión profesional se conservan por separado. */
class RiskEvaluationService
{
    public function __construct(
        private RiskRepository $risks,
        private RiskMethodologyService $methods,
        private PhytosanitaryRisk $calculator,
        private ForestryAccessService $access,
        private TreeInspectionRepository $inspections,
        private \App\Repositories\TreeRepository $trees,
    ) {
    }

    public function preview(User $actor, string $treeId, array $input): array
    {
        $organization = $this->access->organization($actor);
        $inspectionId = $input['inspection_id'] ?? null;
        if ($inspectionId !== null) {
            $inspection = $this->inspections->findForTenant($organization->id, $inspectionId);
            abort_unless($inspection->tree_id === $treeId, 404);
            Gate::forUser($actor)->authorize('update', $inspection);
        } else {
            $tree = $this->trees->findForTenant($organization->id, $treeId);
            Gate::forUser($actor)->authorize('create', [TreeInspection::class, $tree]);
        }
        $method = $this->risks->active($organization->id);
        $config = $method?->configuration ?? $this->methods->template();
        $ids = $input['phytosanitary_condition_ids'] ?? [];
        $codes = $this->risks->selectedCodes($ids);
        if (count($codes) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['phytosanitary_condition_ids' => 'Selecciona condiciones fitosanitarias existentes.']);
        }

        return \App\Support\RiskPresentation::result($this->calculator->evaluate($config, $codes, $this->conditionReferences()), $config)
            + ['methodology_id' => $method?->id];
    }

    public function record(TreeInspection $inspection, ?User $actor, array $input, string $origin = 'web'): void
    {
        if (!in_array($origin, ['web', 'historical', 'import', 'mobile'], true)) {
            throw new \InvalidArgumentException('Origen de evaluación no válido.');
        }
        $existing = $this->risks->evaluation($inspection);
        if ($existing !== null) {
            return;
        }
        $method = $this->methods->initialize($inspection->organization_id);
        $result = $this->calculator->evaluate($method->configuration, $inspection->conditions->where('assessment_type', 'phytosanitary')->pluck('code')->all(), $this->conditionReferences());
        $priority = null;
        if ($origin !== 'historical' && $result['errors'] !== []) {
            throw ValidationException::withMessages(['phytosanitary_condition_ids' => $result['errors']]);
        }
        if ($origin === 'web') {
            if (($input['risk_methodology_id'] ?? null) !== $method->id) {
                throw ValidationException::withMessages(['risk_priority' => 'La metodología cambió. Actualiza la sugerencia y confirma nuevamente.']);
            }
            $confirmed = $input['risk_confirmed'] ?? null;
            $value = $input['risk_priority'] ?? null;
            if (!in_array($confirmed, [true, 1, '1'], true) || !in_array((string) $value, ['1', '2', '3', '4'], true)) {
                throw ValidationException::withMessages(['risk_priority' => 'Selecciona y confirma la prioridad profesional antes de guardar.']);
            }
            $priority = (int) $value;
            if ($priority !== $result['priority'] && trim(\App\Support\ExcelReferencePresentation::text($inspection->general_observations, false) ?? '') === '') {
                throw ValidationException::withMessages(['general_observations' => $result['priority'] === null
                    ? 'No hay sugerencia automática. Justifica tu prioridad profesional en las observaciones generales.'
                    : 'Argumenta en observaciones por qué cambiaste la prioridad sugerida.']);
            }
        }
        $evaluation = $this->risks->record($inspection, $method, $result, $priority, $actor?->id, $origin);
        $inspection->setRelation('riskEvaluation', $evaluation);
    }

    private function conditionReferences(): array
    {
        return $this->risks->conditions()->mapWithKeys(fn ($condition): array => [$condition->code => [
            'label' => $condition->name, 'component' => $condition->category->code,
        ]])->all();
    }
}

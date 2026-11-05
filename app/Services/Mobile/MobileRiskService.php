<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Exceptions\Inspections\StaleInspectionException;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Inspections\InspectionConditionRepository;
use App\Repositories\Inspections\TreeInspectionRepository;
use App\Repositories\Trees\TreeRepository;
use App\Validation\Mobile\MobileRiskInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Risk\RiskEvaluationService;
use App\Services\Risk\RiskMethodologyService;

/** Autoriza la captura móvil y reutiliza el motor institucional, sin duplicar fórmulas. */
class MobileRiskService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private TreeInspectionRepository $inspections,
        private InspectionConditionRepository $conditions,
        private RiskMethodologyService $methods,
        private RiskEvaluationService $risks,
    ) {
    }

    public function preview(User $actor, array $input): array
    {
        $data = Validator::make($input, MobileRiskInput::preview($input))->validate();
        $organization = $this->access->organization($actor);
        $this->access->authorize($actor, 'risk.view');
        $inspection = null;
        $treeId = isset($data['tree_id']) ? strtolower($data['tree_id']) : null;
        if (($data['inspection_id'] ?? null) !== null) {
            $inspection = $this->inspections->findForTenant($organization->id, $data['inspection_id']);
            if ($treeId !== null) {
                abort_unless($inspection->tree_id === $treeId, 404);
            }
            Gate::forUser($actor)->authorize('update', $inspection);
            if ((int) $data['lock_version'] !== $inspection->lock_version) {
                throw new StaleInspectionException();
            }
            if (($data['require_latest_inspection'] ?? false)
                && $this->inspections->latestForTree($organization->id, $inspection->tree_id)?->id !== $inspection->id) {
                throw new StaleInspectionException();
            }
            $treeId = $inspection->tree_id;
        } elseif ($treeId !== null) {
            $tree = $this->trees->findForTenant($organization->id, $treeId);
            Gate::forUser($actor)->authorize('create', [TreeInspection::class, $tree]);
        } else {
            $this->access->authorize($actor, 'trees.create');
            $this->access->authorize($actor, 'inspections.create');
        }
        if (!$this->conditions->validate($data['phytosanitary_condition_ids'], 'phytosanitary', $inspection)) {
            throw ValidationException::withMessages(['phytosanitary_condition_ids' => 'Selecciona condiciones fitosanitarias disponibles o conservadas en esta inspección.']);
        }

        // Igual que el formulario web: prepara la versión inicial solo cuando no existe ninguna.
        $this->methods->initialize($organization->id);

        return $treeId === null
            ? $this->risks->previewRegistration($actor, $data)
            : $this->risks->preview($actor, $treeId, $data);
    }
}

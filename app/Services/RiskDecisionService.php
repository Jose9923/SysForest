<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\TreeInspectionRepository;
use App\Repositories\TreeRepository;
use App\Repositories\UserRepository;
use App\Support\ExcelReferencePresentation;
use App\Validation\TreeInspectionInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Cambia la decisión de la última visita, conservando el flujo transaccional de inspecciones. */
class RiskDecisionService
{
    public function __construct(
        private ForestryAccessService $access,
        private UserRepository $users,
        private TreeRepository $trees,
        private TreeInspectionRepository $inspections,
        private TreeInspectionService $writer,
        private RiskEvaluationService $risk,
        private RiskMethodologyService $methods,
    ) {
    }

    public function page(User $actor, string $treeId): array
    {
        $organization = $this->access->organization($actor);
        $this->authorize($actor);
        $tree = $this->trees->findForTenant($organization->id, $treeId);
        Gate::forUser($actor)->authorize('view', $tree);
        $inspection = $this->inspections->latestForTree($organization->id, $tree->id);
        $method = null;
        $preview = null;
        if ($inspection !== null) {
            Gate::forUser($actor)->authorize('view', $inspection);
            Gate::forUser($actor)->authorize('update', $inspection);
            // Igual que el formulario web existente: solo inicializa si aún no hay versión institucional.
            $method = $this->methods->initialize($organization->id);
            $preview = $this->risk->preview($actor, $tree->id, ['inspection_id' => $inspection->id,
                'phytosanitary_condition_ids' => $inspection->conditions->where('assessment_type', 'phytosanitary')->modelKeys()]);
        }

        return ['organization' => $organization, 'tree' => $tree, 'inspection' => $inspection,
            'riskMethodology' => $method, 'riskPreview' => $preview,
            'observations' => ExcelReferencePresentation::text($inspection?->general_observations, false),
            'canCreateInspection' => Gate::forUser($actor)->allows('create', [TreeInspection::class, $tree])];
    }

    public function update(User $actor, string $treeId, array $input): TreeInspection
    {
        return DB::transaction(function () use ($actor, $treeId, $input): TreeInspection {
            // La selección de última visita comparte el bloqueo de las altas y correcciones.
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $this->authorize($actor);
            $tree = $this->trees->findForTenant($organization->id, $treeId, true);
            Gate::forUser($actor)->authorize('view', $tree);
            $data = $this->validate($input);
            $latest = $this->inspections->latestForTree($organization->id, $tree->id);
            if ($latest === null || $latest->id !== strtolower($data['inspection_id'])) {
                throw new ConflictHttpException('La última inspección cambió desde que abriste la decisión. Actualiza esta revisión antes de guardar.');
            }
            Gate::forUser($actor)->authorize('view', $latest);
            Gate::forUser($actor)->authorize('update', $latest);
            unset($data['inspection_id']);

            return $this->writer->update($actor, $latest->id, $data, 'web');
        }, 3);
    }

    private function authorize(User $actor): void
    {
        foreach (['risk.view', 'trees.view', 'inspections.view', 'inspections.update'] as $permission) {
            $this->access->authorize($actor, $permission);
        }
    }

    private function validate(array $input): array
    {
        $rules = array_intersect_key(TreeInspectionInput::rules(true), array_fill_keys([
            'lock_version', 'risk_priority', 'risk_confirmed', 'risk_methodology_id', 'general_observations',
        ], true));
        $rules['inspection_id'] = ['required', 'uuid'];
        $rules['risk_priority'] = ['required', 'integer', 'between:1,4'];
        $rules['risk_confirmed'] = ['required', 'boolean', 'accepted'];
        $rules['risk_methodology_id'] = ['required', 'uuid'];
        if (array_diff(array_keys($input), array_keys($rules), ['_token', '_method']) !== []) {
            throw ValidationException::withMessages(['decision' => 'Esta pantalla solo permite cambiar la prioridad profesional y las observaciones.']);
        }

        return Validator::make($input, $rules, TreeInspectionInput::messages() + [
            'risk_confirmed.accepted' => 'Confirma expresamente la prioridad profesional antes de guardar.',
        ])->validate();
    }
}

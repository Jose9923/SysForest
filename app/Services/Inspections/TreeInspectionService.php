<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Exceptions\Inspections\StaleInspectionException;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Inspections\TreeInspectionRepository;
use App\Repositories\Trees\TreeRepository;
use App\Repositories\Users\UserRepository;
use App\Validation\Forestry\DendrometryInput;
use App\Validation\Inspections\TreeInspectionInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Carbon\CarbonEstimationService;
use App\Services\Forestry\DendrometryCalculationService;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Forestry\PhenologyService;
use App\Services\Risk\RiskEvaluationService;

/** Coordina una visita completa; identidad, mediciones, hijos y auditoría se confirman juntos. */
class TreeInspectionService
{
    public function __construct(
        private ForestryAccessService $access,
        private UserRepository $users,
        private TreeRepository $trees,
        private TreeInspectionRepository $inspections,
        private DendrometryCalculationService $calculations,
        private InspectionConditionService $conditions,
        private InspectionFaunaService $fauna,
        private PhenologyService $phenology,
        private InspectionAuditService $audit,
        private RiskEvaluationService $risk,
        private CarbonEstimationService $carbon,
    ) {
    }

    public function create(User $actor, string $treeId, array $input, string $origin = 'web'): TreeInspection
    {
        return DB::transaction(function () use ($actor, $treeId, $input, $origin): TreeInspection {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $tree = $this->trees->findForTenant($organization->id, $treeId, true);
            Gate::forUser($actor)->authorize('create', [TreeInspection::class, $tree]);
            $data = Validator::make($input, TreeInspectionInput::rules(), TreeInspectionInput::messages())->validate();
            $attributes = $this->attributes($tree, $data);
            $inspection = $this->inspections->create($attributes + [
                'organization_id' => $organization->id, 'tree_id' => $tree->id,
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'lock_version' => 1,
            ]);
            $this->conditions->apply($inspection, $data);
            $this->fauna->apply($inspection, $data['fauna_category_ids'] ?? []);
            $inspection = $this->inspections->findForTenant($organization->id, $inspection->id);
            $this->risk->record($inspection, $actor, $data, $origin);
            $this->carbon->record($inspection);
            $this->audit->record($actor, $inspection, [], 'inspection.created');

            return $inspection;
        }, 3);
    }

    public function update(User $actor, string $id, array $input, string $origin = 'web'): TreeInspection
    {
        return DB::transaction(function () use ($actor, $id, $input, $origin): TreeInspection {
            // Mantiene el orden actor → organización → árbol → inspección entre todas las escrituras.
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $reference = $this->inspections->findForTenant($organization->id, $id);
            $tree = $this->trees->findForTenant($organization->id, $reference->tree_id, true);
            $inspection = $this->inspections->findForTenant($organization->id, $id, true);
            Gate::forUser($actor)->authorize('update', $inspection);
            $version = $this->version($input['lock_version'] ?? null);
            if ($version !== $inspection->lock_version) {
                throw new StaleInspectionException();
            }
            $input = array_replace($this->currentInput($inspection), $input);
            $data = Validator::make($input, TreeInspectionInput::rules(true), TreeInspectionInput::messages())->validate();
            $data['general_observations'] = \App\Support\Imports\ExcelReferencePresentation::preserveObservations(
                $inspection->general_observations,
                $data['general_observations'] ?? null
            );
            if (mb_strlen($data['general_observations'] ?? '') > 20000) {
                throw ValidationException::withMessages(['general_observations' => 'Reduce la extensión de las observaciones antes de guardar.']);
            }
            if ($inspection->calculation_rule_version !== \App\Domain\Forestry\DendrometryArithmetic::VERSION) {
                throw ValidationException::withMessages(['calculation_rule_version' => 'La corrección requiere el algoritmo original de esta inspección, que no está disponible.']);
            }
            $old = $this->audit->snapshot($inspection);
            $attributes = $this->attributes($tree, $data, $inspection->classification_rule_version_id);
            $updated = $this->inspections->update($inspection, $attributes + ['updated_by' => $actor->id, 'lock_version' => $version + 1]);
            $this->conditions->apply($updated, $data, $inspection);
            $this->fauna->apply($updated, $data['fauna_category_ids'], $inspection);
            $updated = $this->inspections->findForTenant($organization->id, $id);
            $this->risk->record($updated, $actor, $data, $origin);
            $this->carbon->record($updated);
            $this->audit->record($actor, $updated, $old, 'inspection.updated');

            return $updated;
        }, 3);
    }

    private function attributes(Tree $tree, array $data, ?string $ruleVersionId = null): array
    {
        $participation = $data['inventory_tree_id'] ?? null;
        if ($participation !== null && !$this->inspections->validateInventoryTree($tree->organization_id, $tree->id, $participation, true)) {
            throw ValidationException::withMessages(['inventory_tree_id' => 'La participación no corresponde a este árbol y organización.']);
        }

        // Ningún resultado enviado por el cliente participa en las fórmulas ni en las clasificaciones.
        $measurements = DendrometryInput::validate($data);

        return $measurements + $this->calculations->calculate($measurements, $ruleVersionId)
            + $this->phenology->normalize($data) + [
                'inventory_tree_id' => $participation, 'inspection_date' => $data['inspection_date'],
                'general_observations' => $data['general_observations'] ?? null,
                'fauna_observations' => $data['fauna_observations'] ?? null,
            ];
    }

    private function currentInput(TreeInspection $inspection): array
    {
        $fields = array_merge(
            array_keys(DendrometryInput::rules()),
            TreeInspectionInput::TEXT_FIELDS,
            TreeInspectionInput::DATE_FIELDS,
            ['inspection_date', 'inventory_tree_id']
        );
        $input = [];
        foreach ($fields as $field) {
            $value = $inspection->getAttribute($field);
            $input[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        $input['physical_condition_ids'] = $inspection->conditions->where('assessment_type', 'physical_mechanical')->modelKeys();
        $input['phytosanitary_condition_ids'] = $inspection->conditions->where('assessment_type', 'phytosanitary')->modelKeys();
        $input['fauna_category_ids'] = $inspection->faunaCategories->modelKeys();

        return $input;
    }

    private function version(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
            throw ValidationException::withMessages(['lock_version' => 'La versión debe ser un entero positivo.']);
        }

        return (int) Validator::make(['lock_version' => $value], [
            'lock_version' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ], TreeInspectionInput::messages())->validate()['lock_version'];
    }
}

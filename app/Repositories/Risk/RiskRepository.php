<?php

declare(strict_types=1);

namespace App\Repositories\Risk;

use App\Models\Conditions\Condition;
use App\Models\Organizations\Organization;
use App\Models\Risk\RiskEvaluation;
use App\Models\Risk\RiskMethodology;
use App\Models\Inspections\TreeInspection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Toda consulta y escritura de riesgo recibe el alcance institucional explícito. */
class RiskRepository
{
    public function organizations(): Collection
    {
        return Organization::withTrashed()->orderBy('id')->get();
    }

    public function lockOrganization(string $organizationId): void
    {
        Organization::withTrashed()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
    }

    public function settings(string $organizationId): ?object
    {
        return DB::table('risk_settings')->where('organization_id', $organizationId)->first();
    }

    public function active(string $organizationId): ?RiskMethodology
    {
        $setting = $this->settings($organizationId);

        return $setting ? $this->methodology($organizationId, $setting->methodology_id) : null;
    }

    public function methodology(string $organizationId, string $id): RiskMethodology
    {
        return RiskMethodology::query()->where('organization_id', $organizationId)->whereKey($id)->firstOrFail();
    }

    public function versions(string $organizationId): Collection
    {
        return RiskMethodology::query()->where('organization_id', $organizationId)->orderByDesc('version')->get();
    }

    public function conditions(): Collection
    {
        return Condition::query()->where('assessment_type', 'phytosanitary')->with('category')
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    public function selectedCodes(array $ids): array
    {
        return Condition::query()->where('assessment_type', 'phytosanitary')->whereIn('id', $ids)->orderBy('code')->pluck('code')->all();
    }

    public function publish(string $organizationId, string $name, array $configuration, ?int $actorId): RiskMethodology
    {
        $version = (int) RiskMethodology::query()->where('organization_id', $organizationId)->max('version') + 1;
        $configuration['code'] = 'PGF-'.$version;
        $method = new RiskMethodology();
        $method->forceFill(['organization_id' => $organizationId, 'version' => $version, 'name' => $name,
            'configuration' => $configuration, 'created_by' => $actorId, 'created_at' => now()])->save();
        $references = $this->conditions()->keyBy('code');
        foreach ($configuration['conditions'] as $code => $row) {
            DB::table('risk_condition_rules')->insert(['methodology_id' => $method->id,
                'condition_id' => $references[$code]->id, 'weight' => $row['weight'],
                'minimum_priority' => $row['minimum'], 'component' => $row['component']]);
        }
        $setting = $this->settings($organizationId);
        DB::table('risk_settings')->updateOrInsert(
            ['organization_id' => $organizationId],
            ['methodology_id' => $method->id, 'lock_version' => ($setting->lock_version ?? 0) + 1]
        );

        return $method;
    }

    public function evaluation(TreeInspection $inspection): ?RiskEvaluation
    {
        return RiskEvaluation::query()->where('organization_id', $inspection->organization_id)
            ->where('inspection_id', $inspection->id)->where('inspection_version', $inspection->lock_version)->first();
    }

    public function history(string $organizationId, string $inspectionId): Collection
    {
        return RiskEvaluation::query()->where('organization_id', $organizationId)->where('inspection_id', $inspectionId)
            ->with('confirmer')->orderByDesc('inspection_version')->get();
    }

    public function record(TreeInspection $inspection, RiskMethodology $method, array $result, ?int $priority, ?int $actorId, string $origin): RiskEvaluation
    {
        $evaluation = new RiskEvaluation();
        $evaluation->forceFill(['organization_id' => $inspection->organization_id, 'inspection_id' => $inspection->id,
            'inspection_version' => $inspection->lock_version, 'methodology_id' => $method->id, 'result' => $result,
            'priority' => $priority, 'confirmed_by' => $priority === null ? null : $actorId,
            'confirmed_at' => $priority === null ? null : now(), 'observations' => $inspection->general_observations,
            'origin' => $origin, 'created_at' => now()])->save();

        return $evaluation->load(['confirmer', 'methodology']);
    }

    public function lockHistoricalInspection(string $organizationId, string $inspectionId): TreeInspection
    {
        return TreeInspection::query()->where('organization_id', $organizationId)->whereKey($inspectionId)
            ->lockForUpdate()->with('conditions')->firstOrFail();
    }

    public function pendingHistory(string $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        return TreeInspection::query()->where('organization_id', $organizationId)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('risk_evaluations')
                ->whereColumn('risk_evaluations.inspection_id', 'tree_inspections.id')
                ->whereColumn('risk_evaluations.inspection_version', 'tree_inspections.lock_version'));
    }
}

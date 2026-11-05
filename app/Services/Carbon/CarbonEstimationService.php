<?php

declare(strict_types=1);

namespace App\Services\Carbon;

use App\Domain\Carbon\TreeCarbon;
use App\Models\Inspections\TreeInspection;
use App\Repositories\Carbon\CarbonRepository;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Calcula en servidor y conserva mediciones, referencia y método en la misma transacción. */
class CarbonEstimationService
{
    public function __construct(private CarbonRepository $repository, private TreeCarbon $calculator)
    {
    }

    public function record(TreeInspection $inspection): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La estimación requiere una transacción y el bloqueo institucional.');
        }
        $speciesId = $inspection->tree->species_id;
        $profile = $this->repository->active($inspection->organization_id, $speciesId);
        $inputs = ['species_id' => $speciesId, 'cap_cm' => $inspection->cap_cm, 'dap_m' => $inspection->dap_m,
            'growth_habit_id' => $inspection->tree->growth_habit_id,
            'growth_habit_code' => $inspection->tree->growthHabit?->code,
            'number_of_stems' => $inspection->number_of_stems, 'total_height_m' => $inspection->total_height_m,
            'inspection_date' => $inspection->inspection_date->format('Y-m-d')];
        $result = $this->calculator->calculate($inputs, $profile?->attributesToArray());
        $key = hash('sha256', json_encode([$inputs, $profile?->id, TreeCarbon::VERSION], JSON_THROW_ON_ERROR));
        $estimate = $this->repository->record($inspection, $result + ['species_id' => $speciesId,
            'profile_id' => $profile?->id, 'input_key' => $key, 'method_version' => TreeCarbon::VERSION,
            'inputs' => $inputs, 'method' => TreeCarbon::method() + ['profile' => $profile?->attributesToArray()]]);
        $inspection->setRelation('carbonEstimate', $estimate);

        return $estimate->wasRecentlyCreated;
    }

    public function forTree(string $organizationId, string $treeId): void
    {
        foreach ($this->repository->inspections($organizationId)->where('tree_id', $treeId)->orderBy('id')->pluck('id') as $id) {
            $this->record($this->repository->lockInspection($organizationId, $id));
        }
    }

    public function backfill(?string $organizationId = null): int
    {
        $count = 0;
        foreach ($this->repository->organizations($organizationId) as $organization) {
            $this->repository->inspections($organization->id)->select('id')->orderBy('id')->chunkById(100, function ($rows) use ($organization, &$count): void {
                foreach ($rows as $row) {
                    $added = DB::transaction(function () use ($organization, $row): bool {
                        $this->repository->lockOrganization($organization->id);
                        $inspection = $this->repository->lockInspection($organization->id, $row->id);

                        return $this->record($inspection);
                    }, 3);
                    $count += (int) $added;
                }
            });
        }

        return $count;
    }
}

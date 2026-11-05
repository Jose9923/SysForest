<?php

declare(strict_types=1);

namespace App\Repositories\Carbon;

use App\Models\Carbon\CarbonProfile;
use App\Models\Carbon\CarbonRefreshRun;
use App\Models\Inspections\TreeInspection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CarbonRefreshRepository
{
    public function available(): bool
    {
        return Schema::hasTable('carbon_refresh_runs');
    }

    public function latest(string $organizationId): ?CarbonRefreshRun
    {
        return CarbonRefreshRun::query()->where('organization_id', $organizationId)->orderByDesc('id')->first();
    }

    public function active(string $organizationId): ?CarbonRefreshRun
    {
        return CarbonRefreshRun::query()->where('organization_id', $organizationId)
            ->whereIn('status', ['queued', 'running'])->first();
    }

    public function find(string $id): ?CarbonRefreshRun
    {
        return CarbonRefreshRun::query()->find($id);
    }

    public function lock(string $id): ?CarbonRefreshRun
    {
        return CarbonRefreshRun::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function referenceVersion(string $organizationId): int
    {
        return (int) CarbonProfile::query()->where('organization_id', $organizationId)->max('id');
    }

    public function create(string $organizationId, int $actorId, CarbonInterface $requestedAt): CarbonRefreshRun
    {
        $run = new CarbonRefreshRun();
        $run->forceFill(['organization_id' => $organizationId, 'requested_by' => $actorId, 'status' => 'queued',
            'reference_version' => $this->referenceVersion($organizationId), 'requested_at' => $requestedAt,
            'total' => $this->selection($organizationId, $requestedAt)->count(),
            'processed' => 0, 'estimates_added' => 0])->save();

        return $run;
    }

    public function nextInspection(CarbonRefreshRun $run): ?string
    {
        return $this->selection($run->organization_id, $run->requested_at)
            ->when($run->last_inspection_id !== null, fn ($query) => $query->where('id', '>', $run->last_inspection_id))
            ->orderBy('id')->value('id');
    }

    public function update(CarbonRefreshRun $run, array $attributes): CarbonRefreshRun
    {
        $run->forceFill($attributes)->save();

        return $run;
    }

    public function due(): Collection
    {
        return CarbonRefreshRun::query()->whereIn('status', ['queued', 'running'])
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(2)))
            ->orderBy('id')->limit(100)->pluck('id');
    }

    /** El estado durable permite recuperar un commit seguido de una caída de Redis o del proceso web. */
    public function claimDispatch(string $id, bool $continuation): bool
    {
        return CarbonRefreshRun::query()->whereKey($id)->whereIn('status', ['queued', 'running'])
            ->when(!$continuation, fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(2))))
            ->update(['dispatched_at' => now()]) === 1;
    }

    private function selection(string $organizationId, CarbonInterface $requestedAt): Builder
    {
        return TreeInspection::query()->where('organization_id', $organizationId)
            ->where('created_at', '<=', $requestedAt->format('Y-m-d H:i:s.u'));
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Carbon;

use App\Models\Carbon\CarbonProfile;
use App\Repositories\Identifiers\IdentifierRepository;
use App\Support\Identifiers\RecordIdentifier;
use Illuminate\Support\Str;
use App\Models\Carbon\CarbonRefreshRun;
use App\Models\Inspections\TreeInspection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CarbonRefreshRepository
{
    public function __construct(private IdentifierRepository $identifiers)
    {
    }

    public function available(): bool
    {
        return Schema::hasTable('carbon_refresh_runs');
    }

    public function latest(string $organizationId): ?CarbonRefreshRun
    {
        return CarbonRefreshRun::query()->where('organization_id', $organizationId)->orderByDesc('external_id')->first();
    }

    public function active(string $organizationId): ?CarbonRefreshRun
    {
        return CarbonRefreshRun::query()->where('organization_id', $organizationId)
            ->whereIn('status', ['queued', 'running'])->first();
    }

    public function find(string $id): ?CarbonRefreshRun
    {
        $key = $this->key($id);

        return $key === null ? null : CarbonRefreshRun::query()->find($key);
    }

    public function lock(string $id): ?CarbonRefreshRun
    {
        $key = $this->key($id);

        return $key === null ? null : CarbonRefreshRun::query()->whereKey($key)->lockForUpdate()->first();
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
            ->when($run->last_inspection_id !== null, fn ($query) => $query->where(
                'external_id',
                '>',
                $this->identifiers->external('tree_inspections', $run->last_inspection_id)
            ))
            ->orderBy('external_id')->value('id');
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
            ->orderBy('external_id')->limit(100)->pluck('id');
    }

    /** El estado durable permite recuperar un commit seguido de una caída de Redis o del proceso web. */
    public function claimDispatch(string $id, bool $continuation): bool
    {
        $key = $this->key($id);
        if ($key === null) {
            return false;
        }

        return CarbonRefreshRun::query()->whereKey($key)->whereIn('status', ['queued', 'running'])
            ->when(!$continuation, fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(2))))
            ->update(['dispatched_at' => now()]) === 1;
    }

    /** Los trabajos ya en Redis conservan su UUID; los nuevos pueden transportar la PK interna. */
    private function key(string $id): ?string
    {
        if (Str::isUuid($id)) {
            return $this->identifiers->resolveMany('carbon_refresh_runs', [strtolower($id)])[strtolower($id)] ?? null;
        }

        return RecordIdentifier::isValid($id) ? $id : null;
    }

    private function selection(string $organizationId, CarbonInterface $requestedAt): Builder
    {
        return TreeInspection::query()->where('organization_id', $organizationId)
            ->where('created_at', '<=', $requestedAt->format('Y-m-d H:i:s.u'));
    }
}

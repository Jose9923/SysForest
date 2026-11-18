<?php

declare(strict_types=1);

namespace App\Repositories\Carbon;

use App\Models\Carbon\CarbonEstimate;
use App\Repositories\Identifiers\IdentifierRepository;
use App\Models\Carbon\CarbonProfile;
use App\Models\Organizations\Organization;
use App\Models\Taxonomy\Species;
use App\Models\Inspections\TreeInspection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** El alcance institucional se exige incluso para las operaciones de mantenimiento. */
class CarbonRepository
{
    public function __construct(private IdentifierRepository $identifiers)
    {
    }

    /** El JSON conserva las referencias externas usadas por las huellas anteriores a BIGINT. */
    public function inputReferences(TreeInspection $inspection): array
    {
        return [
            'species_id' => $inspection->tree->species_id === null ? null
                : $this->identifiers->external('species', $inspection->tree->species_id),
            'growth_habit_id' => $inspection->tree->growth_habit_id === null ? null
                : $this->identifiers->external('growth_habits', $inspection->tree->growth_habit_id),
        ];
    }

    public function profileSnapshot(?CarbonProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }
        $values = $profile->attributesToArray();
        foreach (['organization_id' => 'organizations', 'species_id' => 'species'] as $field => $table) {
            if (isset($values[$field])) {
                $values[$field] = $this->identifiers->external($table, $values[$field]);
            }
        }

        return $values;
    }

    public function organizations(?string $id = null): Collection
    {
        return Organization::withTrashed()->when($id !== null, fn ($q) => $q->whereKey($id))->orderBy('id')->get();
    }

    public function lockOrganization(string $id): void
    {
        Organization::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function seedSpecies(): Collection
    {
        return Species::query()->with('family')->where('active', true)->orderBy('code')->get();
    }

    public function seedOrganizations(): Collection
    {
        return Organization::query()->where('active', true)->orderBy('id')->get();
    }

    public function seedReceipt(string $organizationId, string $speciesId, string $package): bool
    {
        return CarbonProfile::query()->where('organization_id', $organizationId)->where('species_id', $speciesId)
            ->where('source_package', $package)->exists();
    }

    public function species(): Collection
    {
        return Species::query()->orderBy('scientific_name')->get(['id', 'scientific_name', 'active']);
    }

    public function profiles(string $organizationId): Collection
    {
        return CarbonProfile::query()->where('organization_id', $organizationId)->orderByDesc('id')->get();
    }

    public function active(string $organizationId, ?string $speciesId): ?CarbonProfile
    {
        return CarbonProfile::query()->where('organization_id', $organizationId)->where('species_id', $speciesId)->orderByDesc('id')->first();
    }

    public function publish(string $organizationId, ?int $actorId, array $data): CarbonProfile
    {
        $profile = new CarbonProfile();
        $profile->forceFill($data + ['organization_id' => $organizationId, 'created_by' => $actorId, 'created_at' => now()])->save();

        return $profile;
    }

    public function record(TreeInspection $inspection, array $data): CarbonEstimate
    {
        $existing = CarbonEstimate::query()->where('organization_id', $inspection->organization_id)
            ->where('inspection_id', $inspection->id)->where('inspection_version', $inspection->lock_version)
            ->where('input_key', $data['input_key'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $estimate = new CarbonEstimate();
        $estimate->forceFill($data + ['organization_id' => $inspection->organization_id, 'inspection_id' => $inspection->id,
            'inspection_version' => $inspection->lock_version, 'created_at' => now()])->save();

        return $estimate;
    }

    public function inspections(string $organizationId): Builder
    {
        return TreeInspection::query()->where('organization_id', $organizationId);
    }

    public function lockInspection(string $organizationId, string $id): TreeInspection
    {
        return $this->inspections($organizationId)->whereKey($id)->lockForUpdate()->with(['tree.growthHabit'])->firstOrFail();
    }

    public function history(string $organizationId, string $inspectionId): Collection
    {
        return CarbonEstimate::query()->where('organization_id', $organizationId)->where('inspection_id', $inspectionId)->orderByDesc('id')->get();
    }
}

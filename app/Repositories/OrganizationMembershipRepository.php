<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Collection;

class OrganizationMembershipRepository
{
    public function activeFor(int $userId, string $organizationId, bool $lock = false): ?OrganizationMembership
    {
        $query = OrganizationMembership::query()->with('organization.municipality.department.country')
            ->where('user_id', $userId)->where('organization_id', $organizationId)
            ->where('active', true)->whereNull('left_at')
            ->whereHas('organization', fn ($query) => $query->where('active', true));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** El ID de recurso siempre se combina con el tenant verificado; no hay bypass implícito. */
    public function findForTenant(string $organizationId, string $membershipId, bool $lock = false): OrganizationMembership
    {
        $query = OrganizationMembership::query()->where('organization_id', $organizationId)->whereKey($membershipId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function upsert(int $userId, string $organizationId, array $attributes): OrganizationMembership
    {
        return OrganizationMembership::query()->updateOrCreate(
            ['user_id' => $userId, 'organization_id' => $organizationId],
            array_intersect_key($attributes, array_flip(['active', 'joined_at', 'left_at'])),
        );
    }

    public function update(OrganizationMembership $membership, array $attributes): OrganizationMembership
    {
        // Nunca permite reasignar la cuenta ni el tenant de una membresía existente.
        $membership->fill(array_intersect_key($attributes, array_flip(['active', 'joined_at', 'left_at'])))->save();

        return $membership->refresh();
    }

    public function forUser(int $userId): Collection
    {
        return OrganizationMembership::query()->with('organization.municipality.department.country')
            ->where('user_id', $userId)->orderBy('created_at')->get();
    }
}

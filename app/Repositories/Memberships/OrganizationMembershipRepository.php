<?php

declare(strict_types=1);

namespace App\Repositories\Memberships;

use App\Models\Memberships\OrganizationMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class OrganizationMembershipRepository
{
    public function activeFor(int $userId, string $organizationId, bool $lock = false): ?OrganizationMembership
    {
        $query = OrganizationMembership::query()->with(['organization.municipality.department.country', 'roleAssignment.role'])
            ->where('user_id', $userId)->where('organization_id', $organizationId)
            ->where('active', true)->whereNull('left_at')
            ->whereHas('organization', fn ($query) => $query->where('active', true));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** El ID de recurso siempre se combina con el tenant verificado; no hay bypass implícito. */
    public function findForTenant(string $organizationId, string $membershipId, bool $lock = false): OrganizationMembership
    {
        if (!\App\Support\Identifiers\RecordIdentifier::isValid($membershipId)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(OrganizationMembership::class);
        }
        $query = OrganizationMembership::query()->with(['user', 'organization', 'roleAssignment.role'])
            ->where('organization_id', $organizationId)->whereKey($membershipId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function findForUserInTenant(int $userId, string $organizationId, bool $lock = false): ?OrganizationMembership
    {
        $query = OrganizationMembership::query()->where('user_id', $userId)->where('organization_id', $organizationId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function paginateForTenant(string $organizationId): LengthAwarePaginator
    {
        return OrganizationMembership::query()->with(['user', 'roleAssignment.role'])->where('organization_id', $organizationId)
            ->orderBy('created_at')->orderBy('id')->paginate(20);
    }

    public function create(int $userId, string $organizationId, array $attributes): OrganizationMembership
    {
        return OrganizationMembership::query()->create(array_merge(
            array_intersect_key($attributes, array_flip(['active', 'joined_at', 'left_at'])),
            ['user_id' => $userId, 'organization_id' => $organizationId],
        ));
    }

    /** API confiable para seeders y flujos que ya validaron el estado anterior. */
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
        return OrganizationMembership::query()->with(['organization.municipality.department.country', 'roleAssignment.role'])
            ->where('user_id', $userId)->orderBy('created_at')->get();
    }
}

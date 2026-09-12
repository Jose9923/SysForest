<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;

class InvitationRepository
{
    public function create(array $attributes): Invitation
    {
        return Invitation::query()->create($attributes);
    }

    public function findByTokenHash(string $hash): ?Invitation
    {
        return Invitation::query()->with(['organization', 'role'])->where('token_hash', $hash)->first();
    }

    public function lockById(string $id): Invitation
    {
        return Invitation::query()->with(['organization', 'role'])->lockForUpdate()->findOrFail($id);
    }

    public function lockOrganization(string $id): Organization
    {
        return Organization::withTrashed()->lockForUpdate()->findOrFail($id);
    }

    /** Incluye membresías inactivas para impedir reactivaciones implícitas mediante invitación. */
    public function membershipForUser(int $userId, string $organizationId): ?OrganizationMembership
    {
        return OrganizationMembership::query()->where('user_id', $userId)
            ->where('organization_id', $organizationId)->lockForUpdate()->first();
    }

    public function revokePending(string $organizationId, string $email): void
    {
        Invitation::query()->where('organization_id', $organizationId)->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function consume(Invitation $invitation): void
    {
        $invitation->forceFill(['accepted_at' => now()])->save();
    }
}

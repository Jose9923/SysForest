<?php

declare(strict_types=1);

namespace App\Repositories\Invitations;

use App\Models\Invitations\Invitation;
use App\Models\Organizations\Organization;
use App\Models\Memberships\OrganizationMembership;

class InvitationRepository
{
    public function create(array $attributes): Invitation
    {
        return Invitation::query()->create($attributes)->refresh();
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

    /** La sustitución bloquea las invitaciones previas para auditarlas individualmente en el servicio. */
    public function pendingForAddress(string $organizationId, string $email): \Illuminate\Database\Eloquent\Collection
    {
        return Invitation::query()->where('organization_id', $organizationId)->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    public function consume(Invitation $invitation): void
    {
        $invitation->forceFill(['accepted_at' => now()])->save();
    }
}

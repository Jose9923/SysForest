<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Repositories\OrganizationMembershipRepository;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class TenantContextService
{
    public function __construct(private TenantContext $context, private OrganizationMembershipRepository $memberships)
    {
    }

    public function activate(User $actor, string $organizationId): Organization
    {
        $this->context->clear();

        if (!Str::isUuid($organizationId)) {
            throw new AuthorizationException('La organización solicitada no está autorizada.');
        }

        $membership = $this->memberships->activeFor((int) $actor->id, $organizationId);

        if ($membership === null) {
            throw new AuthorizationException('La organización solicitada no está autorizada.');
        }

        $this->context->activate((int) $actor->id, $organizationId);

        return $membership->organization;
    }

    /** Una revocación posterior a activate también invalida las operaciones siguientes. */
    public function assertCurrentMembership(User $actor, bool $lock = false): OrganizationMembership
    {
        if ($this->context->actorId() !== (int) $actor->id) {
            throw new AuthorizationException('El actor no coincide con el contexto.');
        }

        $membership = $this->memberships->activeFor((int) $actor->id, $this->context->organizationId(), $lock);

        if ($membership === null) {
            $this->context->clear();
            throw new AuthorizationException('La membresía ya no permite operar en esta organización.');
        }

        return $membership;
    }

    public function currentOrganization(User $actor): Organization
    {
        return $this->assertCurrentMembership($actor)->organization;
    }

    /** El finally evita que procesos largos o jobs reutilicen el tenant de una ejecución anterior. */
    public function run(User $actor, string $organizationId, Closure $callback): mixed
    {
        try {
            $this->activate($actor, $organizationId);

            return $callback();
        } finally {
            $this->context->clear();
        }
    }
}

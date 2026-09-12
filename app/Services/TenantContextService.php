<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\UserRepository;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Throwable;

class TenantContextService
{
    public function __construct(
        private TenantContext $context,
        private OrganizationMembershipRepository $memberships,
        private RoleAssignmentRepository $assignments,
        private PermissionService $permissions,
        private UserRepository $users,
    ) {
    }

    public function activate(User $actor, string $organizationId): Organization
    {
        $this->context->clear();
        $this->requireEligibleActor($actor);

        if (!Str::isUuid($organizationId)) {
            throw new AuthorizationException('La organización solicitada no está autorizada.');
        }

        $organization = $this->assignments->activeOrganization($organizationId);

        if ($organization === null) {
            throw new AuthorizationException('La organización solicitada no está autorizada.');
        }

        if ($this->permissions->hasOrganizationRole($actor, $organizationId)
            && $this->permissions->allowsOrganization($actor, 'organizations.view', $organizationId)) {
            $mode = 'organization';
        } elseif ($this->permissions->allowsNational($actor, 'organizations.view')) {
            $mode = 'national';
        } elseif ($this->permissions->allowsDepartment($actor, 'organizations.view', $organization->municipality->department_id)) {
            $mode = 'department_read';
        } else {
            throw new AuthorizationException('La organización solicitada no está autorizada.');
        }

        $this->context->activate((int) $actor->id, $organizationId, $mode);

        return $organization;
    }

    /** Conserva el contrato estricto: una concesión nacional no se convierte en una membresía ficticia. */
    public function assertCurrentMembership(User $actor, bool $lock = false): OrganizationMembership
    {
        try {
            $this->assertActor($actor);

            if ($this->context->accessMode() !== 'organization'
                || !$this->permissions->hasOrganizationRole($actor, $this->context->organizationId())
                || !$this->permissions->allowsOrganization($actor, 'organizations.view', $this->context->organizationId())) {
                throw new AuthorizationException('La operación requiere una membresía y un rol organizacional vigentes.');
            }

            $membership = $this->memberships->activeFor((int) $actor->id, $this->context->organizationId(), $lock);

            if ($membership === null) {
                throw new AuthorizationException('La membresía ya no permite operar en esta organización.');
            }

            return $membership;
        } catch (Throwable $exception) {
            $this->context->clear();

            throw $exception;
        }
    }

    /** Revalida la procedencia del contexto antes de cada operación del Service. */
    public function assertCurrentAccess(User $actor, bool $lock = false): Organization
    {
        try {
            $this->assertActor($actor);
            $organization = $this->assignments->activeOrganization($this->context->organizationId(), $lock);

            if ($organization === null) {
                throw new AuthorizationException('La organización ya no está disponible.');
            }

            $allowed = match ($this->context->accessMode()) {
                'organization' => $this->assertCurrentMembership($actor, $lock)->organization_id === $organization->id,
                'national' => $this->permissions->allowsNational($actor, 'organizations.view'),
                'department_read' => $this->permissions->allowsDepartment(
                    $actor,
                    'organizations.view',
                    $organization->municipality->department_id,
                ),
                default => false,
            };

            if (!$allowed) {
                throw new AuthorizationException('El alcance autorizado ya no está vigente.');
            }

            return $organization;
        } catch (Throwable $exception) {
            $this->context->clear();

            throw $exception;
        }
    }

    public function currentOrganization(User $actor): Organization
    {
        return $this->assertCurrentAccess($actor);
    }

    /** El finally evita que procesos largos o jobs reutilicen el tenant de otra ejecución. */
    public function run(User $actor, string $organizationId, Closure $callback): mixed
    {
        try {
            $this->activate($actor, $organizationId);

            return $callback();
        } finally {
            $this->context->clear();
        }
    }

    private function assertActor(User $actor): void
    {
        if (!$this->context->isActive() || $this->context->actorId() !== (int) $actor->id
            || !Str::isUuid($this->context->organizationId())) {
            throw new AuthorizationException('El actor no coincide con un contexto institucional válido.');
        }

        $this->requireEligibleActor($actor);
    }

    private function requireEligibleActor(User $actor): void
    {
        try {
            $current = $this->users->find((int) $actor->id);
        } catch (ModelNotFoundException) {
            throw new AuthorizationException('La cuenta no permite utilizar la aplicación interna.');
        }

        if (!(bool) $current->active || $current->email_verified_at === null) {
            throw new AuthorizationException('La cuenta debe estar activa y tener el correo verificado.');
        }
    }
}

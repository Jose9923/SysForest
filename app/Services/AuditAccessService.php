<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AuditScope;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/** La auditoría global no se deriva de una membresía ni del alcance departamental de lectura. */
class AuditAccessService
{
    public function __construct(
        private PermissionService $permissions,
        private TenantContextService $tenants,
        private TenantContext $context,
    ) {
    }

    public function resolve(User $actor, ?string $requested = null): AuditScope
    {
        $kind = $requested ?? ($this->context->isActive() ? 'organization' : 'national');
        if ($kind === 'national' && $this->permissions->allowsNational($actor, 'audit.view')) {
            return new AuditScope('national');
        }
        if ($kind !== 'organization') {
            throw new AuthorizationException('No tienes autorización para consultar auditoría en ese alcance.');
        }
        $organization = $this->tenants->assertCurrentAccess($actor);
        if ($this->context->accessMode() === 'department_read'
            || !$this->permissions->allowsOrganization($actor, 'audit.view', $organization->id)) {
            throw new AuthorizationException('La consulta de auditoría requiere administración de la institución autorizada.');
        }

        return new AuditScope('organization', $organization->id, $organization->name);
    }

    public function canView(User $actor): bool
    {
        try {
            $this->resolve($actor);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function canViewNational(User $actor): bool
    {
        return $this->permissions->allowsNational($actor, 'audit.view');
    }

    /** Revalida el alcance original después de leer, sin ampliarlo si cambiaron los roles o la selección. */
    public function reauthorize(User $actor, AuditScope $scope): void
    {
        $current = $this->resolve($actor, $scope->kind);
        if ($current->organizationId !== $scope->organizationId) {
            throw new AuthorizationException('Cambió la organización autorizada. Actualiza la consulta.');
        }
    }
}

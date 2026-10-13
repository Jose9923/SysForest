<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AuditScope;
use App\Models\User;
use App\Repositories\AuditScopeRepository;
use Illuminate\Auth\Access\AuthorizationException;

/** Solo la administración nacional y departamental puede consultar o exportar actividad. */
class AuditAccessService
{
    public function __construct(
        private AuditScopeRepository $scopes,
        private TenantContextService $tenants,
    ) {
    }

    public function resolve(User $actor, ?string $requested = null): AuditScope
    {
        if (($requested !== null && !in_array($requested, ['national', 'department', 'organization'], true))
            || !$actor->exists || !$actor->getKey() || !$this->scopes->eligibleUser((int) $actor->id)) {
            throw $this->denied();
        }

        $national = $this->scopes->hasNationalAccess((int) $actor->id);
        if (($requested === null || $requested === 'national') && $national) {
            return new AuditScope('national');
        }
        if ($requested === 'national') {
            throw $this->denied();
        }

        $departmentIds = $this->scopes->departmentIds((int) $actor->id);
        if (!$national && $departmentIds === []) {
            throw $this->denied();
        }

        if ($requested === 'organization') {
            $organization = $this->tenants->assertCurrentAccess($actor);
            $departmentId = (string) $organization->municipality->department_id;
            if (!$national && !in_array($departmentId, $departmentIds, true)) {
                throw $this->denied();
            }

            return new AuditScope(
                'organization',
                $organization->id,
                $organization->name,
                $national ? [] : [$departmentId],
                [$organization->id],
            );
        }

        if ($departmentIds === []) {
            throw $this->denied();
        }

        return new AuditScope('department', departmentIds: $departmentIds, organizationIds: $this->scopes->organizationIds($departmentIds));
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
        try {
            $this->resolve($actor, 'national');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Revocar una concesión o cambiar su jurisdicción invalida también una exportación en curso. */
    public function reauthorize(User $actor, AuditScope $scope): void
    {
        $current = $this->resolve($actor, $scope->kind);
        if ($current->kind !== $scope->kind || $current->organizationId !== $scope->organizationId
            || $current->departmentIds !== $scope->departmentIds || $current->organizationIds !== $scope->organizationIds) {
            throw new AuthorizationException('Cambió el alcance autorizado de auditoría. Actualiza la consulta.');
        }
    }

    private function denied(): AuthorizationException
    {
        return new AuthorizationException('La auditoría está disponible únicamente para superadministradores y administradores departamentales en su alcance autorizado.');
    }
}

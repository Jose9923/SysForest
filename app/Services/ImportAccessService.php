<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/** Los lotes son información interna del tenant, incluso para quien tiene lectura departamental. */
class ImportAccessService
{
    public function __construct(
        private ForestryAccessService $forestry,
        private PermissionService $permissions,
        private TenantContext $context,
    ) {
    }

    public function authorize(User $actor, string $permission = 'imports.view', bool $lock = false): Organization
    {
        $organization = $this->forestry->organization($actor, $lock);
        if (!in_array($permission, ['imports.view', 'imports.create'], true)
            || $this->context->accessMode() === 'department_read'
            || !$this->permissions->allowsOrganization($actor, $permission, $organization->id)) {
            throw new AuthorizationException('No tienes permiso para trabajar con importaciones en esta organización.');
        }
        $this->forestry->authorize($actor, 'trees.view');
        $this->forestry->authorize($actor, 'inspections.view');
        $this->forestry->authorize($actor, 'inventories.view');
        if ($permission === 'imports.create') {
            $this->forestry->authorize($actor, 'trees.create');
            $this->forestry->authorize($actor, 'inspections.create');
        }

        return $organization;
    }

    public function allows(User $actor, string $permission = 'imports.view'): bool
    {
        try {
            $this->authorize($actor, $permission);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}

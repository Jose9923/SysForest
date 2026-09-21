<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

class ForestryAccessService
{
    private const PERMISSIONS = [
        'inventories.view', 'inventories.create', 'inventories.update',
        'trees.view', 'trees.create', 'trees.update', 'trees.void', 'trees.restore',
        'inspections.view', 'inspections.create', 'inspections.update',
        'evidences.view', 'evidences.create', 'evidences.update', 'evidences.delete', 'evidences.restore',
        'maintenances.view', 'maintenances.create', 'maintenances.update', 'maintenances.transition', 'maintenances.verify', 'maintenances.void', 'maintenances.restore',
    ];

    public function __construct(
        private TenantContextService $tenants,
        private TenantContext $context,
        private PermissionService $permissions,
    ) {
    }

    public function organization(User $actor, bool $lock = false): Organization
    {
        return $this->tenants->assertCurrentAccess($actor, $lock);
    }

    public function allows(User $actor, string $permission): bool
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        try {
            $organization = $this->organization($actor);
        } catch (AuthorizationException) {
            return false;
        }

        if ($this->context->accessMode() === 'department_read') {
            return in_array($permission, ['inventories.view', 'trees.view', 'inspections.view', 'evidences.view', 'maintenances.view'], true)
                && $this->permissions->allowsDepartment($actor, $permission, $organization->municipality->department_id);
        }

        return $this->permissions->allowsOrganization($actor, $permission, $organization->id);
    }

    public function authorize(User $actor, string $permission): void
    {
        if (! $this->allows($actor, $permission)) {
            throw new AuthorizationException('No tiene autorización para esta operación forestal en la organización seleccionada.');
        }
    }
}

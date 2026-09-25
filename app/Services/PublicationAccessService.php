<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/** Publicar requiere una organización explícita; el alcance departamental sigue siendo de lectura. */
class PublicationAccessService
{
    public function __construct(
        private TenantContextService $tenants,
        private TenantContext $context,
        private PermissionService $permissions,
    ) {
    }

    public function organization(User $actor, bool $lock = false): Organization
    {
        $organization = $this->tenants->assertCurrentAccess($actor, $lock);
        if ($this->context->accessMode() === 'department_read'
            || !$this->permissions->allowsOrganization($actor, 'publication.manage', $organization->id)) {
            throw new AuthorizationException('No puedes administrar la publicación de esta organización.');
        }

        return $organization;
    }

    public function canManage(User $actor): bool
    {
        try {
            $this->organization($actor);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}

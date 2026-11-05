<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Auth\RoleAssignmentRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class PermissionService
{
    public function __construct(private RoleAssignmentRepository $assignments, private UserRepository $users)
    {
    }

    public function allowsNational(User $actor, string $permission): bool
    {
        return $this->eligible($actor) && $this->assignments->hasNationalPermission((int) $actor->id, $permission);
    }

    /** Consultar un catálogo nacional no convierte el rol local en autoridad de escritura nacional. */
    public function allowsCatalogView(User $actor): bool
    {
        return $this->eligible($actor)
            && $this->assignments->hasCatalogReadPermission((int) $actor->id);
    }

    public function allowsDepartment(User $actor, string $permission, string $departmentId): bool
    {
        if (!Str::isUuid($departmentId) || !$this->eligible($actor) || !$this->assignments->activeDepartment($departmentId)) {
            return false;
        }

        return $this->assignments->hasNationalPermission((int) $actor->id, $permission)
            || $this->assignments->hasDepartmentPermission((int) $actor->id, $permission, $departmentId);
    }

    public function allowsOrganization(User $actor, string $permission, string $organizationId): bool
    {
        if (!Str::isUuid($organizationId) || !$this->eligible($actor) || !$this->assignments->activeOrganization($organizationId)) {
            return false;
        }

        // La concesión departamental no participa: nunca se convierte en escritura de un tenant.
        return $this->assignments->hasNationalPermission((int) $actor->id, $permission)
            || $this->assignments->hasOrganizationPermission((int) $actor->id, $permission, $organizationId);
    }

    public function hasOrganizationRole(User $actor, string $organizationId): bool
    {
        return Str::isUuid($organizationId) && $this->eligible($actor)
            && $this->assignments->hasOrganizationRole((int) $actor->id, $organizationId);
    }

    /** Se lee el estado actual: un objeto User anterior no conserva acceso tras su desactivación. */
    private function eligible(User $actor): bool
    {
        if (!$actor->exists || !$actor->getKey()) {
            return false;
        }

        try {
            $current = $this->users->find((int) $actor->id);

            return (bool) $current->active && $current->email_verified_at !== null;
        } catch (ModelNotFoundException) {
            return false;
        }
    }
}

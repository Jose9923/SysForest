<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Auth\PermissionService;

/** Frontera nacional: no depende del tenant seleccionado en la navegación. */
class CatalogAccessService
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function canView(User $actor): bool
    {
        return $this->permissions->allowsCatalogView($actor);
    }

    public function canManage(User $actor): bool
    {
        return $this->permissions->allowsNational($actor, 'catalogs.manage');
    }

    public function authorizeView(User $actor): void
    {
        if (!$this->canView($actor)) {
            throw new AuthorizationException('No tiene permiso para consultar catálogos nacionales.');
        }
    }

    public function authorizeManage(User $actor): void
    {
        if (!$this->canManage($actor)) {
            throw new AuthorizationException('La administración de catálogos requiere autorización nacional.');
        }
    }
}

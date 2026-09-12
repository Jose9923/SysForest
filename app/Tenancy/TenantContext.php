<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Auth\Access\AuthorizationException;

class TenantContext
{
    private ?int $actorId = null;
    private ?string $organizationId = null;
    private ?string $accessMode = null;

    /** @internal Solo TenantContextService activa un ámbito tras verificar su autorización actual. */
    public function activate(int $actorId, string $organizationId, string $accessMode = 'organization'): void
    {
        $this->clear();

        if (!in_array($accessMode, ['organization', 'national', 'department_read'], true)) {
            throw new AuthorizationException('El modo de acceso institucional no es válido.');
        }

        $this->actorId = $actorId;
        $this->organizationId = $organizationId;
        $this->accessMode = $accessMode;
    }

    public function actorId(): int
    {
        return $this->actorId ?? throw new AuthorizationException('No hay contexto institucional verificado.');
    }

    public function organizationId(): string
    {
        return $this->organizationId ?? throw new AuthorizationException('No hay contexto institucional verificado.');
    }

    public function accessMode(): string
    {
        return $this->accessMode ?? throw new AuthorizationException('No hay contexto institucional verificado.');
    }

    public function isActive(): bool
    {
        return $this->actorId !== null && $this->organizationId !== null && $this->accessMode !== null;
    }

    public function clear(): void
    {
        $this->actorId = null;
        $this->organizationId = null;
        $this->accessMode = null;
    }
}

<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Auth\Access\AuthorizationException;

class TenantContext
{
    private ?int $actorId = null;
    private ?string $organizationId = null;

    /** @internal Solo TenantContextService debe activar IDs después de verificar la membresía. */
    public function activate(int $actorId, string $organizationId): void
    {
        $this->actorId = $actorId;
        $this->organizationId = $organizationId;
    }

    public function actorId(): int
    {
        return $this->actorId ?? throw new AuthorizationException('No hay contexto institucional verificado.');
    }

    public function organizationId(): string
    {
        return $this->organizationId ?? throw new AuthorizationException('No hay contexto institucional verificado.');
    }

    public function isActive(): bool
    {
        return $this->actorId !== null && $this->organizationId !== null;
    }

    public function clear(): void
    {
        $this->actorId = null;
        $this->organizationId = null;
    }
}

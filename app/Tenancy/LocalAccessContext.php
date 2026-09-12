<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;

class LocalAccessContext
{
    private ?int $actorId = null;
    private int $expiresAt = 0;

    /** Se recibe exclusivamente de la sesión del servidor tras consumir un enlace local. */
    public function grant(int $actorId, int $expiresAt): void
    {
        $this->actorId = $actorId;
        $this->expiresAt = $expiresAt;
    }

    public function isGrantedTo(User $actor): bool
    {
        return app()->environment('local') && $this->actorId === (int) $actor->id && $this->expiresAt > time();
    }

    public function clear(): void
    {
        $this->actorId = null;
        $this->expiresAt = 0;
    }
}

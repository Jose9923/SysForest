<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\SecurityEvent;
use App\Models\User;
use InvalidArgumentException;

class SecurityEventRepository
{
    /** Registro mínimo: admite identificadores y motivos controlados, nunca credenciales o entradas arbitrarias. */
    public function record(string $event, ?User $actor = null, ?string $organizationId = null, array $metadata = []): SecurityEvent
    {
        if (!preg_match('/^[a-z][a-z0-9_.]{2,79}$/', $event)) {
            throw new InvalidArgumentException('Nombre de evento de seguridad inválido.');
        }
        $safe = array_intersect_key($metadata, array_flip([
            'session_id', 'reason', 'membership_id', 'invitation_id', 'target_user_id', 'role_name', 'department_id',
        ]));
        foreach ($safe as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                unset($safe[$key]);
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 150);
            }
        }

        $record = new SecurityEvent();
        $record->forceFill(['event' => $event, 'actor_user_id' => $actor?->id,
            'organization_id' => $organizationId, 'metadata' => (object) $safe, 'created_at' => now()])->save();

        return $record;
    }
}

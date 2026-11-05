<?php

declare(strict_types=1);

namespace App\Repositories\Auth;

use App\Models\Auth\SecurityEvent;
use App\Models\User;
use App\Auditing\AuditSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class SecurityEventRepository
{
    public function __construct(private AuditSnapshot $snapshots)
    {
    }

    /** Registro mínimo compatible con autenticación; las credenciales nunca pertenecen a este contrato. */
    public function record(
        string $event,
        ?User $actor = null,
        ?string $organizationId = null,
        array $metadata = [],
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $beforeValues = null,
        ?array $afterValues = null,
    ): SecurityEvent {
        if (!preg_match('/^[a-z][a-z0-9_.]{2,79}$/', $event)) {
            throw new InvalidArgumentException('Nombre de evento de seguridad inválido.');
        }
        if (($resourceType === null) !== ($resourceId === null)
            || ($beforeValues === null) !== ($afterValues === null)
            || ($resourceType !== null && !in_array($resourceType, $this->snapshots->types(), true))
            || ($resourceId !== null && !Str::isUuid($resourceId) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $resourceId) !== 1)) {
            throw new InvalidArgumentException('La identidad auditada debe tener un tipo e identificador válidos.');
        }
        if ($beforeValues !== null && ($resourceType === null || DB::transactionLevel() < 1)) {
            throw new LogicException('El cambio auditado debe confirmarse en la transacción de su recurso.');
        }
        $safe = array_intersect_key($metadata, array_flip([
            'session_id', 'reason', 'membership_id', 'invitation_id', 'target_user_id', 'role_name', 'department_id',
            'catalog_key', 'resource_id', 'resource_code', 'rule_version', 'revision_id', 'change_type',
        ]));
        foreach ($safe as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                unset($safe[$key]);
            } elseif (is_string($value)) {
                // El motivo forestal conserva la extensión admitida por el servicio de ubicación.
                $safe[$key] = mb_substr($value, 0, $key === 'reason' ? 5000 : 150);
            }
        }
        if (array_key_exists('change_type', $safe) && !in_array($safe['change_type'], [null, 'correction', 'relocation', 'transplant'], true)) {
            unset($safe['change_type']);
        }
        // El servidor genera la correlación; no copia cabeceras, cookies, IP ni parámetros del cliente.
        $request = request();
        $requestId = $request->attributes->get('sysforest.audit_request_id');
        if (!is_string($requestId) || !Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('sysforest.audit_request_id', $requestId);
        }
        $record = new SecurityEvent();
        $record->forceFill([
            'event' => $event, 'actor_user_id' => $actor?->id, 'organization_id' => $organizationId,
            'metadata' => (object) $safe, 'created_at' => now(), 'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before_values' => $beforeValues === null ? null : (object) $this->snapshots->sanitize($resourceType, $beforeValues),
            'after_values' => $afterValues === null ? null : (object) $this->snapshots->sanitize($resourceType, $afterValues),
            'context' => (object) ['request_id' => $requestId],
        ])->save();

        return $record;
    }

    /** La transacción obliga a revertir el cambio de negocio cuando falla su auditoría. */
    public function recordChange(string $event, User $actor, ?string $organizationId, string $resourceType, string $resourceId, array $before, array $after, array $metadata = []): SecurityEvent
    {
        return $this->record($event, $actor, $organizationId, $metadata, $resourceType, $resourceId, $before, $after);
    }
}

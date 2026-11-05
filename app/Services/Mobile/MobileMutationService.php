<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobileIdempotencyConflictException;
use App\Models\User;
use App\Repositories\Mobile\MobileMutationRepository;
use App\Repositories\Users\UserRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use App\Services\Forestry\ForestryAccessService;

/** Confirma cambio y recibo juntos; nunca repite una escritura tras perderse la respuesta HTTP. */
class MobileMutationService
{
    public function __construct(
        private ForestryAccessService $access,
        private UserRepository $users,
        private MobileMutationRepository $receipts,
    ) {
    }

    public function run(User $actor, string $action, string $targetId, string $permission, array $input, Closure $write, Closure $authorize): array
    {
        $targetId = strtolower($targetId);
        $operationId = strtolower($input['operation_id']);
        unset($input['operation_id']);
        $hash = hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($actor, $action, $targetId, $permission, $operationId, $hash, $write, $authorize): array {
            // El bloqueo institucional serializa el mismo UUID incluso entre actores diferentes.
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $this->access->authorize($actor, $permission);
            // Un recibo conserva el resultado, pero nunca conserva un permiso revocado sobre el recurso.
            $authorize($actor, $organization->id);
            $receipt = $this->receipts->receipt($organization->id, $operationId);
            if ($receipt !== null) {
                if ($receipt->created_by !== (int) $actor->id || $receipt->action !== $action
                    || $receipt->target_id !== $targetId || !hash_equals($receipt->payload_hash, $hash)) {
                    throw new MobileIdempotencyConflictException('operation_id ya fue confirmado con otro contenido, usuario, recurso o acción. Conserva la operación original y revisa el conflicto.');
                }

                return ['operation_id' => $operationId, 'status' => 'replayed', 'data' => $receipt->result];
            }
            $result = $write($actor, $organization->id);
            $this->receipts->confirm(['organization_id' => $organization->id, 'operation_id' => $operationId,
                'action' => $action, 'target_id' => $targetId, 'created_by' => $actor->id,
                'tree_id' => $result['tree_id'], 'payload_hash' => $hash, 'result' => $result['data']]);

            return ['operation_id' => $operationId, 'status' => $action === 'inspection.create' ? 'created' : 'updated', 'data' => $result['data']];
        }, 3);
    }

    /** El orden de claves no cambia una operación; tipos, valores y orden de listas sí. */
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }
}

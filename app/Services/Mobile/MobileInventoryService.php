<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobileIdempotencyConflictException;
use App\Models\Inventories\Inventory;
use App\Models\User;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Mobile\MobileInventoryRepository;
use App\Repositories\Users\UserRepository;
use App\Support\Mobile\MobileInventoryProjection;
use App\Validation\Mobile\MobileInventoryInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Inventories\InventoryService;

class MobileInventoryService
{
    public function __construct(
        private ForestryAccessService $access,
        private UserRepository $users,
        private InventoryRepository $inventories,
        private MobileInventoryRepository $receipts,
        private InventoryService $writes,
    ) {
    }

    public function create(User $actor, array $input): array
    {
        if (array_diff(array_keys($input), ['operation_id', 'inventory']) !== []) {
            throw ValidationException::withMessages(['inventory' => 'La operación contiene campos no permitidos.']);
        }
        $data = Validator::make($input, MobileInventoryInput::rules(), MobileInventoryInput::messages())->validate();
        $operationId = strtolower($data['operation_id']);
        $inventoryInput = $data['inventory'] + ['status' => 'active', 'active' => true];
        $hash = hash('sha256', json_encode($this->canonical($inventoryInput), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($actor, $operationId, $inventoryInput, $hash): array {
            // Actor y organización se bloquean en el orden común de todas las escrituras forestales.
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            Gate::forUser($actor)->authorize('create', Inventory::class);
            $this->access->authorize($actor, 'inventories.view');
            $receipt = $this->receipts->receipt($organization->id, $operationId);
            if ($receipt !== null) {
                if ($receipt->created_by !== (int) $actor->id || !hash_equals($receipt->payload_hash, $hash)) {
                    throw new MobileIdempotencyConflictException('operation_id ya fue confirmado con otro contenido o usuario. Conserva la operación original y revisa el conflicto.');
                }
                Gate::forUser($actor)->authorize('view', $this->inventories->findForTenant($organization->id, $receipt->inventory_id));

                return ['operation_id' => $operationId, 'status' => 'replayed', 'data' => $receipt->result];
            }
            $inventory = $this->writes->create($actor, $inventoryInput);
            $result = MobileInventoryProjection::make($inventory);
            $this->receipts->confirm(['organization_id' => $organization->id, 'operation_id' => $operationId,
                'inventory_id' => $inventory->id, 'created_by' => $actor->id, 'payload_hash' => $hash, 'result' => $result]);

            return ['operation_id' => $operationId, 'status' => 'created', 'data' => $result];
        }, 3);
    }

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

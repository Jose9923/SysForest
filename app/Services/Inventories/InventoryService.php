<?php

declare(strict_types=1);

namespace App\Services\Inventories;

use App\Enums\Inventories\InventoryStatus;
use App\Exceptions\Shared\StaleRecordException;
use App\Validation\Inventories\InventoryInput;
use App\Models\Inventories\Inventory;
use App\Models\User;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditSnapshotService;
use App\Services\Forestry\ForestryAccessService;

class InventoryService
{
    public function __construct(
        private InventoryRepository $inventories,
        private ForestryAccessService $access,
        private UserRepository $users,
        private SecurityEventRepository $events,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function index(User $actor, array $filters): array
    {
        $organization = $this->access->organization($actor);
        $this->access->authorize($actor, 'inventories.view');
        $filters = Validator::make($filters, InventoryInput::filterRules(), InventoryInput::messages())->validate();

        return array_merge($this->viewFlags($actor), [
            'organization' => $organization, 'inventories' => $this->inventories->paginate($organization->id, $filters),
            'filters' => $filters,
        ]);
    }

    public function form(User $actor, ?string $id = null): array
    {
        $organization = $this->access->organization($actor);
        $inventory = $id === null ? null : $this->inventories->findForTenant($organization->id, $id);
        if ($inventory === null) {
            Gate::forUser($actor)->authorize('create', Inventory::class);
        } else {
            Gate::forUser($actor)->authorize('update', $inventory);
        }

        return array_merge($this->viewFlags($actor), ['organization' => $organization, 'inventory' => $inventory]);
    }

    public function show(User $actor, string $id): array
    {
        $organization = $this->access->organization($actor);
        $inventory = $this->inventories->findForTenant($organization->id, $id);
        Gate::forUser($actor)->authorize('view', $inventory);

        return array_merge($this->viewFlags($actor), ['organization' => $organization, 'inventory' => $inventory]);
    }

    public function create(User $actor, array $input): Inventory
    {
        return $this->save($actor, null, $input);
    }

    public function update(User $actor, string $id, array $input): Inventory
    {
        return $this->save($actor, $id, $input);
    }

    private function save(User $actor, ?string $id, array $input): Inventory
    {
        try {
            return DB::transaction(function () use ($actor, $id, $input): Inventory {
                $actor = $this->users->findForUpdate((int) $actor->id);
                $organization = $this->access->organization($actor, true);
                $inventory = $id === null ? null : $this->inventories->findForTenant($organization->id, $id, true);
                if ($inventory === null) {
                    Gate::forUser($actor)->authorize('create', Inventory::class);
                } else {
                    Gate::forUser($actor)->authorize('update', $inventory);
                }

                $data = $this->validatedInput($input, $inventory !== null);
                if ($inventory !== null && (int) $data['lock_version'] !== $inventory->lock_version) {
                    throw new StaleRecordException();
                }
                if ($inventory !== null && $data['code'] !== $inventory->code) {
                    throw ValidationException::withMessages(['code' => 'El código del inventario es permanente y no puede modificarse.']);
                }
                if ($this->inventories->codeExists($organization->id, $data['code'], $id)) {
                    throw ValidationException::withMessages(['code' => 'El código ya existe en esta organización.']);
                }
                $before = $inventory === null ? [] : $this->snapshots->snapshot($inventory);
                unset($data['organization_id'], $data['created_by'], $data['updated_by'], $data['created_at'], $data['updated_at'], $data['id']);
                if ($inventory === null) {
                    unset($data['lock_version']);
                    $inventory = $this->inventories->create(array_merge($data, [
                        'organization_id' => $organization->id, 'created_by' => $actor->id,
                        'updated_by' => $actor->id, 'lock_version' => 1,
                    ]));
                } else {
                    $expectedVersion = (int) $data['lock_version'];
                    unset($data['lock_version']);
                    $inventory = $this->inventories->updateWithVersion(
                        $organization->id,
                        $inventory->id,
                        $expectedVersion,
                        array_merge($data, ['updated_by' => $actor->id]),
                    ) ?? throw new StaleRecordException();
                }
                $this->events->recordChange(
                    $id === null ? 'inventory.created' : 'inventory.updated',
                    $actor,
                    $organization->id,
                    'inventory',
                    $inventory->id,
                    $before,
                    $this->snapshots->snapshot($inventory),
                    [
                        'resource_id' => $inventory->id, 'resource_code' => $inventory->code,
                    ]
                );

                return $inventory;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => 'El código ya existe en esta organización.']);
            }
            throw $exception;
        }
    }

    private function validatedInput(array $input, bool $updating): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field]) && trim($input[$field]) === '') {
                $input[$field] = null;
            }
        }
        $data = Validator::make($input, InventoryInput::rules($updating), InventoryInput::messages())->validate();
        $data['name'] = trim($data['name']);
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && $data['ends_at'] < $data['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha final no puede ser anterior a la fecha inicial.']);
        }

        return $data;
    }

    private function viewFlags(User $actor): array
    {
        $canUpdate = $this->access->allows($actor, 'inventories.update');

        return [
            'canCreate' => $this->access->allows($actor, 'inventories.create'),
            'canUpdate' => $canUpdate, 'canManageInventory' => $canUpdate,
            'inventoryStatuses' => InventoryStatus::options(),
        ];
    }
}

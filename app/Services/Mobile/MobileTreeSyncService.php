<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Support\Mobile\MobileIdentifiers as Ids;
use App\Enums\Inventories\InventoryStatus;
use App\Models\Trees\Tree;
use App\Models\User;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Mobile\MobileTreeSyncRepository;
use App\Repositories\Users\UserRepository;
use App\Validation\Trees\TreeInput;
use App\Validation\Mobile\MobileRiskInput;
use App\Validation\Inspections\TreeInspectionInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Inspections\TreeInspectionService;
use App\Services\Trees\TreeService;

/** La reconexión confirma cada captura completa junto con su clave de reintento. */
class MobileTreeSyncService
{
    public function __construct(
        private ForestryAccessService $access,
        private UserRepository $users,
        private InventoryRepository $inventories,
        private MobileTreeSyncRepository $receipts,
        private TreeService $trees,
        private TreeInspectionService $inspections,
    ) {
    }

    public function sync(User $actor, string $inventoryId, array $items): array
    {
        $inventoryId = Ids::internal('inventories', strtolower($inventoryId));
        $organization = $this->access->organization($actor);
        $inventory = $this->inventories->findForTenant($organization->id, $inventoryId);
        Gate::forUser($actor)->authorize('view', $inventory);
        Gate::forUser($actor)->authorize('create', Tree::class);
        $results = [];
        foreach ($items as $item) {
            $clientId = strtolower($item['client_id']);
            try {
                $results[] = $this->capture($actor, $inventoryId, $clientId, $item);
            } catch (ValidationException $exception) {
                $results[] = ['client_id' => $clientId, 'status' => 'rejected', 'http_status' => 422,
                    'message' => 'Revisa los campos de esta captura.', 'errors' => $exception->errors()];
            } catch (ConflictHttpException $exception) {
                $results[] = ['client_id' => $clientId, 'status' => 'conflict', 'http_status' => 409, 'code' => 'idempotency_conflict',
                    'message' => $exception->getMessage()];
            } catch (AuthorizationException) {
                $results[] = ['client_id' => $clientId, 'status' => 'forbidden', 'http_status' => 403,
                    'message' => 'La cuenta no tiene permiso para guardar esta captura.'];
            }
        }

        return [
            'inventory_id' => Ids::id($inventory), 'organization_id' => Ids::id($organization),
            'summary' => [
                'received' => count($results),
                'created' => count(array_filter($results, fn (array $row) => $row['status'] === 'created')),
                'replayed' => count(array_filter($results, fn (array $row) => $row['status'] === 'replayed')),
                'failed' => count(array_filter($results, fn (array $row) => !in_array($row['status'], ['created', 'replayed'], true))),
            ],
            'results' => $results,
        ];
    }

    private function capture(User $actor, string $inventoryId, string $clientId, array $item): array
    {
        return DB::transaction(function () use ($actor, $inventoryId, $clientId, $item): array {
            // Mismo orden de bloqueo que los servicios forestales; serializa también reintentos concurrentes.
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            Gate::forUser($actor)->authorize('create', Tree::class);
            $inventory = $this->inventories->findForTenant($organization->id, $inventoryId, true);
            Gate::forUser($actor)->authorize('view', $inventory);
            $hash = hash('sha256', json_encode($this->canonical([
                'tree' => $item['tree'] ?? null, 'inspection' => $item['inspection'] ?? null,
            ]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            if (is_array($item['inspection'] ?? null)) {
                $this->access->authorize($actor, 'inspections.create');
                if (MobileRiskInput::hasDecision($item['inspection'])) {
                    $this->access->authorize($actor, 'risk.view');
                }
            }
            $receipt = $this->receipts->receipt($organization->id, $clientId);
            if ($receipt !== null) {
                if ($receipt->created_by !== (int) $actor->id || $receipt->inventory_id !== $inventoryId
                    || !hash_equals($receipt->payload_hash, $hash)) {
                    throw new ConflictHttpException('client_id ya fue confirmado con otro contenido, usuario o inventario. Conserva la captura original y revisa el conflicto.');
                }

                return ['client_id' => $clientId, 'status' => 'replayed', 'http_status' => 200] + $receipt->result;
            }
            if (!$inventory->active || $inventory->status !== InventoryStatus::Active) {
                throw ValidationException::withMessages(['inventory_id' => 'Solo se pueden enviar capturas nuevas a inventarios activos.']);
            }
            $data = $this->validateItem($item);
            $data['tree'] = Ids::input($data['tree']);
            if (isset($data['inspection'])) {
                $data['inspection'] = Ids::input($data['inspection']);
            }
            $tree = $this->trees->create($actor, array_merge($data['tree'], ['inventory_ids' => [$inventoryId]]));
            $participation = $this->receipts->participation($organization->id, $inventoryId, $tree->id);
            $inspection = isset($data['inspection'])
                ? $this->inspections->create($actor, $tree->id, array_merge($data['inspection'], ['inventory_tree_id' => $participation->id]), 'mobile')
                : null;
            $result = ['tree_id' => Ids::id($tree), 'tree_code' => $tree->code,
                'inventory_tree_id' => Ids::id($participation), 'inspection_id' => Ids::id($inspection),
                'created_by' => (int) $actor->id];
            $this->receipts->confirm([
                'organization_id' => $organization->id, 'inventory_id' => $inventoryId,
                'client_id' => $clientId, 'created_by' => $actor->id, 'payload_hash' => $hash,
                'tree_id' => $tree->id, 'result' => $result,
            ]);

            return ['client_id' => $clientId, 'status' => 'created', 'http_status' => 201] + $result;
        }, 3);
    }

    private function validateItem(array $item): array
    {
        $treeRules = Ids::publicRules(TreeInput::rules());
        // El inventario viene de la ruta y la identidad del JWT, nunca de un árbol del lote.
        $treeRules['inventory_ids'] = ['prohibited'];
        unset($treeRules['inventory_ids.*']);
        $treeRules['captured_at_local'] = ['prohibited'];
        $treeRules['captured_at'] = ['nullable', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D'];
        $inspectionRules = Ids::publicRules(TreeInspectionInput::rules());
        $inspectionRules['inventory_tree_id'] = ['prohibited'];
        foreach ([$treeRules, $inspectionRules] as $index => $fieldRules) {
            foreach ($fieldRules as $field => $constraints) {
                if (in_array('prohibited', $constraints, true)) {
                    if ($index === 0) {
                        $treeRules[$field] = ['missing'];
                    } else {
                        $inspectionRules[$field] = ['missing'];
                    }
                }
            }
        }
        $keys = fn (array $rules) => implode(',', array_filter(array_keys($rules), fn (string $key) => !str_contains($key, '.')));
        $rules = ['tree' => ['required', 'array:'.$keys($treeRules)],
            'inspection' => ['sometimes', 'nullable', 'array:'.$keys($inspectionRules)]];
        foreach ($treeRules as $field => $fieldRules) {
            $rules['tree.'.$field] = $fieldRules;
        }
        if (isset($item['inspection'])) {
            foreach ($inspectionRules as $field => $fieldRules) {
                // La regla dendrométrica reconoce el nombre de dominio, sin el prefijo HTTP.
                $rules['inspection.'.$field] = array_map(
                    fn ($rule) => $rule instanceof \Closure
                        ? fn (string $attribute, mixed $value, \Closure $fail) => $rule($field, $value, $fail)
                        : $rule,
                    $fieldRules,
                );
            }
        }

        return Validator::make($item, $rules, ['missing' => 'El campo :attribute lo determina el servidor y debe omitirse.'] + TreeInput::messages() + TreeInspectionInput::messages())->validate();
    }

    /** El orden de claves JSON no cambia la captura; sí lo hacen valores, tipos y orden de listas. */
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

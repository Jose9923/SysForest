<?php

declare(strict_types=1);

namespace App\Repositories\Inventories;

use App\Models\Inventories\Inventory;
use App\Models\Inventories\InventoryTree;
use App\Models\Trees\Tree;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryRepository
{
    public function findForTenant(string $organizationId, string $id, bool $lock = false): Inventory
    {
        $this->requireUuid($organizationId);
        $this->requireUuid($id);
        $query = Inventory::query()->where('organization_id', $organizationId)->whereKey($id)
            ->with(['creator', 'updater'])->withCount('trees');

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function paginate(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Inventory::query()->where('organization_id', $organizationId)->withCount('trees');
        if (($filters['q'] ?? '') !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn (Builder $query) => $query->where('name', 'ilike', $pattern)->orWhere('code', 'ilike', $pattern));
        }
        if (isset($filters['active'])) {
            $query->where('active', (string) $filters['active'] === '1');
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $sort = in_array($filters['sort'] ?? '', ['name', 'code', 'starts_at', 'created_at', 'status'], true) ? $filters['sort'] : 'name';
        $direction = ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(20)->withQueryString();
    }

    /** Disponible no equivale a campaña activa: las opciones operativas exigen ambas condiciones. */
    public function options(string $organizationId, bool $activeOnly = false): Collection
    {
        $query = Inventory::query()->where('organization_id', $organizationId);
        if ($activeOnly) {
            $query->where('active', true)->where('status', 'active');
        }

        return $query->orderBy('name')->orderBy('id')->get();
    }

    public function codeExists(string $organizationId, string $code, ?string $exceptId = null): bool
    {
        return Inventory::query()->where('organization_id', $organizationId)->where('code', $code)
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))->exists();
    }

    /** El servicio proporciona organización y autores; no se toman de la petición. */
    public function create(array $attributes): Inventory
    {
        $inventory = new Inventory();
        $inventory->forceFill($attributes)->save();

        return $this->findForTenant($inventory->organization_id, $inventory->id);
    }

    public function updateWithVersion(string $organizationId, string $id, int $expectedVersion, array $attributes): ?Inventory
    {
        $allowed = ['code', 'name', 'description', 'starts_at', 'ends_at', 'status', 'active', 'updated_by'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw new InvalidArgumentException('La actualización contiene atributos de inventario no permitidos.');
        }
        $changed = Inventory::query()->where('organization_id', $organizationId)->whereKey($id)
            ->where('lock_version', $expectedVersion)->update(array_merge($attributes, [
                'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]));

        return $changed === 1 ? $this->findForTenant($organizationId, $id) : null;
    }

    /** Asociación idempotente; conserva quién y cuándo incluyó inicialmente al individuo. */
    public function attach(
        string $organizationId,
        string $inventoryId,
        string $treeId,
        int $actorId,
        ?string $notes = null,
    ): InventoryTree {
        $this->findForTenant($organizationId, $inventoryId);
        if (! \App\Support\Identifiers\RecordIdentifier::isValid($treeId)) {
            throw (new ModelNotFoundException())->setModel(Tree::class, [$treeId]);
        }
        Tree::query()->where('organization_id', $organizationId)->whereKey($treeId)->firstOrFail();

        return InventoryTree::query()->firstOrCreate([
            'organization_id' => $organizationId, 'inventory_id' => $inventoryId, 'tree_id' => $treeId,
        ], ['included_at' => now(), 'included_by' => $actorId, 'notes' => $notes]);
    }

    /** Siembra institucional sin inventar un funcionario y sin sobrescribir ajustes posteriores. */
    public function seedPilot(string $organizationId, array $defaults): Inventory
    {
        return DB::transaction(function () use ($organizationId, $defaults): Inventory {
            $existing = Inventory::query()->where('organization_id', $organizationId)->where('code', $defaults['code'])->first();

            return $existing ?? $this->create(array_merge($defaults, [
                'organization_id' => $organizationId, 'created_by' => null, 'updated_by' => null, 'lock_version' => 1,
            ]));
        });
    }

    private function requireUuid(string $id): void
    {
        if (! \App\Support\Identifiers\RecordIdentifier::isValid($id)) {
            throw (new ModelNotFoundException())->setModel(Inventory::class, [$id]);
        }
    }
}

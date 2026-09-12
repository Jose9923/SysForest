<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Tree;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** Todas las lecturas operativas exigen el tenant; el UUID por sí solo nunca resuelve un individuo. */
class TreeRepository
{
    private const RELATIONS = ['species.family', 'species.commonNames', 'placement', 'growthHabit', 'establishmentOrigin', 'biogeographicOrigin', 'inventories', 'creator', 'updater', 'deletionReason'];

    public function queryForTenant(string $organizationId): Builder
    {
        return Tree::query()->where('trees.organization_id', $organizationId);
    }

    public function findForTenant(string $organizationId, string $id, bool $lock = false, bool $withTrashed = false): Tree
    {
        if (!Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Tree::class);
        }
        $query = $this->queryForTenant($organizationId)->whereKey($id);
        if ($withTrashed) {
            $query->withTrashed();
        }
        $query->select('trees.*')->selectRaw('ST_Y(geometry) AS latitude, ST_X(geometry) AS longitude');
        $tree = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();

        return $tree->load(self::RELATIONS);
    }

    public function paginate(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = $this->queryForTenant($organizationId)->with(self::RELATIONS);
        if (($filters['trashed'] ?? '') === 'only') {
            $query->onlyTrashed();
        }
        foreach (['species_id', 'placement_id', 'current_status'] as $key) {
            if (!empty($filters[$key])) {
                $query->where('trees.'.$key, $filters[$key]);
            }
        }
        if (!empty($filters['inventory_id'])) {
            $query->whereHas('inventories', fn (Builder $q) => $q->where('inventories.id', $filters['inventory_id'])->where('inventories.organization_id', $organizationId));
        }
        if (!empty($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('trees.code', 'ilike', $term)->orWhere('external_number', 'ilike', $term)
                    ->orWhere('location_description', 'ilike', $term)
                    ->orWhereHas('species', fn (Builder $s) => $s->where('scientific_name', 'ilike', $term)
                        ->orWhereHas('commonNames', fn (Builder $n) => $n->where('name', 'ilike', $term)));
            });
        }
        $direction = ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc';
        $sort = $filters['sort'] ?? 'code';
        if ($sort === 'scientific_name') {
            $query->leftJoin('species', 'species.id', '=', 'trees.species_id')->select('trees.*')->orderBy('species.scientific_name', $direction);
        } else {
            $query->orderBy('trees.'.($sort === 'created_at' ? 'created_at' : 'code'), $direction);
        }

        return $query->orderBy('trees.id')->paginate(20)->withQueryString();
    }

    /** Los atributos de identidad llegan exclusivamente desde el servicio transaccional. */
    public function create(array $attributes): Tree
    {
        $tree = new Tree();
        $tree->forceFill($attributes)->save();

        return $tree;
    }

    public function update(Tree $tree, array $attributes): Tree
    {
        unset($attributes['id'], $attributes['organization_id'], $attributes['code'], $attributes['created_by'], $attributes['created_at'], $attributes['geometry'], $attributes['latitude'], $attributes['longitude']);
        $tree->forceFill($attributes)->save();

        return $tree;
    }

    public function hasActiveInventory(Tree $tree): bool
    {
        return $tree->inventories()->where('inventories.organization_id', $tree->organization_id)
            ->where('inventories.active', true)->where('inventories.status', 'active')->exists();
    }

    /** SoftDeletes distingue un error de registro de la muerte o retiro del individuo real. */
    public function void(Tree $tree, array $metadata): void
    {
        $tree->forceFill($metadata)->save();
        $tree->delete();
    }

    public function restore(Tree $tree, int $actorId): void
    {
        $tree->forceFill(['deleted_by' => null, 'deletion_reason_id' => null, 'deletion_notes' => null,
            'restored_by' => $actorId, 'restored_at' => now()]);
        $tree->restore();
    }

    public function detail(Tree $tree): Tree
    {
        return $tree->load(['statusHistories.changedBy', 'locationHistories.changedBy']);
    }
}

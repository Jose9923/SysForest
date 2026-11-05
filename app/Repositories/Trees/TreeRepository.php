<?php

declare(strict_types=1);

namespace App\Repositories\Trees;

use App\DTOs\Trees\TreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;
use App\Models\Trees\Tree;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** Todas las lecturas operativas exigen el tenant; el UUID por sí solo nunca resuelve un individuo. */
class TreeRepository
{
    private const RELATIONS = ['species.family', 'species.commonNames', 'placement', 'growthHabit', 'establishmentOrigin', 'biogeographicOrigin', 'inventories', 'creator', 'updater', 'deletionReason'];

    public function __construct(private TreeQueryRepository $queries)
    {
    }

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

    /** La anulación conserva la identidad y, por tanto, también reserva su código. */
    public function codeExists(string $organizationId, string $code): bool
    {
        return Tree::withTrashed()->where('organization_id', $organizationId)->where('code', $code)->exists();
    }

    public function paginate(string $organizationId, array $filters, bool $includeRisk = false): LengthAwarePaginator
    {
        $criteria = TreeFilterDTO::fromValidated($filters);
        $query = $this->queries->query(TreeReadScope::organization($organizationId), $criteria)->with(self::RELATIONS);
        if ($includeRisk) {
            $query->addSelect('latest_inspection.risk_priority', 'latest_inspection.risk_result', 'latest_inspection.risk_evaluation_id');
        }

        return $this->queries->ordered($query, $criteria)->paginate(20)->withQueryString();
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

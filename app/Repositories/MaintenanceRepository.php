<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Maintenance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class MaintenanceRepository
{
    private const RELATIONS = ['tree', 'maintenanceType', 'assignee', 'creator', 'updater', 'deleter'];

    public function findForTenant(string $organizationId, string $id, bool $lock = false, bool $withTrashed = false): Maintenance
    {
        $this->uuid($organizationId);
        $this->uuid($id);
        $query = $this->query($organizationId)->whereKey($id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function detail(string $organizationId, string $id, bool $withTrashed = false): Maintenance
    {
        return $this->findForTenant($organizationId, $id, false, $withTrashed)
            ->load(['statusHistories.changedBy', 'revisions.changedBy']);
    }

    public function paginate(string $organizationId, array $filters = []): LengthAwarePaginator
    {
        $this->uuid($organizationId);
        $query = $this->query($organizationId);
        // Este indicador solo procede del Service autorizado; no es un filtro del navegador.
        if (($filters['include_voided_trees'] ?? false) !== true) {
            $query->whereHas('tree', fn (Builder $tree) => $tree->whereNull('trees.deleted_at'));
        }
        if (($filters['trashed'] ?? null) === 'only') {
            $query->onlyTrashed();
        }
        foreach (['tree_id', 'maintenance_type_id'] as $key) {
            if (!empty($filters[$key])) {
                $this->uuid($filters[$key]);
                $query->where($key, $filters[$key]);
            }
        }
        foreach (['status', 'priority', 'assigned_to'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where($key, $filters[$key]);
            }
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && trim((string) $filters[$key]) !== '') {
                $query->whereDate('scheduled_date', $operator, $filters[$key]);
            }
        }
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(fn (Builder $search) => $search->where('code', 'ilike', $term)->orWhere('notes', 'ilike', $term)
                ->orWhereHas('tree', fn (Builder $tree) => $tree->where('code', 'ilike', $term)));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();
    }

    public function timelineForTree(string $organizationId, string $treeId): Collection
    {
        $this->uuid($organizationId);
        $this->uuid($treeId);

        return $this->query($organizationId)->where('tree_id', $treeId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();
    }

    /** API interna: el Service entrega identidad y procedencia verificadas. */
    public function create(array $attributes): Maintenance
    {
        $this->requireTransaction();
        $maintenance = new Maintenance();
        $maintenance->forceFill($attributes)->save();

        return $this->findForTenant($maintenance->organization_id, $maintenance->id);
    }

    public function update(Maintenance $maintenance, array $attributes): Maintenance
    {
        return $this->persist($maintenance, array_intersect_key($attributes, array_flip([
            'maintenance_type_id', 'priority', 'scheduled_date', 'performed_date', 'assigned_to',
            'notes', 'result', 'status', 'updated_by', 'lock_version',
        ])));
    }

    public function void(Maintenance $maintenance, array $attributes): Maintenance
    {
        $changes = array_intersect_key($attributes, array_flip(['deleted_by', 'deletion_notes', 'updated_by', 'lock_version']));
        $changes['deleted_at'] = now();

        return $this->persist($maintenance, $changes);
    }

    public function restore(Maintenance $maintenance, array $attributes): Maintenance
    {
        $changes = array_intersect_key($attributes, array_flip(['updated_by', 'lock_version']));
        $changes += ['deleted_at' => null, 'deleted_by' => null, 'deletion_notes' => null];

        return $this->persist($maintenance, $changes);
    }

    private function query(string $organizationId): Builder
    {
        return Maintenance::query()->where('organization_id', $organizationId)->with(self::RELATIONS);
    }

    private function persist(Maintenance $maintenance, array $changes): Maintenance
    {
        $this->requireTransaction();
        $maintenance->forceFill($changes)->save();

        return $this->findForTenant($maintenance->organization_id, $maintenance->id, false, true);
    }

    private function uuid(string $value): void
    {
        if (!Str::isUuid($value)) {
            throw (new ModelNotFoundException())->setModel(Maintenance::class);
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La orden y su historial requieren una transacción.');
        }
    }
}

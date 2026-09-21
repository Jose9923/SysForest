<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Evidence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class EvidenceRepository
{
    private const RELATIONS = ['evidenceType', 'uploader', 'updater', 'deleter', 'tree', 'inspection.tree', 'maintenance.tree'];

    public function findForTenant(string $organizationId, string $id, bool $lock = false, bool $withTrashed = false): Evidence
    {
        $this->requireUuid($organizationId);
        $this->requireUuid($id);
        $query = $this->queryForTenant($organizationId)->whereKey($id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** Los atributos físicos y el propietario proceden exclusivamente del flujo autorizado de carga. */
    public function create(array $attributes): Evidence
    {
        $this->requireTransaction();
        if (isset($attributes['metadata']) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = (object) $attributes['metadata'];
        }
        $evidence = new Evidence();
        $evidence->forceFill($attributes)->save();

        return $this->findForTenant($evidence->organization_id, $evidence->id);
    }

    public function update(Evidence $evidence, array $attributes): Evidence
    {
        return $this->persist($evidence, array_intersect_key($attributes, array_flip([
            'evidence_type_id', 'caption', 'notes', 'captured_at', 'updated_by', 'lock_version',
        ])));
    }

    public function void(Evidence $evidence, array $attributes): Evidence
    {
        $changes = array_intersect_key($attributes, array_flip(['deleted_by', 'deletion_notes', 'updated_by', 'lock_version']));
        $changes['deleted_at'] = now();

        return $this->persist($evidence, $changes);
    }

    public function restore(Evidence $evidence, array $attributes): Evidence
    {
        $changes = array_intersect_key($attributes, array_flip(['updated_by', 'lock_version']));
        $changes += ['deleted_at' => null, 'deleted_by' => null, 'deletion_notes' => null];

        return $this->persist($evidence, $changes);
    }

    public function forOwner(string $organizationId, ?string $treeId, ?string $inspectionId, array $filters = [], ?string $maintenanceId = null): Collection
    {
        return $this->ownerQuery($organizationId, $treeId, $inspectionId, $filters, $maintenanceId)->get();
    }

    public function paginateForOwner(string $organizationId, ?string $treeId, ?string $inspectionId, array $filters = [], ?string $maintenanceId = null): LengthAwarePaginator
    {
        return $this->ownerQuery($organizationId, $treeId, $inspectionId, $filters, $maintenanceId)->paginate(20)->withQueryString();
    }

    /** La ficha muestra una muestra acotada y el total; la página solicitada no altera este resumen. */
    public function summaryForOwner(string $organizationId, ?string $treeId, ?string $inspectionId, int $limit = 6, ?string $maintenanceId = null): LengthAwarePaginator
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('El resumen requiere un límite positivo.');
        }

        return $this->ownerQuery($organizationId, $treeId, $inspectionId, [], $maintenanceId)->paginate($limit, ['*'], 'evidence_summary', 1);
    }

    /** Prepara galerías por lotes para árboles, inspecciones y mantenimientos, sin consultar desde Blade. */
    public function forOwners(string $organizationId, array $treeIds = [], array $inspectionIds = [], array $filters = [], array $maintenanceIds = []): Collection
    {
        $this->requireUuid($organizationId);
        foreach (array_merge($treeIds, $inspectionIds, $maintenanceIds) as $id) {
            $this->requireUuid($id);
        }
        if ($treeIds === [] && $inspectionIds === [] && $maintenanceIds === []) {
            return new Collection();
        }

        $query = $this->queryForTenant($organizationId)->where(function (Builder $owners) use ($treeIds, $inspectionIds, $maintenanceIds): void {
            $owners->whereIn('tree_id', array_unique($treeIds))->orWhereIn('tree_inspection_id', array_unique($inspectionIds))
                ->orWhereIn('maintenance_id', array_unique($maintenanceIds));
        });

        return $this->applyFilters($query, $filters)->get();
    }

    /** Consulta interna de reconciliación: una referencia anulada también protege el archivo. */
    public function referencedStoragePath(string $disk, string $path): bool
    {
        return Evidence::withTrashed()->where('disk', $disk)->where('path', $path)->exists();
    }

    /** Carga y reconciliación coordinan el mismo UUID, incluso antes de que exista la fila. */
    public function lockStorageOperation(string $id): void
    {
        $this->requireTransaction();
        $this->requireUuid($id);
        DB::selectOne("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", ['sysforest:evidence:'.strtolower($id)]);
    }

    private function ownerQuery(string $organizationId, ?string $treeId, ?string $inspectionId, array $filters, ?string $maintenanceId): Builder
    {
        $this->requireUuid($organizationId);
        if (count(array_filter([$treeId, $inspectionId, $maintenanceId], fn ($id) => $id !== null)) !== 1) {
            throw new InvalidArgumentException('La consulta de evidencias requiere exactamente un propietario.');
        }
        $this->requireUuid($treeId ?? $inspectionId ?? $maintenanceId);
        $query = $this->queryForTenant($organizationId);
        foreach (['tree_id' => $treeId, 'tree_inspection_id' => $inspectionId, 'maintenance_id' => $maintenanceId] as $column => $id) {
            $id === null ? $query->whereNull($column) : $query->where($column, $id);
        }

        return $this->applyFilters($query, $filters);
    }

    private function queryForTenant(string $organizationId): Builder
    {
        return Evidence::query()->where('organization_id', $organizationId)->with(self::RELATIONS);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (($filters['trashed'] ?? '') === 'only') {
            $query->onlyTrashed();
        } elseif (($filters['trashed'] ?? '') === 'with') {
            $query->withTrashed();
        }
        if (!empty($filters['evidence_type_id'])) {
            $this->requireUuid($filters['evidence_type_id']);
            $query->where('evidence_type_id', $filters['evidence_type_id']);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->whereDate('created_at', $operator, $filters[$field]);
            }
        }
        $sort = in_array($filters['sort'] ?? '', ['created_at', 'captured_at'], true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';

        return $query->orderByRaw($sort.' '.$direction.' NULLS LAST')->orderBy('id', $direction);
    }

    private function persist(Evidence $evidence, array $attributes): Evidence
    {
        $this->requireTransaction();
        $evidence->forceFill($attributes)->save();

        return $this->findForTenant($evidence->organization_id, $evidence->id, false, true);
    }

    private function requireUuid(mixed $id): void
    {
        if (!is_string($id) || !Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Evidence::class);
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La evidencia y su revisión requieren una transacción activa.');
        }
    }
}

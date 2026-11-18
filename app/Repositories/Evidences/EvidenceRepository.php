<?php

declare(strict_types=1);

namespace App\Repositories\Evidences;

use App\Models\Evidences\Evidence;
use App\Support\Identifiers\RecordIdentifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class EvidenceRepository
{
    private const RELATIONS = ['evidenceType', 'uploader', 'updater', 'deleter', 'tree', 'inspection.tree', 'maintenance.tree'];

    public function findForTenant(string $organizationId, string $id, bool $lock = false, bool $withTrashed = false): Evidence
    {
        $this->requireRecordId($organizationId);
        $this->requireRecordId($id);
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

    /** Todas las fotos vigentes de los árboles participantes, sin limitar sus visitas a esta campaña. */
    public function paginateForInventory(
        string $organizationId,
        string $inventoryId,
        int $page = 1,
        int $perPage = 100,
        bool $includeInspections = false,
        bool $includeMaintenances = false,
    ): LengthAwarePaginator {
        $this->requireRecordId($organizationId);
        $this->requireRecordId($inventoryId);
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('La página debe ser válida y contener entre 1 y 100 fotografías.');
        }

        // Las capacidades se resuelven una vez en el Service; cada rama reproduce el alcance de su propietario.
        return Evidence::query()->with('evidenceType')->where('evidences.organization_id', $organizationId)
            ->leftJoin('tree_inspections as photo_inspection', fn (JoinClause $join) => $join
                ->on('photo_inspection.id', '=', 'evidences.tree_inspection_id')
                ->on('photo_inspection.organization_id', '=', 'evidences.organization_id'))
            ->leftJoin('maintenances as photo_maintenance', fn (JoinClause $join) => $join
                ->on('photo_maintenance.id', '=', 'evidences.maintenance_id')
                ->on('photo_maintenance.organization_id', '=', 'evidences.organization_id'))
            ->join('trees as photo_tree', fn (JoinClause $join) => $join
                ->on('photo_tree.id', '=', DB::raw('COALESCE(evidences.tree_id, photo_inspection.tree_id, photo_maintenance.tree_id)'))
                ->on('photo_tree.organization_id', '=', 'evidences.organization_id'))
            ->whereNull('photo_tree.deleted_at')
            ->where(function (Builder $owners) use ($includeInspections, $includeMaintenances): void {
                $owners->whereNotNull('evidences.tree_id');
                if ($includeInspections) {
                    $owners->orWhereNotNull('evidences.tree_inspection_id');
                }
                if ($includeMaintenances) {
                    $owners->orWhere(fn (Builder $maintenance) => $maintenance
                        ->whereNotNull('evidences.maintenance_id')->whereNull('photo_maintenance.deleted_at'));
                }
            })
            ->whereExists(fn (QueryBuilder $participation) => $participation->selectRaw('1')->from('inventory_trees as photo_participation')
                ->whereColumn('photo_participation.organization_id', 'evidences.organization_id')
                ->whereColumn('photo_participation.tree_id', 'photo_tree.id')
                ->where('photo_participation.inventory_id', $inventoryId))
            ->select('evidences.*')->addSelect('photo_tree.id as mobile_tree_id')
            ->orderByDesc('evidences.created_at')->orderByDesc('evidences.external_id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** Portada explícita: árbol o sus visitas autorizadas; nunca mantenimientos ni anuladas. */
    public function wholeTreePhoto(string $organizationId, string $treeId, bool $includeInspections = false): ?Evidence
    {
        $this->requireRecordId($organizationId);
        $this->requireRecordId($treeId);

        return $this->queryForTenant($organizationId)
            ->whereHas('evidenceType', fn (Builder $type) => $type->where('code', 'EVD_WHOLE_TREE'))
            ->whereNull('maintenance_id')
            ->where(function (Builder $owners) use ($organizationId, $treeId, $includeInspections): void {
                $owners->where(fn (Builder $direct) => $direct->where('tree_id', $treeId)->whereNull('tree_inspection_id'));
                if ($includeInspections) {
                    $owners->orWhere(fn (Builder $visit) => $visit->whereNull('tree_id')
                        ->whereHas('inspection', fn (Builder $inspection) => $inspection
                            ->where('organization_id', $organizationId)->where('tree_id', $treeId)));
                }
            })
            ->orderByRaw('COALESCE(captured_at, created_at) DESC NULLS LAST')
            ->orderByDesc('created_at')->orderByDesc('external_id')->first();
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
        $this->requireRecordId($organizationId);
        foreach (array_merge($treeIds, $inspectionIds, $maintenanceIds) as $id) {
            $this->requireRecordId($id);
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
        $this->requireOperationUuid($id);
        DB::selectOne("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", ['sysforest:evidence:'.strtolower($id)]);
    }

    private function ownerQuery(string $organizationId, ?string $treeId, ?string $inspectionId, array $filters, ?string $maintenanceId): Builder
    {
        $this->requireRecordId($organizationId);
        if (count(array_filter([$treeId, $inspectionId, $maintenanceId], fn ($id) => $id !== null)) !== 1) {
            throw new InvalidArgumentException('La consulta de evidencias requiere exactamente un propietario.');
        }
        $this->requireRecordId($treeId ?? $inspectionId ?? $maintenanceId);
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
            $this->requireRecordId($filters['evidence_type_id']);
            $query->where('evidence_type_id', $filters['evidence_type_id']);
        }
        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->where('created_at', '>=', \App\Support\Time\BogotaDateTime::startOfDayUtc($filters['date_from'])->toIso8601String());
        }
        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->where('created_at', '<', \App\Support\Time\BogotaDateTime::afterDayUtc($filters['date_to'])->toIso8601String());
        }
        $sort = in_array($filters['sort'] ?? '', ['created_at', 'captured_at'], true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';

        return $query->orderByRaw($sort.' '.$direction.' NULLS LAST')->orderBy('external_id', $direction);
    }

    private function persist(Evidence $evidence, array $attributes): Evidence
    {
        $this->requireTransaction();
        $evidence->forceFill($attributes)->save();

        return $this->findForTenant($evidence->organization_id, $evidence->id, false, true);
    }

    private function requireRecordId(mixed $id): void
    {
        if (!RecordIdentifier::isValid($id)) {
            throw (new ModelNotFoundException())->setModel(Evidence::class);
        }
    }

    private function requireOperationUuid(mixed $id): void
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

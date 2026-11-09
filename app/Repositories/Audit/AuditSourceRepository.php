<?php

declare(strict_types=1);

namespace App\Repositories\Audit;

use App\DTOs\Audit\AuditScope;
use App\Auditing\AuditCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Fuente transaccional recuperable de eventos y revisiones; mantiene la deduplicación histórica. */
class AuditSourceRepository
{
    public const SOURCES = ['events', 'inspections', 'evidences', 'maintenances', 'publications', 'statuses', 'locations'];

    public function paginate(AuditScope $scope, array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($scope, $filters);

        return $query->orderByDesc('a.occurred_at')->orderBy('a.source')->orderByDesc('a.id')
            ->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    private function filtered(AuditScope $scope, array $filters): Builder
    {
        $query = $this->query($scope);
        $query->where('a.occurred_at', '>=', \App\Support\Time\BogotaDateTime::startOfDayUtc($filters['date_from'])->toIso8601String())
            ->where('a.occurred_at', '<', \App\Support\Time\BogotaDateTime::afterDayUtc($filters['date_to'])->toIso8601String());
        foreach (['organization_id', 'event', 'resource_type', 'resource_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where('a.'.$key, $filters[$key]);
            }
        }
        if (isset($filters['actor'])) {
            // El filtro es literal: % y _ no amplían la búsqueda escrita por el usuario.
            $query->whereRaw("u.name ILIKE ? ESCAPE '!'", ['%'.strtr($filters['actor'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%']);
        }

        if (isset($filters['user_id'])) {
            $query->where('a.actor_user_id', $filters['user_id']);
        }
        if (isset($filters['module'])) {
            $column = DB::raw("split_part(a.event, '.', 1)");
            if ($filters['module'] === 'other') {
                $query->whereNotIn($column, AuditCatalog::knownPrefixes());
            } else {
                $query->whereIn($column, AuditCatalog::prefixes($filters['module']));
            }
        }

        return $query;
    }

    public function find(AuditScope $scope, string $source, string $id): ?object
    {
        return $this->query($scope)->where('a.source', $source)->where('a.id', $id)->first();
    }

    /** Los controles sólo enumeran organizaciones, usuarios y acciones del alcance autorizado. */
    public function organizations(AuditScope $scope): Collection
    {
        $query = DB::table('organizations');
        if ($scope->kind === 'department') {
            $query->whereIn('id', $scope->organizationIds);
        } elseif ($scope->kind === 'organization') {
            $query->where('id', $scope->organizationId);
        } elseif ($scope->kind !== 'national') {
            throw new \InvalidArgumentException('El alcance de auditoría no es válido.');
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    public function options(AuditScope $scope): array
    {
        return [
            'users' => $this->query($scope)->whereNotNull('u.id')->select('u.id', 'u.name')->distinct()->orderBy('u.name')->orderBy('u.id')->get(),
            'events' => $this->query($scope)->select('a.event')->distinct()->orderBy('a.event')->pluck('event'),
        ];
    }

    public function exportCount(AuditScope $scope, array $filters): int
    {
        return $this->filtered($scope, $filters)->count();
    }

    /** El servicio mantiene una instantánea SQL para contar y recorrer exactamente el mismo conjunto. */
    public function exportRows(AuditScope $scope, array $filters): iterable
    {
        return $this->filtered($scope, $filters)->orderByDesc('a.occurred_at')->orderBy('a.source')->orderByDesc('a.id')->cursor();
    }

    /** Reintenta todo registro sin recibo, incluidos commits tardíos con fechas o UUID anteriores. */
    public function pending(int $limit): Collection
    {
        return $this->ordered($this->timeline()->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('audit_deliveries as d')
                ->whereColumn('d.source', 'a.source')->whereColumn('d.entry_id', 'a.id');
        }))->limit($this->batchLimit($limit))->get();
    }

    /** Recuperación explícita de Mongo: recorre también los eventos que ya tienen recibo. */
    public function replayBatch(int $limit, ?array $cursor = null): Collection
    {
        $query = $this->timeline();
        if ($cursor !== null) {
            $query->where(function (Builder $next) use ($cursor): void {
                $next->where('a.occurred_at', '>', $cursor['occurred_at'])
                    ->orWhere(function (Builder $sameTime) use ($cursor): void {
                        $sameTime->where('a.occurred_at', $cursor['occurred_at'])
                            ->where(function (Builder $nextSource) use ($cursor): void {
                                $nextSource->where('a.source', '>', $cursor['source'])
                                    ->orWhere(function (Builder $sameSource) use ($cursor): void {
                                        $sameSource->where('a.source', $cursor['source'])->where('a.id', '>', $cursor['id']);
                                    });
                            });
                    });
            });
        }

        return $this->ordered($query)->limit($this->batchLimit($limit))->get();
    }

    public function hasPending(): bool
    {
        return $this->timeline()->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('audit_deliveries as d')
                ->whereColumn('d.source', 'a.source')->whereColumn('d.entry_id', 'a.id');
        })->exists();
    }

    public function unsyncedCount(): int
    {
        return $this->timeline()->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('audit_deliveries as d')
                ->whereColumn('d.source', 'a.source')->whereColumn('d.entry_id', 'a.id');
        })->count();
    }

    public function totalCount(): int
    {
        return $this->timeline()->count();
    }

    private function ordered(Builder $query): Builder
    {
        return $query->orderBy('a.occurred_at')->orderBy('a.source')->orderBy('a.id');
    }

    private function batchLimit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }

    private function query(AuditScope $scope): Builder
    {
        $query = $this->timeline()
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
            ->select('a.*', 'u.name as actor_name', 'o.name as organization_name');
        if ($scope->kind === 'organization') {
            $query->where('a.organization_id', $scope->organizationId);
        } elseif ($scope->kind === 'department') {
            $query->whereIn('a.organization_id', $scope->organizationIds);
        } elseif ($scope->kind !== 'national') {
            throw new \InvalidArgumentException('El alcance de auditoría no es válido.');
        }

        return $query;
    }

    private function timeline(): Builder
    {
        $union = DB::table('security_events')->selectRaw("id AS id, 'events'::text AS source, organization_id, actor_user_id,
            created_at AS occurred_at, event, COALESCE(resource_type, 'security_event') AS resource_type,
            resource_id, before_values, after_values, metadata, context, NULL::integer AS version");
        foreach (['inspections' => ['inspection_revisions', 'inspection_id', 'tree_inspection'],
            'evidences' => ['evidence_revisions', 'evidence_id', 'evidence'],
            'maintenances' => ['maintenance_revisions', 'maintenance_id', 'maintenance']] as $source => [$table, $subject, $type]) {
            $union->unionAll($this->legacy($table, "'{$type}'", "r.{$subject}::text", 'r.event')->selectRaw("r.id, '{$source}'::text, r.organization_id, r.changed_by,
                r.changed_at, r.event, '{$type}'::text, r.{$subject}::text, r.old_values, r.new_values,
                '{}'::jsonb, '{}'::jsonb, r.version"));
        }
        $union->unionAll($this->legacy(
            'publication_revisions',
            "CASE WHEN r.organization_subject_id IS NOT NULL THEN 'organization' WHEN r.inventory_id IS NOT NULL THEN 'inventory' WHEN r.tree_id IS NOT NULL THEN 'tree' WHEN r.tree_inspection_id IS NOT NULL THEN 'tree_inspection' ELSE 'evidence' END",
            'COALESCE(r.organization_subject_id, r.inventory_id, r.tree_id, r.tree_inspection_id, r.evidence_id)::text',
            "'publication.changed'"
        )->selectRaw("r.id, 'publications'::text, r.organization_id, r.changed_by,
            r.changed_at, 'publication.changed'::text,
            CASE WHEN r.organization_subject_id IS NOT NULL THEN 'organization' WHEN r.inventory_id IS NOT NULL THEN 'inventory'
                WHEN r.tree_id IS NOT NULL THEN 'tree' WHEN r.tree_inspection_id IS NOT NULL THEN 'tree_inspection' ELSE 'evidence' END,
            COALESCE(r.organization_subject_id, r.inventory_id, r.tree_id, r.tree_inspection_id, r.evidence_id)::text,
            r.old_values, r.new_values, '{}'::jsonb, '{}'::jsonb, r.version"));
        $union->unionAll($this->legacy('tree_status_histories', "'tree'", 'r.tree_id::text', "'tree.status_changed'")->selectRaw("r.id, 'statuses'::text, r.organization_id, r.changed_by,
            r.changed_at, 'tree.status_changed'::text, 'tree'::text, r.tree_id::text,
            jsonb_build_object('current_status', r.from_status), jsonb_build_object('current_status', r.to_status),
            jsonb_build_object('reason', r.reason), '{}'::jsonb, NULL::integer"));
        $union->unionAll($this->legacy('tree_location_histories', "'tree'", 'r.tree_id::text', "'tree.location_changed'")->selectRaw("r.id, 'locations'::text, r.organization_id, r.changed_by,
            r.changed_at, 'tree.location_changed'::text, 'tree'::text, r.tree_id::text,
            jsonb_build_object('coordinate_raw', r.old_coordinate_raw, 'location', ST_AsGeoJSON(r.old_geometry)::jsonb, 'location_metadata', r.old_metadata),
            jsonb_build_object('coordinate_raw', r.new_coordinate_raw, 'location', ST_AsGeoJSON(r.new_geometry)::jsonb, 'location_metadata', r.new_metadata),
            jsonb_build_object('reason', r.reason, 'change_type', r.change_type), '{}'::jsonb, NULL::integer"));
        return DB::query()->fromSub($union, 'a')->select('a.*');
    }

    private function legacy(string $table, string $type, string $subject, string $event): Builder
    {
        // Únicamente una referencia explícita sustituye la revisión por el evento nuevo.
        // Dos acciones simultáneas nunca se fusionan por coincidencia de fecha o actor.
        return DB::table($table.' as r')->whereNotExists(function (Builder $query) use ($type, $subject, $event): void {
            $query->selectRaw('1')->from('security_events as e')
                ->whereColumn('e.organization_id', 'r.organization_id')
                ->whereRaw("e.metadata->>'revision_id' = r.id::text")
                ->whereNotNull('e.before_values')
                ->whereRaw("e.resource_type = {$type} AND e.resource_id = {$subject} AND e.event = {$event}");
        });
    }
}

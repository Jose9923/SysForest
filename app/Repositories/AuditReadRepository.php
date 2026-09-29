<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\AuditScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Unifica eventos y revisiones previas sin alterar ni reconstruir el historial persistido. */
class AuditReadRepository
{
    public const SOURCES = ['events', 'inspections', 'evidences', 'maintenances', 'publications', 'statuses', 'locations'];

    public function paginate(AuditScope $scope, array $filters): LengthAwarePaginator
    {
        $query = $this->query($scope);
        $query->where('a.occurred_at', '>=', $filters['date_from'].' 00:00:00+00')
            ->where('a.occurred_at', '<', CarbonImmutable::parse($filters['date_to'], 'UTC')->addDay()->toDateString().' 00:00:00+00');
        foreach (['organization_id', 'event', 'resource_type', 'resource_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where('a.'.$key, $filters[$key]);
            }
        }
        if (isset($filters['actor'])) {
            // El filtro es literal: % y _ no amplían la búsqueda escrita por el usuario.
            $query->whereRaw("u.name ILIKE ? ESCAPE '!'", ['%'.strtr($filters['actor'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%']);
        }

        return $query->orderByDesc('a.occurred_at')->orderBy('a.source')->orderByDesc('a.id')
            ->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(AuditScope $scope, string $source, string $id): ?object
    {
        return $this->query($scope)->where('a.source', $source)->where('a.id', $id)->first();
    }

    /** Incluye instituciones desactivadas: la desactivación no elimina su trazabilidad nacional. */
    public function organizations(): Collection
    {
        return DB::table('organizations')->orderBy('name')->get(['id', 'name']);
    }

    private function query(AuditScope $scope): Builder
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
        $query = DB::query()->fromSub($union, 'a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
            ->select('a.*', 'u.name as actor_name', 'o.name as organization_name');
        if ($scope->kind === 'organization') {
            $query->where('a.organization_id', $scope->organizationId);
        }

        return $query;
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

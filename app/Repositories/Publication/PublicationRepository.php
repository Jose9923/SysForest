<?php

declare(strict_types=1);

namespace App\Repositories\Publication;

use App\Models\Evidences\Evidence;
use App\Models\Inventories\Inventory;
use App\Models\Maintenances\Maintenance;
use App\Models\Organizations\Organization;
use App\Models\Publication\PublicationRevision;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Consultas explícitas; ningún nombre de tabla o columna procede del navegador. */
class PublicationRepository
{
    public function inventory(string $organizationId, string $id, bool $lock = false): Inventory
    {
        return $this->find(Inventory::query(), $organizationId, $id, $lock);
    }

    public function tree(string $organizationId, string $id, bool $lock = false): Tree
    {
        return $this->find(Tree::withTrashed(), $organizationId, $id, $lock);
    }

    public function inspection(string $organizationId, string $id, bool $lock = false): TreeInspection
    {
        return $this->find(TreeInspection::query()->with('tree'), $organizationId, $id, $lock);
    }

    public function maintenance(string $organizationId, string $id, bool $lock = false): Maintenance
    {
        return $this->find(Maintenance::withTrashed()->with('tree'), $organizationId, $id, $lock);
    }

    public function evidence(string $organizationId, string $id, bool $lock = false): Evidence
    {
        return $this->find(
            Evidence::withTrashed()->with(['tree', 'inspection.tree', 'maintenance.tree', 'evidenceType']),
            $organizationId,
            $id,
            $lock
        );
    }

    public function inventories(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Inventory::query()->where('organization_id', $organizationId);
        if (!empty($filters['q'])) {
            $term = $this->term($filters['q']);
            $query->where(fn (Builder $q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        return $this->page($query, $filters);
    }

    public function trees(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Tree::withTrashed()->where('organization_id', $organizationId);
        if (!empty($filters['q'])) {
            $query->where('code', 'ilike', $this->term($filters['q']));
        }

        return $this->page($query, $filters);
    }

    public function inspections(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = TreeInspection::query()->where('organization_id', $organizationId)->with('tree');
        if (!empty($filters['q'])) {
            $query->whereHas('tree', fn (Builder $tree) => $tree->where('code', 'ilike', $this->term($filters['q'])));
        }

        return $this->page($query, $filters);
    }

    public function evidences(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Evidence::withTrashed()->where('organization_id', $organizationId)
            ->with(['tree', 'inspection.tree', 'maintenance.tree', 'evidenceType']);
        if (!empty($filters['q'])) {
            $term = $this->term($filters['q']);
            $query->where(fn (Builder $owners) => $owners
                ->whereHas('tree', fn (Builder $tree) => $tree->where('code', 'ilike', $term))
                ->orWhereHas('inspection.tree', fn (Builder $tree) => $tree->where('code', 'ilike', $term))
                ->orWhereHas('maintenance.tree', fn (Builder $tree) => $tree->where('code', 'ilike', $term)));
        }

        return $this->page($query, $filters);
    }

    /** El Service ya bloqueó actor, organización, raíz y sujeto en ese orden. */
    public function persist(Organization|Inventory|Tree|TreeInspection|Evidence $record, array $attributes): Model
    {
        $this->transaction();
        $allowed = ['is_public', 'publication_version'];
        if ($record instanceof Organization) {
            $allowed[] = 'public_code';
        } else {
            $allowed = array_merge($allowed, ['lock_version', 'updated_by']);
        }
        if ($record instanceof Evidence) {
            $allowed = array_merge($allowed, ['public_id', 'public_caption']);
        }
        $record->forceFill(array_intersect_key($attributes, array_flip($allowed)))->save();

        return $record->refresh();
    }

    public function recordRevision(Organization|Inventory|Tree|TreeInspection|Evidence $record, int $actorId, array $old, array $new): PublicationRevision
    {
        $this->transaction();
        $column = match (true) {
            $record instanceof Organization => 'organization_subject_id',
            $record instanceof Inventory => 'inventory_id',
            $record instanceof Tree => 'tree_id',
            $record instanceof TreeInspection => 'tree_inspection_id',
            $record instanceof Evidence => 'evidence_id',
        };
        $revision = new PublicationRevision();
        $revision->forceFill([
            'organization_id' => $record instanceof Organization ? $record->id : $record->organization_id,
            $column => $record->id, 'version' => $record->publication_version,
            'changed_by' => $actorId, 'changed_at' => now(),
            'old_values' => (object) $old, 'new_values' => (object) $new,
        ])->save();

        return $revision;
    }

    public function revisions(string $organizationId): LengthAwarePaginator
    {
        return PublicationRevision::query()->where('organization_id', $organizationId)->with('changedBy')
            ->orderByDesc('changed_at')->orderByDesc('id')->paginate(20, ['*'], 'revisions_page');
    }

    private function find(Builder $query, string $organizationId, string $id, bool $lock): Model
    {
        if (!\App\Support\Identifiers\RecordIdentifier::isValid($organizationId) || !\App\Support\Identifiers\RecordIdentifier::isValid($id)) {
            throw new ModelNotFoundException();
        }
        $query->where('organization_id', $organizationId)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function page(Builder $query, array $filters): LengthAwarePaginator
    {
        if (isset($filters['is_public'])) {
            $query->where('is_public', (bool) $filters['is_public']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();
    }

    private function term(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La publicación y su auditoría requieren una transacción.');
        }
    }
}

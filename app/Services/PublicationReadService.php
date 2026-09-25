<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evidence;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\PublicationRevision;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\PublicationRepository;
use App\Validation\PublicationInput;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

class PublicationReadService
{
    public const KINDS = ['organizations' => 'Organización', 'inventories' => 'Inventarios',
        'trees' => 'Árboles', 'inspections' => 'Inspecciones', 'evidences' => 'Fotografías'];

    public function __construct(private PublicationAccessService $access, private PublicationRepository $records)
    {
    }

    public function page(User $actor, array $input): array
    {
        $organization = $this->access->organization($actor);
        foreach (['q', 'is_public', 'kind'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) === '') {
                $input[$key] = null;
            }
        }
        $filters = Validator::make($input, PublicationInput::filters(), PublicationInput::messages())->validate();
        $kind = $filters['kind'] ?? 'organizations';
        $records = match ($kind) {
            'inventories' => $this->records->inventories($organization->id, $filters),
            'trees' => $this->records->trees($organization->id, $filters),
            'inspections' => $this->records->inspections($organization->id, $filters),
            'evidences' => $this->records->evidences($organization->id, $filters),
            default => new LengthAwarePaginator(collect([$organization]), 1, 20, 1, ['path' => route('publication.index')]),
        };
        $records->setCollection($records->getCollection()->map(fn ($record) => $this->entry($record)));
        $revisions = $this->records->revisions($organization->id);
        $revisions->setCollection($revisions->getCollection()->map(fn (PublicationRevision $entry) => [
            'kind' => match (true) {
                $entry->organization_subject_id !== null => 'Organización',
                $entry->inventory_id !== null => 'Inventario',
                $entry->tree_id !== null => 'Árbol',
                $entry->tree_inspection_id !== null => 'Inspección',
                default => 'Fotografía',
            },
            'version' => $entry->version, 'date' => $entry->changed_at->utc()->format('Y-m-d H:i:s').' UTC',
            'actor' => $entry->changedBy->name, 'before' => $entry->old_values, 'after' => $entry->new_values,
        ]));
        $revisions->appends($filters);

        return ['organization' => $organization, 'kind' => $kind, 'kinds' => self::KINDS,
            'filters' => $filters, 'records' => $records, 'revisions' => $revisions];
    }

    private function entry(Organization|Inventory|Tree|TreeInspection|Evidence $record): array
    {
        $kind = match (true) {
            $record instanceof Organization => 'organizations', $record instanceof Inventory => 'inventories',
            $record instanceof Tree => 'trees', $record instanceof TreeInspection => 'inspections', default => 'evidences',
        };
        $title = match (true) {
            $record instanceof Organization => $record->name,
            $record instanceof Inventory => $record->code.' · '.$record->name,
            $record instanceof Tree => $record->code,
            $record instanceof TreeInspection => $record->tree->code.' · '.$record->inspection_date->format('Y-m-d'),
            default => $record->evidenceType->name.' · '.($record->tree?->code ?? $record->inspection?->tree?->code ?? $record->maintenance?->tree?->code),
        };
        $detail = match (true) {
            $record instanceof Organization => 'Código público: '.($record->public_code ?? 'se reservará al publicar'),
            $record instanceof Inventory => $record->status->label().($record->active ? '' : ' · Inactivo'),
            $record instanceof Tree => $record->current_status->label().($record->trashed() ? ' · Registro anulado' : ''),
            $record instanceof TreeInspection => 'Solo datos forestales estructurados; los textos libres permanecen privados.',
            default => $record->trashed() ? 'Fotografía anulada: no se entrega públicamente.' : 'El original y su descripción interna permanecen privados.',
        };

        return ['id' => $record->id, 'kind' => $kind, 'title' => $title, 'detail' => $detail,
            'is_public' => $record->is_public, 'publication_version' => $record->publication_version,
            'public_caption' => $record instanceof Evidence ? $record->public_caption : null,
            'action' => $record instanceof Organization ? route('publication.organizations.update')
                : route('publication.'.$kind.'.update', $record->id)];
    }
}

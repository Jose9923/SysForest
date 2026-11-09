<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Models\Evidences\Evidence;
use App\Models\Maintenances\Maintenance;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Evidences\EvidenceRepository;
use App\Repositories\Evidences\EvidenceRevisionRepository;
use App\Repositories\Catalogs\EvidenceTypeRepository;
use App\Validation\Evidences\EvidenceInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Forestry\ForestryAccessService;

/** Proyección privada explícita; nunca serializa automáticamente un Model hacia el navegador. */
class EvidenceReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private EvidenceOwnerService $owners,
        private EvidenceRepository $evidences,
        private EvidenceTypeRepository $types,
        private EvidenceRevisionRepository $revisions
    ) {
    }

    public function authorizeUpload(User $actor, ?string $treeId, ?string $inspectionId, ?string $maintenanceId = null): bool
    {
        $owner = $this->owners->resolve($actor, $treeId, $inspectionId, false, $maintenanceId);
        Gate::forUser($actor)->authorize('create', [Evidence::class, $owner]);

        return true;
    }

    public function authorizeExisting(User $actor, string $id, string $ability = 'view'): Evidence
    {
        $organization = $this->access->organization($actor);
        $evidence = $this->evidences->findForTenant($organization->id, $id, false, $this->access->allows($actor, 'evidences.restore'));
        Gate::forUser($actor)->authorize($ability, $evidence);

        return $evidence;
    }

    public function wholeTreePhoto(User $actor, Tree $tree): ?array
    {
        if (!Gate::forUser($actor)->allows('view', $tree) || !Gate::forUser($actor)->allows('viewAny', Evidence::class)) {
            return null;
        }
        $organization = $this->access->organization($actor);
        abort_unless($organization->id === $tree->organization_id, 403);
        $photo = $this->evidences->wholeTreePhoto($organization->id, $tree->id, $this->access->allows($actor, 'inspections.view'));
        if ($photo === null || !Gate::forUser($actor)->allows('view', $photo)) {
            return null;
        }

        return $this->item($photo, []) + ['sourceLabel' => $photo->tree_inspection_id ? 'Fotografía de una inspección' : 'Fotografía del árbol'];
    }

    public function uploadForm(User $actor, ?string $treeId, ?string $inspectionId, ?string $maintenanceId = null): array
    {
        $owner = $this->owners->resolve($actor, $treeId, $inspectionId, false, $maintenanceId);
        Gate::forUser($actor)->authorize('create', [Evidence::class, $owner]);

        return ['organization' => $this->access->organization($actor), 'ownerLabel' => $this->label($owner),
            'ownerUrl' => $this->owners->url($owner), 'storeUrl' => route($this->routePrefix($owner).'.store', $owner->id),
            'evidenceTypes' => $this->types->options(), 'title' => 'Adjuntar fotografías',
            'limits' => ['maxSizeMb' => config('evidence.max_size_mb'), 'maxSizeBytes' => config('evidence.max_size_mb') * 1048576,
                'maxFiles' => config('evidence.max_files'), 'allowedMimes' => config('evidence.allowed_mimes')]];
    }

    public function index(User $actor, ?string $treeId, ?string $inspectionId, array $input = [], ?string $maintenanceId = null): array
    {
        $owner = $this->owners->resolve($actor, $treeId, $inspectionId, false, $maintenanceId);
        Gate::forUser($actor)->authorize('viewAny', Evidence::class);
        $input = array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $input);
        $filters = Validator::make($input, EvidenceInput::filters(), EvidenceInput::messages())->validate();
        if (!empty($filters['trashed'])) {
            $this->access->authorize($actor, 'evidences.restore');
        }
        if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final no puede preceder a la inicial.']);
        }
        $records = $this->evidences->paginateForOwner($owner->organization_id, $treeId, $inspectionId, $filters, $maintenanceId);
        $gallery = $this->projectGallery($actor, $owner, $records);
        $gallery['pagination'] = $records;

        return ['organization' => $this->access->organization($actor), 'gallery' => $gallery, 'filters' => $filters,
            'typeOptions' => $this->types->optionsForFilter(), 'trashedAllowed' => $this->access->allows($actor, 'evidences.restore')];
    }

    public function gallery(User $actor, Tree|TreeInspection|Maintenance $owner): ?array
    {
        if (!Gate::forUser($actor)->allows('view', $owner) || !Gate::forUser($actor)->allows('viewAny', Evidence::class)) {
            return null;
        }
        $this->access->organization($actor);
        $records = $this->evidences->summaryForOwner(
            $owner->organization_id,
            $owner instanceof Tree ? $owner->id : null,
            $owner instanceof TreeInspection ? $owner->id : null,
            6,
            $owner instanceof Maintenance ? $owner->id : null
        );

        return $this->projectGallery($actor, $owner, $records);
    }

    public function show(User $actor, string $id): array
    {
        $evidence = $this->authorizeExisting($actor, $id);
        $owner = $evidence->ownerRecord();
        $events = ['evidence.uploaded' => 'Fotografía adjuntada', 'evidence.updated' => 'Metadata corregida',
            'evidence.voided' => 'Evidencia anulada', 'evidence.restored' => 'Evidencia restaurada'];
        $revisions = $this->revisions->forEvidence($evidence->organization_id, $evidence->id)->map(fn ($revision) => [
            'eventLabel' => $events[$revision->event] ?? 'Cambio de evidencia',
            'changedAt' => \App\Support\Time\BogotaDateTime::format($revision->changed_at, 'Y-m-d H:i:s').' (Bogotá)',
            'changedByName' => $revision->changedBy?->name ?? 'Cuenta no disponible',
            'summary' => 'Versión '.$revision->version,
        ]);

        return ['organization' => $this->access->organization($actor), 'evidenceItem' => $this->item($evidence, $this->abilities($actor, $evidence)),
            'typeOptions' => $this->types->options($evidence), 'ownerUrl' => $this->owners->url($owner), 'ownerLabel' => $this->label($owner),
            'updateUrl' => route('evidences.update', $id), 'voidUrl' => route('evidences.void', $id),
            'restoreUrl' => route('evidences.restore', $id), 'revisions' => $revisions, 'lockVersion' => $evidence->lock_version];
    }

    public function ownerUrl(Evidence $evidence): string
    {
        return $this->owners->url($evidence->ownerRecord());
    }

    private function projectGallery(User $actor, Tree|TreeInspection|Maintenance $owner, $records): array
    {
        $first = $records->first();
        // Toda la página comparte propietario y estado de anulación; resolver permisos una vez evita N+1.
        $abilities = $first ? $this->abilities($actor, $first) : [];
        $canUpload = Gate::forUser($actor)->allows('create', [Evidence::class, $owner]);

        return ['domId' => 'evidence-gallery-'.$owner->id, 'title' => match (true) {
            $owner instanceof Tree => 'Fotografías del árbol',
            $owner instanceof TreeInspection => 'Fotografías de la inspección',
            default => 'Fotografías del mantenimiento',
        },
            'description' => match (true) {
                $owner instanceof Tree => 'Evidencias permanentes del individuo.',
                $owner instanceof TreeInspection => 'Evidencias de esta visita, incluidas fauna e individuo completo.',
                default => 'Evidencias de esta orden, incluidas fotografías antes y después de la intervención.',
            },
            'ownerLabel' => $this->label($owner), 'ownerUrl' => $this->owners->url($owner),
            'items' => collect($records->items())->map(fn (Evidence $evidence) => $this->item($evidence, $abilities)), 'total' => $records->total(),
            'indexUrl' => route($this->routePrefix($owner).'.index', $owner->id),
            'uploadUrl' => $canUpload ? route($this->routePrefix($owner).'.create', $owner->id) : null,
            'canUpload' => $canUpload, 'canViewVoided' => $this->access->allows($actor, 'evidences.restore')];
    }

    private function abilities(User $actor, Evidence $evidence): array
    {
        return ['canUpdate' => Gate::forUser($actor)->allows('update', $evidence),
            'canVoid' => Gate::forUser($actor)->allows('delete', $evidence),
            'canRestore' => Gate::forUser($actor)->allows('restore', $evidence)];
    }

    private function item(Evidence $evidence, array $abilities): array
    {
        return $abilities + ['id' => $evidence->id, 'typeId' => $evidence->evidence_type_id,
            'typeName' => $evidence->evidenceType->name, 'typeActive' => $evidence->evidenceType->active,
            'caption' => $evidence->caption, 'notes' => $evidence->notes, 'originalName' => $evidence->original_name,
            'capturedAt' => $evidence->captured_at ? \App\Support\Time\BogotaDateTime::format($evidence->captured_at, 'Y-m-d H:i').' (Bogotá)' : null,
            'capturedAtInput' => \App\Support\Time\BogotaDateTime::format($evidence->captured_at, 'Y-m-d\TH:i'),
            'createdAt' => \App\Support\Time\BogotaDateTime::format($evidence->created_at, 'Y-m-d H:i').' (Bogotá)',
            'updatedAt' => \App\Support\Time\BogotaDateTime::format($evidence->updated_at, 'Y-m-d H:i').' (Bogotá)',
            'uploadedByName' => $evidence->uploader?->name ?? 'Cuenta no disponible',
            'updatedByName' => $evidence->updater?->name ?? 'Cuenta no disponible',
            'viewUrl' => route('evidences.view', $evidence->id), 'downloadUrl' => route('evidences.download', $evidence->id),
            'showUrl' => route('evidences.show', $evidence->id), 'isVoided' => $evidence->trashed(),
            'deletedAt' => \App\Support\Time\BogotaDateTime::format($evidence->deleted_at, 'Y-m-d H:i'), 'deletedByName' => $evidence->deleter?->name,
            'deletionNotes' => $evidence->deletion_notes, 'mimeType' => $evidence->mime_type,
            'sizeLabel' => number_format($evidence->size_bytes / 1048576, 2, ',', '.').' MiB',
            'dimensions' => $evidence->width !== null ? $evidence->width.' × '.$evidence->height.' px' : null,
            'checksum' => $evidence->checksum_sha256];
    }

    private function label(Tree|TreeInspection|Maintenance $owner): string
    {
        return match (true) {
            $owner instanceof Tree => 'Árbol '.$owner->code,
            $owner instanceof TreeInspection => 'Inspección '.$owner->inspection_date->format('Y-m-d').' · '.$owner->tree->code,
            default => 'Mantenimiento '.$owner->code.' · '.$owner->tree->code,
        };
    }

    private function routePrefix(Tree|TreeInspection|Maintenance $owner): string
    {
        return match (true) {
            $owner instanceof Tree => 'trees.evidences',
            $owner instanceof TreeInspection => 'inspections.evidences',
            default => 'maintenances.evidences',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Models\Evidences\Evidence;
use App\Models\Maintenances\Maintenance;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Evidences\EvidenceRepository;
use App\Repositories\Catalogs\EvidenceTypeRepository;
use App\Repositories\Users\UserRepository;
use App\Validation\Evidences\EvidenceInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Lote todo o nada: valida todas las imágenes antes de escribir y conserva intentos recuperables. */
class EvidenceService
{
    public function __construct(
        private UserRepository $users,
        private EvidenceOwnerService $owners,
        private EvidenceRepository $evidences,
        private EvidenceTypeRepository $types,
        private ImageMetadataService $images,
        private EvidenceStorageService $storage,
        private EvidenceRecoveryService $recovery,
        private EvidenceAuditService $audit,
        private EvidenceWriteService $writes
    ) {
    }

    public function upload(User $actor, ?string $treeId, ?string $inspectionId, array $input, ?string $maintenanceId = null): Collection
    {
        $owner = $this->owners->resolve($actor, $treeId, $inspectionId, false, $maintenanceId);
        Gate::forUser($actor)->authorize('create', [Evidence::class, $owner]);
        $data = Validator::make($input, EvidenceInput::storeRules(), EvidenceInput::messages())->validate();
        $technical = [];
        foreach ($data['items'] as $index => $item) {
            $technical[$index] = $this->images->inspect($item['file'], 'items.'.$index.'.file');
        }
        if (array_sum(array_column($technical, 'size_bytes')) > (int) config('evidence.max_batch_size_mb') * 1048576) {
            throw ValidationException::withMessages(['items' => 'El lote supera el tamaño máximo permitido.']);
        }
        $operationIds = [];
        try {
            // No se reintenta automáticamente una transacción que haya escrito archivos.
            return DB::transaction(function () use ($actor, $treeId, $inspectionId, $data, $technical, &$operationIds, $maintenanceId): Collection {
                $actor = $this->users->findForUpdate((int) $actor->id);
                $owner = $this->owners->resolve($actor, $treeId, $inspectionId, true, $maintenanceId);
                Gate::forUser($actor)->authorize('create', [Evidence::class, $owner]);
                foreach ($data['items'] as $index => $item) {
                    $this->validateType($item['evidence_type_id'], null, 'items.'.$index.'.evidence_type_id');
                }
                $records = collect();
                $references = [];
                foreach ($data['items'] as $index => $item) {
                    $id = (string) Str::uuid();
                    $operationIds[] = $id;
                    $this->evidences->lockStorageOperation($id);
                    $reference = $this->storage->store($item['file'], $owner->organization_id, $technical[$index], $id);
                    $references[] = $reference;
                    $evidence = $this->evidences->create($technical[$index] + $this->metadata($item) + [
                        'id' => $id, 'organization_id' => $owner->organization_id,
                        'tree_id' => $owner instanceof Tree ? $owner->id : null,
                        'tree_inspection_id' => $owner instanceof TreeInspection ? $owner->id : null,
                        'maintenance_id' => $owner instanceof Maintenance ? $owner->id : null,
                        'disk' => $reference['disk'], 'path' => $reference['path'],
                        'uploaded_by' => $actor->id, 'updated_by' => $actor->id, 'lock_version' => 1,
                    ]);
                    $this->audit->record($actor, $evidence, [], 'evidence.uploaded');
                    $records->push($this->evidences->findForTenant($owner->organization_id, $evidence->id));
                }
                DB::afterCommit(fn () => $this->recovery->acknowledge($references));

                return $records;
            });
        } catch (Throwable $exception) {
            $this->recovery->compensate($operationIds);
            if ($exception instanceof ValidationException || $exception instanceof \Illuminate\Auth\Access\AuthorizationException
                || $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $exception;
            }
            throw new HttpException(503, 'No fue posible completar la carga. Consulta la galería antes de volver a intentarlo.', $exception);
        }
    }

    public function update(User $actor, string $id, array $input): Evidence
    {
        return $this->writes->run($actor, $id, 'update', $input, function (Evidence $evidence, User $actor, array $version) use ($input): void {
            $current = ['evidence_type_id' => $evidence->evidence_type_id, 'caption' => $evidence->caption,
                'notes' => $evidence->notes, 'captured_at' => $evidence->captured_at?->utc()->format('Y-m-d\TH:i')];
            $data = Validator::make(array_replace($current, $input), EvidenceInput::updateRules(), EvidenceInput::messages())->validate();
            $this->validateType($data['evidence_type_id'], $evidence->evidence_type_id, 'evidence_type_id');
            $this->evidences->update($evidence, $this->metadata($data) + $version);
        }, 'evidence.updated');
    }

    public function void(User $actor, string $id, array $input): Evidence
    {
        return $this->writes->run($actor, $id, 'delete', $input, function (Evidence $evidence, User $actor, array $version) use ($input): void {
            $data = Validator::make($input, EvidenceInput::lifecycleRules(), EvidenceInput::messages())->validate();
            $this->evidences->void($evidence, $version + ['deleted_by' => $actor->id, 'deletion_notes' => $data['deletion_notes'] ?? null]);
        }, 'evidence.voided');
    }

    public function restore(User $actor, string $id, array $input): Evidence
    {
        return $this->writes->run($actor, $id, 'restore', $input, function (Evidence $evidence, User $actor, array $version) use ($input): void {
            Validator::make($input, EvidenceInput::lifecycleRules(), EvidenceInput::messages())->validate();
            if (!$this->storage->exists($evidence)) {
                throw ValidationException::withMessages(['file' => 'No se puede restaurar la evidencia porque su archivo no está disponible.']);
            }
            $this->evidences->restore($evidence, $version);
        }, 'evidence.restored');
    }

    private function validateType(string $id, ?string $current, string $errorKey): void
    {
        if (!$this->types->validate($id, $current, true)) {
            throw ValidationException::withMessages([$errorKey => 'Selecciona un tipo de evidencia activo.']);
        }
    }

    private function metadata(array $item): array
    {
        return ['evidence_type_id' => $item['evidence_type_id'], 'caption' => $item['caption'] ?? null,
            'notes' => $item['notes'] ?? null, 'captured_at' => trim((string) ($item['captured_at'] ?? '')) !== ''
                ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $item['captured_at'], 'UTC') : null];
    }
}

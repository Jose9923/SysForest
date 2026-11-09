<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobileIdempotencyConflictException;
use App\Models\Evidences\Evidence;
use App\Models\User;
use App\Repositories\Evidences\EvidenceRepository;
use App\Repositories\Catalogs\EvidenceTypeRepository;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Mobile\MobilePhotoRepository;
use App\Repositories\Users\UserRepository;
use App\Validation\Mobile\MobileTreePhotoInput;
use App\Validation\Mobile\MobileInventoryPageInput;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use App\Services\Evidences\EvidenceAuditService;
use App\Services\Evidences\EvidenceDeliveryService;
use App\Services\Evidences\EvidenceOwnerService;
use App\Services\Evidences\EvidenceReadService;
use App\Services\Evidences\EvidenceRecoveryService;
use App\Services\Evidences\EvidenceStorageService;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Evidences\ImageMetadataService;

/** Foto, auditoría y recibo se confirman juntos; el journal recupera cualquier archivo no confirmado. */
class MobileTreePhotoService
{
    public function __construct(
        private UserRepository $users,
        private EvidenceOwnerService $owners,
        private EvidenceRepository $evidences,
        private EvidenceTypeRepository $types,
        private MobilePhotoRepository $receipts,
        private ImageMetadataService $images,
        private EvidenceStorageService $storage,
        private EvidenceRecoveryService $recovery,
        private EvidenceAuditService $audit,
        private EvidenceReadService $reader,
        private EvidenceDeliveryService $delivery,
        private ForestryAccessService $access,
        private InventoryRepository $inventories,
    ) {
    }

    public function upload(User $actor, string $treeId, array $input): array
    {
        $treeId = strtolower($treeId);
        $owner = $this->owners->resolve($actor, $treeId, null);
        Gate::forUser($actor)->authorize('create', [Evidence::class, $owner]);
        $validator = Validator::make($input, MobileTreePhotoInput::rules(), MobileTreePhotoInput::messages());
        $validator->after(fn ($validator) => MobileTreePhotoInput::rejectUnexpected($validator, $input));
        $data = $validator->validate();
        $technical = $this->images->inspect($data['file']);
        $clientId = strtolower($data['client_id']);
        $metadata = $this->metadata($data);
        // Un cambio de nombre local no cambia la foto. Sí cambian sus bytes o los metadatos semánticos.
        $hash = hash('sha256', json_encode([
            'checksum_sha256' => $technical['checksum_sha256'],
            'evidence_type_id' => $metadata['evidence_type_id'],
            'caption' => $metadata['caption'], 'notes' => $metadata['notes'],
            'captured_at' => $metadata['captured_at']?->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR));
        $operationIds = [];
        try {
            // Los locks actor → organización → árbol coinciden con los flujos web y serializan reintentos.
            // Una transacción que escribe archivos nunca se reintenta automáticamente.
            return DB::transaction(function () use ($actor, $treeId, $data, $technical, $clientId, $metadata, $hash, &$operationIds): array {
                $actor = $this->users->findForUpdate((int) $actor->id);
                $owner = $this->owners->resolve($actor, $treeId, null, true);
                Gate::forUser($actor)->authorize('create', [Evidence::class, $owner]);
                $receipt = $this->receipts->receipt($owner->organization_id, $clientId);
                if ($receipt !== null) {
                    if ($receipt->created_by !== (int) $actor->id || $receipt->tree_id !== $treeId
                        || !hash_equals($receipt->payload_hash, $hash)) {
                        throw new MobileIdempotencyConflictException('client_id ya fue confirmado con otra fotografía, metadatos, usuario o árbol. Conserva la operación original y revisa el conflicto.');
                    }

                    return ['client_id' => $clientId, 'status' => 'replayed', 'photo' => $receipt->result];
                }
                if (!$this->types->validate($metadata['evidence_type_id'], null, true)) {
                    throw ValidationException::withMessages(['evidence_type_id' => 'Selecciona un tipo de evidencia activo.']);
                }
                $id = (string) Str::uuid();
                $operationIds[] = $id;
                $this->evidences->lockStorageOperation($id);
                $reference = $this->storage->store($data['file'], $owner->organization_id, $technical, $id);
                $evidence = $this->evidences->create($technical + $metadata + [
                    'id' => $id, 'organization_id' => $owner->organization_id,
                    'tree_id' => $owner->id, 'tree_inspection_id' => null, 'maintenance_id' => null,
                    'disk' => $reference['disk'], 'path' => $reference['path'],
                    'uploaded_by' => $actor->id, 'updated_by' => $actor->id, 'lock_version' => 1,
                ]);
                $this->audit->record($actor, $evidence, [], 'evidence.uploaded');
                $photo = $this->photo($evidence);
                $this->receipts->confirm([
                    'organization_id' => $owner->organization_id, 'client_id' => $clientId,
                    'created_by' => $actor->id, 'payload_hash' => $hash, 'tree_id' => $owner->id,
                    'evidence_id' => $evidence->id, 'result' => $photo,
                ]);
                DB::afterCommit(fn () => $this->recovery->acknowledge([$reference]));

                return ['client_id' => $clientId, 'status' => 'created', 'photo' => $photo];
            });
        } catch (Throwable $exception) {
            // Reconciliación consulta DB bajo lock: jamás elimina un archivo cuyo commit sí ocurrió.
            $this->recovery->compensate($operationIds);
            if ($exception instanceof ValidationException || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            throw new HttpException(503, 'No fue posible confirmar la fotografía. Reintenta con el mismo client_id, archivo y metadatos.', $exception);
        }
    }

    public function index(User $actor, string $treeId, array $input): array
    {
        $owner = $this->owners->resolve($actor, strtolower($treeId), null);
        Gate::forUser($actor)->authorize('viewAny', Evidence::class);
        Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']])->validate();
        $page = $this->evidences->paginateForOwner($owner->organization_id, $owner->id, null);

        return ['data' => $page->getCollection()->map($this->photo(...))->all(), 'meta' => [
            'tree_id' => $owner->id, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(), 'has_more' => $page->hasMorePages(),
        ]];
    }

    /** Descarga paginada de metadatos privados; los originales se reautorizan al solicitar su contenido. */
    public function inventory(User $actor, string $inventoryId, array $input): array
    {
        $data = MobileInventoryPageInput::validate($input);
        $organization = $this->access->organization($actor);
        $inventory = $this->inventories->findForTenant($organization->id, strtolower($inventoryId));
        Gate::forUser($actor)->authorize('view', $inventory);
        $this->access->authorize($actor, 'trees.view');
        Gate::forUser($actor)->authorize('viewAny', Evidence::class);
        $page = $this->evidences->paginateForInventory(
            $organization->id,
            $inventory->id,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 100),
            $this->access->allows($actor, 'inspections.view'),
            $this->access->allows($actor, 'maintenances.view'),
        );

        return ['data' => $page->getCollection()->map(fn (Evidence $photo): array => array_replace($this->photo($photo), [
            'tree_id' => $photo->getAttribute('mobile_tree_id'),
            'tree_inspection_id' => $photo->tree_inspection_id, 'maintenance_id' => $photo->maintenance_id,
            'source_kind' => $photo->tree_id !== null ? 'tree' : ($photo->tree_inspection_id !== null ? 'inspection' : 'maintenance'),
        ]))->all(), 'meta' => [
            'organization_id' => $organization->id, 'inventory_id' => $inventory->id,
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(), 'has_more' => $page->hasMorePages(),
            'generated_at' => now()->toIso8601String(),
        ]];
    }

    public function content(User $actor, string $photoId): StreamedResponse
    {
        $photo = $this->reader->authorizeExisting($actor, strtolower($photoId));
        abort_if($photo->trashed(), 404);

        return $this->delivery->response($actor, $photo->id);
    }

    private function metadata(array $data): array
    {
        return [
            'evidence_type_id' => strtolower($data['evidence_type_id']),
            'caption' => isset($data['caption']) && trim($data['caption']) !== '' ? trim($data['caption']) : null,
            'notes' => isset($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null,
            'captured_at' => !empty($data['captured_at']) ? CarbonImmutable::parse($data['captured_at'])->utc() : null,
        ];
    }

    private function photo(Evidence $photo): array
    {
        return [
            'id' => $photo->id, 'tree_id' => $photo->tree_id, 'evidence_type_id' => $photo->evidence_type_id,
            'evidence_type_code' => $photo->evidenceType?->code, 'evidence_type_name' => $photo->evidenceType?->name,
            'source_kind' => $photo->tree_id !== null ? 'tree' : ($photo->tree_inspection_id !== null ? 'inspection' : 'maintenance'),
            'tree_inspection_id' => $photo->tree_inspection_id, 'maintenance_id' => $photo->maintenance_id,
            'caption' => $photo->caption, 'notes' => $photo->notes,
            'captured_at' => $photo->captured_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'mime_type' => $photo->mime_type, 'size_bytes' => $photo->size_bytes,
            'width' => $photo->width, 'height' => $photo->height, 'checksum_sha256' => $photo->checksum_sha256,
            'created_at' => $photo->created_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'uploaded_by' => $photo->uploaded_by,
            'content_url' => route('mobile.photos.content', $photo->id, false),
        ];
    }
}

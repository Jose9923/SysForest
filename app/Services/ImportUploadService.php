<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Enums\InventoryStatus;
use App\Imports\ImportTemplateSchema;
use App\Imports\ImportWorkbookReader;
use App\Models\ImportBatch;
use App\Models\User;
use App\Repositories\ImportBatchRepository;
use App\Repositories\ImportRowRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Registra una vista previa auditable; el XLSX nunca se publica ni se conserva como archivo ejecutable. */
class ImportUploadService
{
    public function __construct(
        private ImportAccessService $access,
        private ImportWorkbookReader $reader,
        private ImportValidationService $validation,
        private ImportBatchRepository $batches,
        private ImportRowRepository $rows,
        private InventoryRepository $inventories,
        private UserRepository $users,
        private SecurityEventRepository $audit,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function upload(User $actor, UploadedFile $file, string $inventoryId): ImportBatch
    {
        $organization = $this->access->authorize($actor, 'imports.create');
        $this->inventories->findForTenant($organization->id, $inventoryId);
        if (!$file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'xlsx'
            || $file->getSize() < 1 || $file->getSize() > (int) config('imports.max_upload_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'Selecciona una plantilla oficial XLSX dentro del tamaño permitido.']);
        }
        $sources = $this->reader->read($file->getPathname());
        if ($sources === [] || count($sources) > (int) config('imports.max_rows')) {
            throw ValidationException::withMessages(['file' => 'El archivo debe contener entre 1 y '.config('imports.max_rows').' filas de datos.']);
        }
        $hash = hash_file('sha256', $file->getPathname());
        $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'plantilla.xlsx', 0, 255);

        return DB::transaction(function () use ($actor, $sources, $inventoryId, $hash, $name, $file): ImportBatch {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->authorize($actor, 'imports.create', true);
            if ($this->batches->sourceWasImported($organization->id, $hash)) {
                throw ValidationException::withMessages(['file' => 'Este mismo archivo ya fue importado en la organización. Consulta el resultado del lote anterior.']);
            }
            $inventory = $this->inventories->findForTenant($organization->id, $inventoryId, true);
            if (!$inventory->active || $inventory->status !== InventoryStatus::Active) {
                throw ValidationException::withMessages(['inventory_id' => 'Selecciona una campaña activa de la organización.']);
            }
            $results = $this->validation->validate($organization, $inventory, $sources, true);
            $invalid = count(array_filter($results, fn (array $row): bool => $row['errors'] !== []));
            $id = (string) Str::uuid();
            $batch = $this->batches->create([
                'id' => $id, 'organization_id' => $organization->id, 'inventory_id' => $inventory->id,
                'code' => 'IMP-'.now()->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', $id), -12)),
                'schema_version' => ImportTemplateSchema::VERSION, 'file_name' => $name, 'file_sha256' => $hash,
                'file_size' => $file->getSize(), 'status' => $invalid ? ImportBatchStatus::Invalid : ImportBatchStatus::Ready,
                'total_rows' => count($results), 'valid_rows' => count($results) - $invalid,
                'invalid_rows' => $invalid, 'imported_rows' => 0, 'lock_version' => 1, 'created_by' => $actor->id,
                'expires_at' => now()->addHours(max(1, (int) config('imports.preview_ttl_hours'))),
            ]);
            foreach ($results as $result) {
                $this->rows->create($batch, $result + ['status' => $result['errors'] === [] ? 'valid' : 'invalid',
                    'tree_code' => $result['normalized_values']['requested_code'] ?? null]);
            }
            $this->audit->record(
                'import.preview_created',
                $actor,
                $organization->id,
                ['resource_id' => $batch->id, 'resource_code' => $batch->code, 'reason' => 'filas:'.$batch->total_rows.';errores:'.$invalid],
                'import_batch',
                $batch->id,
                [],
                $this->snapshots->snapshot($batch)
            );

            return $batch;
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Imports\ImportBatchStatus;
use App\Enums\Inventories\InventoryStatus;
use App\Imports\ImportTemplateSchema;
use App\Imports\ImportWorkbookReader;
use App\Models\Imports\ImportBatch;
use App\Models\User;
use App\Repositories\Imports\ImportBatchRepository;
use App\Repositories\Imports\ImportRowRepository;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditSnapshotService;

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

    public const PREPARED_VERSION = 'SYSFOREST_PREPARED_IMPORT_V1';

    /**
     * @internal Solo herramientas CLI: prepara un bloque determinista, sin confiar en valores normalizados.
     * @return array{sources:array,hash:string,name:string,size:int,reason:string}
     */
    public function preparedSource(array $sources, string $sourceFile, string $sourceHash): array
    {
        if (!app()->runningInConsole()) {
            throw new \LogicException('La importación revisada solo está disponible desde la consola.');
        }
        if ($sourceFile === '' || mb_strlen($sourceFile) > 255 || basename(str_replace('\\', '/', $sourceFile)) !== $sourceFile
            || preg_match('/[\x00-\x1F\x7F]/u', $sourceFile) || preg_match('/\A[a-f0-9]{64}\z/D', $sourceHash) !== 1) {
            throw ValidationException::withMessages(['file' => 'La fuente revisada requiere nombre de archivo y SHA-256 original válidos.']);
        }
        if (!array_is_list($sources) || $sources === [] || count($sources) > (int) config('imports.max_rows')) {
            throw ValidationException::withMessages(['rows' => 'Cada bloque debe contener entre 1 y '.config('imports.max_rows').' filas.']);
        }
        $headers = (new ImportTemplateSchema())->headers();
        $ordered = [];
        $previous = 1;
        $errors = [];
        foreach ($sources as $index => $source) {
            $field = 'rows.'.($index + 1);
            if (!is_array($source) || array_diff(array_keys($source), ['row_number', 'values']) !== []
                || !isset($source['row_number'], $source['values']) || !is_int($source['row_number'])
                || $source['row_number'] <= $previous || $source['row_number'] > 2147483647
                || !is_array($source['values'])) {
                $errors[$field][] = 'Cada fila debe conservar su número Excel ascendente, único y sus valores.';
                continue;
            }
            $previous = $source['row_number'];
            $values = $source['values'];
            if (array_diff(array_keys($values), $headers) !== [] || array_diff($headers, array_keys($values)) !== []) {
                $errors[$field][] = 'La fila debe contener exactamente las 110 columnas oficiales de importación.';
                continue;
            }
            $canonical = [];
            foreach ($headers as $header) {
                $value = $values[$header];
                if ($value !== null && (!is_string($value) || mb_strlen($value) > (int) config('imports.max_cell_chars'))) {
                    $errors[$field][] = 'Las celdas deben ser texto o null, dentro del límite de caracteres; no se aceptan booleanos ni números JSON.';
                    continue;
                }
                $canonical[$header] = $value;
            }
            $ordered[] = ['row_number' => $source['row_number'], 'values' => $canonical];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $content = json_encode(['version' => self::PREPARED_VERSION, 'source_file' => $sourceFile,
            'source_sha256' => $sourceHash, 'rows' => $ordered], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($content) > (int) config('imports.max_upload_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'El bloque JSON revisado supera el tamaño de carga permitido.']);
        }
        $first = $ordered[0]['row_number'];
        $last = $ordered[array_key_last($ordered)]['row_number'];

        return ['sources' => $ordered, 'hash' => hash('sha256', $content), 'size' => strlen($content),
            'name' => 'reviewed-'.$sourceHash.'-'.$first.'-'.$last.'.json',
            'reason' => 'origen_cli:'.$sourceFile.';sha256_original:'.$sourceHash.';filas_excel:'.$first.'-'.$last];
    }

    /** @internal La interfaz HTTP conserva upload(UploadedFile) y nunca acepta este contrato CLI. */
    public function uploadPrepared(User $actor, array $sources, string $inventoryId, string $sourceFile, string $sourceHash): ImportBatch
    {
        $organization = $this->access->authorize($actor, 'imports.create');
        $this->inventories->findForTenant($organization->id, $inventoryId);
        $prepared = $this->preparedSource($sources, $sourceFile, $sourceHash);

        return $this->createPreview(
            $actor,
            $prepared['sources'],
            $inventoryId,
            $prepared['hash'],
            $prepared['name'],
            $prepared['size'],
            true,
            $prepared['reason']
        );
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

        return $this->createPreview($actor, $sources, $inventoryId, $hash, $name, (int) $file->getSize());
    }

    /** Comparte autorización, bloqueos y auditoría sin relajar el lector oficial de la entrada HTTP. */
    private function createPreview(User $actor, array $sources, string $inventoryId, string $hash, string $name, int $size, bool $reusePrepared = false, string $sourceReason = ''): ImportBatch
    {
        return DB::transaction(function () use ($actor, $sources, $inventoryId, $hash, $name, $size, $reusePrepared, $sourceReason): ImportBatch {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->authorize($actor, 'imports.create', true);
            if ($reusePrepared) {
                preg_match('/\\Areviewed-([a-f0-9]{64})-([0-9]+)-([0-9]+)\\.json\\z/D', $name, $identity);
                if ($identity === []) {
                    throw new \LogicException('La identidad del bloque preparado no es válida.');
                }
                foreach ($this->batches->preparedSourcesForTenant($organization->id, $identity[1], true) as $previous) {
                    preg_match('/-([0-9]+)-([0-9]+)\\.json\\z/D', $previous->file_name, $range);
                    $overlaps = $range !== [] && (int) $range[1] <= (int) $identity[3] && (int) $range[2] >= (int) $identity[2];
                    if (($previous->file_name === $name || $overlaps)
                        && ($previous->file_name !== $name || $previous->file_sha256 !== $hash || $previous->inventory_id !== $inventoryId)) {
                        throw ValidationException::withMessages(['file' => 'La fuente original ya tiene un bloque solapado de otra revisión, partición o campaña.']);
                    }
                }
                $existing = $this->batches->findBySourceHashForTenant($organization->id, $hash, true);
                if ($existing !== null) {
                    if ($existing->inventory_id !== $inventoryId || $existing->file_name !== $name || $existing->file_size !== $size
                        || !in_array($existing->status, [ImportBatchStatus::Ready, ImportBatchStatus::Completed], true)
                        || ($existing->status === ImportBatchStatus::Ready && $existing->expires_at->lessThanOrEqualTo(now()))) {
                        throw ValidationException::withMessages(['file' => 'El bloque ya tiene una vista previa no reutilizable o pertenece a otra campaña.']);
                    }

                    return $existing;
                }
            }
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
                // Las celdas XLSX se normalizan al contrato vigente; el contrato CLI conserva su versión.
                'schema_version' => $reusePrepared ? ImportTemplateSchema::LEGACY_VERSION : ImportTemplateSchema::VERSION, 'file_name' => $name, 'file_sha256' => $hash,
                'file_size' => $size, 'status' => $invalid ? ImportBatchStatus::Invalid : ImportBatchStatus::Ready,
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
                ['resource_id' => $batch->id, 'resource_code' => $batch->code, 'reason' => 'filas:'.$batch->total_rows.';errores:'.$invalid.($sourceReason === '' ? '' : ';'.$sourceReason)],
                'import_batch',
                $batch->id,
                [],
                $this->snapshots->snapshot($batch)
            );

            return $batch;
        }, 3);
    }
}

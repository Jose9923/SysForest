<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Imports\ImportBatchStatus;
use App\Enums\Inventories\InventoryStatus;
use App\Imports\ImportTemplateSchema;
use App\Models\Imports\ImportBatch;
use App\Repositories\Imports\ImportBatchRepository;
use App\Repositories\Imports\ImportRowRepository;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Users\UserRepository;
use Closure;
use Illuminate\Validation\ValidationException;
use JsonException;
use LogicException;
use Throwable;
use App\Services\Tenancy\TenantContextService;

/** Importación operativa explícita: prevalidación global sin escrituras y confirmación atómica por bloque. */
class PreparedInventoryImportService
{
    public function __construct(
        private UserRepository $users,
        private TenantContextService $tenants,
        private ImportAccessService $access,
        private InventoryRepository $inventories,
        private ImportUploadService $uploads,
        private ImportValidationService $validation,
        private ImportConfirmationService $confirmation,
        private ImportBatchRepository $batches,
        private ImportRowRepository $rows,
    ) {
    }

    /**
     * @param Closure(array):void|null $progress Solo comunica avance; no interviene en la validación.
     * @return array<string,mixed> Resultado explícito, incluidos bloques confirmados antes de un fallo.
     */
    public function run(string $path, int $actorId, string $organizationId, string $inventoryId, bool $confirm = false, ?Closure $progress = null): array
    {
        if (!app()->runningInConsole()) {
            throw new LogicException('La herramienta de inventario revisado solo admite ejecución CLI.');
        }
        $document = $this->readDocument($path);
        $actor = $this->users->find($actorId);

        return $this->tenants->run($actor, $organizationId, function () use ($document, $actor, $inventoryId, $confirm, $progress): array {
            $organization = $this->access->authorize($actor, 'imports.create');
            $inventory = $this->inventories->findForTenant($organization->id, $inventoryId);
            if (!$inventory->active || $inventory->status !== InventoryStatus::Active) {
                throw ValidationException::withMessages(['inventory' => 'Selecciona una campaña activa y disponible de la organización.']);
            }
            $limit = min(100, (int) config('imports.max_rows'));
            if ($limit < 1) {
                throw ValidationException::withMessages(['configuration' => 'El límite de filas de importación debe ser positivo.']);
            }
            $chunks = array_chunk($document['rows'], $limit);
            $report = ['mode' => $confirm ? 'confirm' : 'dry_run', 'status' => 'validating',
                'source_file' => $document['source_file'], 'source_sha256' => $document['source_sha256'],
                'organization_id' => $organization->id, 'inventory_id' => $inventory->id,
                'total_rows' => count($document['rows']), 'total_chunks' => count($chunks), 'chunk_size' => $limit,
                'completed_rows' => 0, 'skipped_completed_rows' => 0, 'confirmed_rows' => 0, 'chunks' => [], 'errors' => []];
            $prepared = [];
            $previousRow = 1;
            $codes = [];
            $hasAutomaticCode = false;

            // Se revisan todos los bloques antes de crear siquiera una vista previa en la base.
            foreach ($chunks as $index => $chunk) {
                $number = $index + 1;
                try {
                    $source = $this->uploads->preparedSource($chunk, $document['source_file'], $document['source_sha256']);
                    $prepared[$index] = $source;
                    if ($source['sources'][0]['row_number'] <= $previousRow) {
                        throw ValidationException::withMessages(['rows' => 'Los números Excel deben ser únicos y ascendentes en el documento completo.']);
                    }
                    $previousRow = $source['sources'][array_key_last($source['sources'])]['row_number'];
                    foreach ($source['sources'] as $row) {
                        $code = $row['values']['code'];
                        if (is_string($code) && $code !== '') {
                            $codes[$code][] = $row['row_number'];
                        } else {
                            $hasAutomaticCode = true;
                        }
                    }
                    $this->access->authorize($actor, 'imports.create');
                    $batch = $this->batches->findBySourceHashForTenant($organization->id, $source['hash']);
                    if ($batch !== null) {
                        $this->assertExistingBatch($batch, $source, $inventory->id);
                    }
                    $completed = $batch?->status === ImportBatchStatus::Completed;
                    if (!$completed) {
                        foreach ($this->validation->validate($organization, $inventory, $source['sources']) as $row) {
                            if ($row['errors'] !== []) {
                                $report['errors'][] = ['chunk' => $number, 'row_number' => $row['row_number'], 'errors' => $row['errors']];
                            }
                        }
                    } else {
                        // Sus filas originales y referencias ya fueron contrastadas; no se tratan como árboles nuevos.
                        $report['completed_rows'] += $batch->imported_rows;
                        $report['skipped_completed_rows'] += $batch->imported_rows;
                    }
                    $report['chunks'][$index] = ['chunk' => $number, 'rows' => count($chunk), 'source_hash' => $source['hash'],
                        'status' => $completed ? 'already_completed' : 'validated', 'batch_id' => $batch?->id, 'batch_code' => $batch?->code];
                } catch (ValidationException $exception) {
                    $report['errors'][] = ['chunk' => $number, 'row_number' => null, 'errors' => $exception->errors()];
                }
                $progress?->__invoke(['phase' => 'validation', 'chunk' => $number, 'total_chunks' => count($chunks)]);
            }
            foreach ($codes as $rowNumbers) {
                if (count($rowNumbers) > 1) {
                    foreach ($rowNumbers as $rowNumber) {
                        $report['errors'][] = ['chunk' => null, 'row_number' => $rowNumber,
                            'errors' => ['code' => ['El código se repite en el documento completo, incluso entre bloques.']]];
                    }
                }
            }
            // La reserva de consecutivos es atómica por lote: no puede anticipar códigos explícitos de otro bloque.
            if (count($chunks) > 1 && $hasAutomaticCode && $codes !== []) {
                $report['errors'][] = ['chunk' => null, 'row_number' => null, 'errors' => ['code' => [
                    'Un documento de varios bloques debe usar solo códigos automáticos o solo códigos explícitos. La mezcla puede producir colisiones entre bloques y no se importará.',
                ]]];
            }
            // Una revisión distinta o partición diferente no puede reutilizar el SHA original para duplicar individuos.
            $planned = array_column($prepared, 'hash', 'name');
            foreach ($this->batches->preparedSourcesForTenant($organization->id, $document['source_sha256']) as $existing) {
                if (($planned[$existing->file_name] ?? null) !== $existing->file_sha256 || $existing->inventory_id !== $inventory->id) {
                    $report['errors'][] = ['chunk' => null, 'row_number' => null, 'errors' => ['source' => [
                        'La fuente original ya tiene bloques de otra revisión, partición o campaña. Reintenta exactamente el archivo y límite anteriores.',
                    ]]];
                }
            }
            if ($report['errors'] !== []) {
                $report['status'] = 'invalid';

                return $report;
            }
            if (!$confirm) {
                $report['status'] = 'validated';

                return $report;
            }

            foreach ($prepared as $index => $source) {
                if ($report['chunks'][$index]['status'] === 'already_completed') {
                    continue;
                }
                try {
                    $batch = $this->uploads->uploadPrepared(
                        $actor,
                        $source['sources'],
                        $inventory->id,
                        $document['source_file'],
                        $document['source_sha256']
                    );
                    $report['chunks'][$index]['batch_id'] = $batch->id;
                    $report['chunks'][$index]['batch_code'] = $batch->code;
                    $this->assertExistingBatch($batch, $source, $inventory->id);
                    if ($batch->status === ImportBatchStatus::Completed) {
                        $report['skipped_completed_rows'] += $batch->imported_rows;
                        $report['chunks'][$index]['status'] = 'already_completed';
                    } else {
                        $batch = $this->confirmation->confirm($actor, $batch->id, $batch->lock_version);
                        if ($batch->status !== ImportBatchStatus::Completed) {
                            throw ValidationException::withMessages(['batch' => $batch->notice ?: 'La confirmación requiere revisar nuevamente el bloque.']);
                        }
                        $report['confirmed_rows'] += $batch->imported_rows;
                        $report['chunks'][$index]['status'] = 'completed';
                    }
                    $report['completed_rows'] += $batch->imported_rows;
                    $progress?->__invoke(['phase' => 'confirmation', 'chunk' => $index + 1, 'total_chunks' => count($chunks),
                        'batch_code' => $batch->code, 'completed_rows' => $report['completed_rows']]);
                } catch (Throwable $exception) {
                    $report['status'] = $report['completed_rows'] > 0 ? 'partial' : 'failed';
                    $report['failed_chunk'] = $index + 1;
                    $report['errors'][] = ['chunk' => $index + 1, 'row_number' => null,
                        'errors' => $exception instanceof ValidationException ? $exception->errors()
                            : ['batch' => ['No se confirmó el bloque. Revisa el lote y reintenta el mismo archivo; los bloques completados se omiten.']],
                        'exception_type' => get_class($exception)];

                    return $report;
                }
            }
            $report['status'] = 'completed';

            return $report;
        });
    }

    /** Ruta local confinada al almacenamiento privado; no se admiten URLs ni archivos de la interfaz HTTP. */
    private function readDocument(string $path): array
    {
        $private = realpath(storage_path('app/private'));
        $resolved = realpath($path);
        if ($private === false || $resolved === false || !str_starts_with($resolved, $private.DIRECTORY_SEPARATOR)
            || !is_file($resolved) || !is_readable($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'json') {
            throw ValidationException::withMessages(['file' => 'Indica un archivo JSON legible dentro de storage/app/private.']);
        }
        $maximum = (int) config('imports.max_uncompressed_bytes');
        $size = filesize($resolved);
        if ($size === false || $size < 1 || $size > $maximum) {
            throw ValidationException::withMessages(['file' => 'El JSON preparado supera el límite de lectura privada.']);
        }
        $content = file_get_contents($resolved, false, null, 0, $maximum + 1);
        if ($content === false || strlen($content) > $maximum) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo preparado dentro del límite permitido.']);
        }
        try {
            $document = json_decode($content, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => 'El archivo debe contener JSON UTF-8 válido, sin BOM.']);
        }
        if (!is_array($document) || array_diff(array_keys($document), ['version', 'source_file', 'source_sha256', 'rows']) !== []
            || ($document['version'] ?? null) !== ImportUploadService::PREPARED_VERSION
            || !is_string($document['source_file'] ?? null) || !is_string($document['source_sha256'] ?? null)
            || !is_array($document['rows'] ?? null) || !array_is_list($document['rows']) || $document['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'El JSON no cumple el contrato SYSFOREST_PREPARED_IMPORT_V1.']);
        }

        return $document;
    }

    /** Un hash repetido solo se reutiliza si las filas, campaña y resultado conservan exactamente su identidad. */
    private function assertExistingBatch(ImportBatch $batch, array $source, string $inventoryId): void
    {
        if ($batch->inventory_id !== $inventoryId || !in_array($batch->schema_version, [ImportTemplateSchema::VERSION, ImportTemplateSchema::LEGACY_VERSION], true)
            || $batch->file_sha256 !== $source['hash'] || $batch->file_name !== $source['name']
            || $batch->file_size !== $source['size'] || $batch->total_rows !== count($source['sources'])
            || ($batch->status === ImportBatchStatus::Completed && $batch->imported_rows !== $batch->total_rows)
            || !in_array($batch->status, [ImportBatchStatus::Ready, ImportBatchStatus::Completed], true)
            || ($batch->status === ImportBatchStatus::Ready && $batch->expires_at->lessThanOrEqualTo(now()))) {
            throw ValidationException::withMessages(['batch' => 'Existe un lote de esta fuente en otra campaña, no vigente o no reutilizable. Revisa su trazabilidad antes de continuar.']);
        }
        $saved = $this->rows->all($batch);
        if ($saved->count() !== count($source['sources'])) {
            throw ValidationException::withMessages(['batch' => 'Las filas conservadas no coinciden con el bloque preparado.']);
        }
        foreach ($source['sources'] as $index => $row) {
            $stored = $saved[$index];
            $savedValues = $stored->raw_values;
            $sourceValues = $row['values'];
            ksort($savedValues);
            ksort($sourceValues);
            if ($stored->row_number !== $row['row_number'] || $savedValues !== $sourceValues || $stored->reader_errors !== []) {
                throw ValidationException::withMessages(['batch' => 'La identidad de una fila guardada difiere del archivo revisado.']);
            }
            if ($batch->status === ImportBatchStatus::Completed && ($stored->status !== 'imported'
                || $stored->tree_id === null || $stored->inspection_id === null || $stored->tree_code === null)) {
                throw ValidationException::withMessages(['batch' => 'El resultado previo no conserva todas las referencias forestales requeridas.']);
            }
        }
    }
}

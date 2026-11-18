<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Imports\ImportBatchStatus;
use App\Enums\Inventories\InventoryStatus;
use App\Imports\ImportTemplateSchema;
use App\Models\Imports\ImportBatch;
use App\Models\User;
use App\Repositories\Imports\ImportBatchRepository;
use App\Repositories\Imports\ImportRowRepository;
use App\Repositories\Imports\ImportNormalizedIdentifierRepository;
use App\Repositories\Inventories\InventoryRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Audit\AuditSnapshotService;

/** La confirmación reautoriza y revalida el lote completo. Ninguna fila se confirma por separado. */
class ImportConfirmationService
{
    public function __construct(
        private ImportAccessService $access,
        private ImportValidationService $validation,
        private ImportRowWriteService $writes,
        private ImportBatchRepository $batches,
        private ImportRowRepository $rows,
        private ImportNormalizedIdentifierRepository $normalizedIdentifiers,
        private InventoryRepository $inventories,
        private UserRepository $users,
        private SecurityEventRepository $audit,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function confirm(User $actor, string $id, int $version): ImportBatch
    {
        return DB::transaction(function () use ($actor, $id, $version): ImportBatch {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->authorize($actor, 'imports.create', true);
            $batch = $this->batches->find($organization->id, $id, true);
            // Un reintento de red obtiene el resultado anterior sin repetir árboles ni inspecciones.
            if ($batch->status === ImportBatchStatus::Completed) {
                return $batch;
            }
            $this->requireVersion($batch, $version);
            if ($batch->status !== ImportBatchStatus::Ready) {
                throw new HttpException(409, 'Este lote no está listo para confirmar. Corrige el archivo y crea una nueva vista previa.');
            }
            if ($batch->expires_at->isPast() || !in_array($batch->schema_version, [ImportTemplateSchema::VERSION, ImportTemplateSchema::LEGACY_VERSION], true)) {
                return $this->cancelLocked($actor, $batch, 'La vista previa caducó o cambió la plantilla oficial. Carga nuevamente el archivo.');
            }
            if ($this->batches->sourceWasImported($organization->id, $batch->file_sha256)) {
                return $this->cancelLocked($actor, $batch, 'El mismo archivo ya se confirmó en otro lote. No se crearon registros duplicados.');
            }
            $before = $this->snapshots->snapshot($batch);
            $inventory = $this->inventories->findForTenant($organization->id, $batch->inventory_id, true);
            $saved = $this->rows->all($batch);
            if ($saved->count() !== $batch->total_rows || $saved->count() > (int) config('imports.max_rows')) {
                throw new HttpException(409, 'El lote no coincide con el límite o la cantidad de filas registrada. Carga nuevamente el archivo.');
            }
            $sources = $saved->map(fn ($row): array => ['row_number' => $row->row_number,
                'values' => $row->raw_values, 'errors' => $row->reader_errors])->all();
            $results = $this->validation->validate($organization, $inventory, $sources, true);
            if (!$inventory->active || $inventory->status !== InventoryStatus::Active) {
                foreach ($results as &$result) {
                    $result['errors']['inventory_id'][] = 'La campaña ya no está activa. No se importó ninguna fila.';
                }
                unset($result);
            }
            $invalid = count(array_filter($results, fn (array $row): bool => $row['errors'] !== []));
            $changed = false;
            foreach ($results as $index => $result) {
                $changed = $changed || $this->fingerprint($this->normalizedIdentifiers->forComparison($result['normalized_values']))
                    !== $this->fingerprint($this->normalizedIdentifiers->forComparison($saved[$index]->normalized_values));
            }
            if ($invalid > 0 || $changed) {
                foreach ($results as $index => $result) {
                    $this->rows->update($saved[$index], ['normalized_values' => $result['normalized_values'],
                        'errors' => $result['errors'], 'status' => $result['errors'] === [] ? 'valid' : 'invalid']);
                }
                $this->batches->update($batch, ['status' => $invalid ? ImportBatchStatus::Invalid : ImportBatchStatus::Ready,
                    'valid_rows' => $batch->total_rows - $invalid, 'invalid_rows' => $invalid,
                    'lock_version' => $batch->lock_version + 1,
                    'notice' => $invalid ? 'La validación cambió. Revisa los errores; no se importó ninguna fila.'
                        : 'Los datos de referencia cambiaron. Revisa la vista previa actualizada y confirma nuevamente.']);
                $this->event('import.preview_revalidated', $actor, $batch, $before);

                return $batch;
            }

            // La reserva completa evita que una fila automática consuma un código explícito posterior.
            $validated = array_column($results, 'normalized_values');
            $this->writes->reserveCodes($organization, $validated);
            foreach ($validated as $index => $values) {
                $created = $this->writes->create($actor, $inventory, $values);
                $this->rows->update($saved[$index], ['status' => 'imported', 'tree_id' => $created['tree']->id,
                    'tree_code' => $created['tree']->code, 'inspection_id' => $created['inspection']->id]);
            }
            $this->batches->update($batch, ['status' => ImportBatchStatus::Completed, 'imported_rows' => $batch->total_rows,
                'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'lock_version' => $batch->lock_version + 1,
                'notice' => 'Se importaron todas las filas. Los individuos e inspecciones permanecen privados hasta una publicación explícita.']);
            $this->event('import.completed', $actor, $batch, $before);

            return $batch;
        }, 3);
    }

    public function cancel(User $actor, string $id, int $version): ImportBatch
    {
        return DB::transaction(function () use ($actor, $id, $version): ImportBatch {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->authorize($actor, 'imports.create', true);
            $batch = $this->batches->find($organization->id, $id, true);
            if ($batch->status === ImportBatchStatus::Cancelled) {
                return $batch;
            }
            $this->requireVersion($batch, $version);
            if ($batch->status === ImportBatchStatus::Completed) {
                throw new HttpException(409, 'El lote ya fue importado. Sus registros conservan su trazabilidad.');
            }

            return $this->cancelLocked($actor, $batch, 'El lote fue cancelado. No se importó ninguna fila.');
        }, 3);
    }

    private function cancelLocked(User $actor, ImportBatch $batch, string $notice): ImportBatch
    {
        $before = $this->snapshots->snapshot($batch);
        $this->batches->update($batch, ['status' => ImportBatchStatus::Cancelled, 'cancelled_by' => $actor->id,
            'cancelled_at' => now(), 'notice' => $notice, 'lock_version' => $batch->lock_version + 1]);
        $this->event('import.cancelled', $actor, $batch, $before);

        return $batch;
    }

    private function requireVersion(ImportBatch $batch, int $version): void
    {
        if ($version < 1 || $version !== $batch->lock_version) {
            throw new HttpException(409, 'El lote cambió desde que abriste la vista previa. Actualiza la página antes de continuar.');
        }
    }

    private function event(string $event, User $actor, ImportBatch $batch, array $before): void
    {
        $this->audit->record(
            $event,
            $actor,
            $batch->organization_id,
            ['resource_id' => $batch->id, 'resource_code' => $batch->code, 'reason' => 'filas:'.$batch->total_rows],
            'import_batch',
            $batch->id,
            $before,
            $this->snapshots->snapshot($batch)
        );
    }

    private function fingerprint(array $values): string
    {
        // JSONB reordena claves: compara el contenido, no el orden accidental de serialización.
        $sort = function (array $data) use (&$sort): array {
            if (!array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }

            return $data;
        };

        return hash('sha256', json_encode($sort($values), JSON_THROW_ON_ERROR));
    }
}

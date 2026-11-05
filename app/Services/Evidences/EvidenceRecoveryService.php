<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Repositories\Evidences\EvidenceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Recupera únicamente intentos registrados, nunca escanea ni purga evidencias existentes. */
class EvidenceRecoveryService
{
    public function __construct(private EvidenceRepository $evidences, private EvidenceStorageService $storage)
    {
    }

    public function recover(string $operationId): bool
    {
        return DB::transaction(function () use ($operationId): bool {
            // Serializa contra la escritura original; un commit incierto se comprueba antes de borrar.
            $this->evidences->lockStorageOperation($operationId);
            $reference = $this->storage->findPending($operationId);
            if ($reference === null) {
                return false;
            }
            if ($this->evidences->referencedStoragePath($reference['disk'], $reference['path'])) {
                $this->storage->acknowledgeWritten([$reference]);
            } else {
                $this->storage->deleteWritten([$reference]);
            }

            return true;
        });
    }

    /** Un fallo de recuperación conserva el journal; no oculta el error principal ni inventa éxito. */
    public function compensate(array $operationIds): void
    {
        foreach ($operationIds as $id) {
            try {
                $this->recover($id);
            } catch (Throwable) {
                Log::critical('La carga requiere recuperación de almacenamiento.', ['operation_id' => $id]);
            }
        }
    }

    public function acknowledge(array $references): void
    {
        try {
            $this->storage->acknowledgeWritten($references);
        } catch (Throwable) {
            foreach ($references as $reference) {
                Log::warning('La carga confirmada conserva un journal pendiente.', ['operation_id' => $reference['operation_id']]);
            }
        }
    }
}

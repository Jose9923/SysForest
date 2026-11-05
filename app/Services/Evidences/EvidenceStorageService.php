<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Models\Evidences\Evidence;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/** Encapsula Filesystem. El coordinador mantiene el lock PostgreSQL de cada operación. */
class EvidenceStorageService
{
    public function __construct(private EvidenceStorageJournal $journal)
    {
    }

    /** operationId es único por archivo; el lote conserva una lista de estas referencias. */
    public function store(UploadedFile $file, string $organizationId, array $technical, string $operationId): array
    {
        $reference = $this->journal->reference($organizationId, $operationId, $technical['extension'] ?? '');
        $this->journal->begin($reference);
        $stream = null;
        try {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false || ! $this->journal->disk()->put($reference['path'], $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('No se pudo almacenar la imagen.');
            }
            if ($this->journal->disk()->size($reference['path']) !== ($technical['size_bytes'] ?? null)) {
                throw new RuntimeException('El archivo almacenado está incompleto.');
            }

            return $reference;
        } catch (Throwable $failure) {
            try {
                $this->deleteWritten([$reference]);
            } catch (Throwable) {
                // La intención permanece disponible para reconciliación con lock y referencia DB.
                throw new RuntimeException('La carga falló y su limpieza requiere reconciliación.', 0, $failure);
            }
            throw new RuntimeException('No se pudo almacenar la imagen.', 0, $failure);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Solo se invoca cuando el coordinador confirmó ausencia de referencia DB bajo el mismo lock. */
    public function deleteWritten(array $references): void
    {
        $failed = false;
        foreach ($references as $reference) {
            try {
                $reference = $this->journal->validateReference($reference);
                $pending = $this->journal->find($reference['operation_id']);
                if ($pending === null && ! $this->journal->disk()->exists($reference['path'])) {
                    continue;
                }
                if ($pending === null || $pending !== $reference) {
                    throw new RuntimeException('No existe una intención verificable para compensar esta carga.');
                }
                if (! $this->journal->disk()->delete($reference['path'])) {
                    throw new RuntimeException('No se pudo compensar el archivo de la carga.');
                }
                $this->journal->acknowledge($reference);
            } catch (Throwable) {
                $failed = true;
            }
        }
        if ($failed) {
            throw new RuntimeException('No se completó la compensación de todas las cargas. Sus registros pendientes se conservan.');
        }
    }

    /** Después del commit solo retira intenciones; jamás borra fotografías. */
    public function acknowledgeWritten(array $references): void
    {
        $failed = false;
        foreach ($references as $reference) {
            try {
                $this->journal->acknowledge($reference);
            } catch (Throwable) {
                $failed = true;
            }
        }
        if ($failed) {
            throw new RuntimeException('La evidencia está guardada; no se pudo cerrar todo el registro de almacenamiento.');
        }
    }

    public function pendingOperations(): array
    {
        return $this->journal->pending();
    }

    public function findPending(string $operationId): ?array
    {
        return $this->journal->find($operationId);
    }

    public function exists(Evidence $evidence): bool
    {
        return $this->journal->disk()->exists($this->evidenceReference($evidence)['path']);
    }

    /** @return resource */
    public function read(Evidence $evidence)
    {
        $stream = $this->journal->disk()->readStream($this->evidenceReference($evidence)['path']);
        if (! is_resource($stream)) {
            throw new RuntimeException('El archivo de esta evidencia no está disponible.');
        }

        return $stream;
    }

    private function evidenceReference(Evidence $evidence): array
    {
        if (! is_string($evidence->path) || ! preg_match('/\/([0-9a-f-]{36})\.(?:jpg|png|webp)\z/D', $evidence->path, $parts)) {
            throw new RuntimeException('La ubicación de la evidencia no es válida.');
        }
        $reference = $this->journal->validateReference([
            'disk' => $evidence->disk, 'path' => $evidence->path, 'operation_id' => $parts[1],
        ]);
        if (! str_starts_with($reference['path'], 'organizations/'.strtolower((string) $evidence->organization_id).'/')) {
            throw new RuntimeException('La ubicación de la evidencia no corresponde a su organización.');
        }

        return $reference;
    }
}

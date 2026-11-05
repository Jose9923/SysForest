<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/** Intenciones durables de escritura. No decide si una referencia está confirmada en PostgreSQL. */
class EvidenceStorageJournal
{
    public function __construct(private Factory $filesystems)
    {
    }

    public function diskName(): string
    {
        $name = config('evidence.disk');
        if (! is_string($name) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,49}\z/D', $name)) {
            throw new RuntimeException('El almacenamiento de evidencias no está configurado.');
        }

        return $name;
    }

    public function disk(): Filesystem
    {
        return $this->filesystems->disk($this->diskName());
    }

    public function reference(string $organizationId, string $operationId, string $extension): array
    {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($operationId)
            || ! in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('La referencia de almacenamiento no es válida.');
        }

        return [
            'disk' => $this->diskName(),
            'path' => 'organizations/'.strtolower($organizationId).'/'.strtolower($operationId).'.'.$extension,
            'operation_id' => strtolower($operationId),
        ];
    }

    public function validateReference(array $reference): array
    {
        if (($reference['disk'] ?? null) !== $this->diskName()
            || ! is_string($reference['path'] ?? null) || ! is_string($reference['operation_id'] ?? null)
            || ! preg_match('/\Aorganizations\/([0-9a-f-]{36})\/([0-9a-f-]{36})\.(jpg|png|webp)\z/D', $reference['path'], $parts)) {
            throw new RuntimeException('La referencia de almacenamiento no es válida.');
        }
        $expected = $this->reference($parts[1], $parts[2], $parts[3]);
        if ($reference['operation_id'] !== $expected['operation_id']) {
            throw new RuntimeException('La operación de almacenamiento no corresponde al archivo.');
        }

        return $expected;
    }

    public function begin(array $reference): void
    {
        $reference = $this->validateReference($reference);
        $journalPath = $this->journalPath($reference['operation_id']);
        if ($this->disk()->exists($journalPath) || $this->disk()->exists($reference['path'])) {
            throw new RuntimeException('La operación de carga ya existe y no puede sobrescribirse.');
        }
        if (! $this->disk()->put($journalPath, json_encode($reference, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
            throw new RuntimeException('No se pudo registrar la intención de almacenamiento.');
        }
    }

    public function acknowledge(array $reference): void
    {
        $reference = $this->validateReference($reference);
        $pending = $this->find($reference['operation_id']);
        if ($pending !== null && $pending !== $reference) {
            throw new RuntimeException('El registro de carga no corresponde al archivo.');
        }
        if (! $this->disk()->delete($this->journalPath($reference['operation_id']))) {
            throw new RuntimeException('No se pudo cerrar el registro de almacenamiento.');
        }
    }

    public function find(string $operationId): ?array
    {
        $path = $this->journalPath($operationId);
        if (! $this->disk()->exists($path)) {
            return null;
        }
        if ($this->disk()->size($path) > 2048) {
            throw new RuntimeException('El registro de almacenamiento no es válido.');
        }
        $decoded = json_decode($this->disk()->get($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || count($decoded) !== 3) {
            throw new RuntimeException('El registro de almacenamiento no es válido.');
        }
        $reference = $this->validateReference($decoded);
        if ($reference['operation_id'] !== strtolower($operationId)) {
            throw new RuntimeException('El registro de almacenamiento no corresponde a la operación.');
        }

        return $reference;
    }

    public function pending(): array
    {
        $references = [];
        foreach ($this->disk()->files('.pending') as $path) {
            if (! preg_match('/\A\.pending\/([0-9a-f-]{36})\.json\z/D', $path, $parts)) {
                continue;
            }
            $reference = $this->find($parts[1]);
            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    private function journalPath(string $operationId): string
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('La operación de almacenamiento no es válida.');
        }

        return '.pending/'.strtolower($operationId).'.json';
    }
}

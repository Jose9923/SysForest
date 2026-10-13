<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\AuditScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Consulta Mongo cuando está completo; la fuente transaccional evita vacíos durante la entrega. */
class AuditReadRepository
{
    public const SOURCES = AuditSourceRepository::SOURCES;

    private ?string $notice = null;

    public function __construct(private AuditSourceRepository $source, private MongoAuditRepository $mongo)
    {
    }

    public function paginate(AuditScope $scope, array $filters): LengthAwarePaginator
    {
        return $this->read(fn () => $this->mongo->paginate($scope, $filters), fn () => $this->source->paginate($scope, $filters));
    }

    public function find(AuditScope $scope, string $source, string $id): ?object
    {
        return $this->read(fn () => $this->mongo->find($scope, $source, $id), fn () => $this->source->find($scope, $source, $id));
    }

    public function organizations(AuditScope $scope): Collection
    {
        return $this->source->organizations($scope);
    }

    public function options(AuditScope $scope): array
    {
        return $this->source->options($scope);
    }

    public function notice(): ?string
    {
        return $this->notice;
    }

    private function read(callable $mongo, callable $fallback): mixed
    {
        $this->notice = null;
        if (config('audit.store', 'postgres') !== 'mongodb') {
            return $fallback();
        }
        try {
            if ($this->source->hasPending() || $this->source->totalCount() !== $this->mongo->totalCount()) {
                $this->notice = 'La sincronización del historial está en curso. Se muestran los registros desde el respaldo actualizado.';

                return $fallback();
            }

            return $mongo();
        } catch (Throwable) {
            // Nunca incluir la excepción: el driver puede adjuntar credenciales o contenido privado.
            Log::warning('Auditoría consultada desde la fuente transaccional por indisponibilidad del destino.');
            $this->notice = 'El almacenamiento de consulta está temporalmente sin conexión. Puedes consultar el historial desde el respaldo.';

            return $fallback();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use LogicException;

class OrganizationSequenceRepository
{
    /** Cada dominio reserva sus códigos dentro de la transacción que crea el registro. */
    public function nextMaintenanceValue(string $organizationId): int
    {
        return $this->nextValue($organizationId, 'maintenance');
    }

    /** UPSERT bloquea la fila institucional; la secuencia y el árbol se confirman en la misma transacción. */
    public function nextTreeValue(string $organizationId): int
    {
        return $this->nextValue($organizationId, 'tree');
    }

    /** Adelanta la secuencia sin reducirla; serializa importaciones con las capturas manuales. */
    public function reserveTreeAtLeast(string $organizationId, int $minimum): int
    {
        if (DB::transactionLevel() < 1 || $minimum < 0) {
            throw new LogicException('La reserva de códigos importados requiere una transacción y un mínimo no negativo.');
        }
        $row = DB::selectOne(
            "INSERT INTO organization_sequences (organization_id, name, value, created_at, updated_at) VALUES (?, 'tree', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (organization_id, name) DO UPDATE SET value = GREATEST(organization_sequences.value, EXCLUDED.value), updated_at = CURRENT_TIMESTAMP RETURNING value",
            [$organizationId, $minimum],
        );

        return (int) $row->value;
    }

    private function nextValue(string $organizationId, string $name): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La reserva de código requiere una transacción.');
        }
        $row = DB::selectOne(
            'INSERT INTO organization_sequences (organization_id, name, value, created_at, updated_at) VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (organization_id, name) DO UPDATE SET value = organization_sequences.value + 1, updated_at = CURRENT_TIMESTAMP RETURNING value',
            [$organizationId, $name],
        );

        return (int) $row->value;
    }
}

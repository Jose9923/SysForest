<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use LogicException;

class OrganizationSequenceRepository
{
    /** UPSERT bloquea la fila institucional; la secuencia y el árbol se confirman en la misma transacción. */
    public function nextTreeValue(string $organizationId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La reserva de código requiere una transacción.');
        }
        $row = DB::selectOne(
            'INSERT INTO organization_sequences (organization_id, name, value, created_at, updated_at) VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (organization_id, name) DO UPDATE SET value = organization_sequences.value + 1, updated_at = CURRENT_TIMESTAMP RETURNING value',
            [$organizationId, 'tree'],
        );

        return (int) $row->value;
    }
}

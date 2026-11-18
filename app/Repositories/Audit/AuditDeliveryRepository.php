<?php

declare(strict_types=1);

namespace App\Repositories\Audit;

use Illuminate\Support\Facades\DB;
use App\Support\Identifiers\RecordIdentifier;
use InvalidArgumentException;

/** Recibos técnicos; el contenido de auditoría permanece en su registro transaccional original. */
class AuditDeliveryRepository
{
    public function acknowledge(string $source, string $id): void
    {
        if (!in_array($source, AuditSourceRepository::SOURCES, true) || !RecordIdentifier::isValid($id)) {
            throw new InvalidArgumentException('Referencia de auditoría inválida.');
        }
        DB::table('audit_deliveries')->insertOrIgnore([
            'source' => $source, 'entry_id' => $id,
            'delivered_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function lastDeliveredAt(): ?string
    {
        $value = DB::table('audit_deliveries')->max('delivered_at');

        return is_string($value) ? $value : null;
    }
}

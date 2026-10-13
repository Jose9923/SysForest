<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditSyncService;
use Illuminate\Console\Command;
use Throwable;

class AuditStatus extends Command
{
    protected $signature = 'sysforest:audit-status';

    protected $description = 'Muestra el volumen y los recibos de entrega de la auditoría sin exponer documentos privados.';

    public function handle(AuditSyncService $sync): int
    {
        try {
            $status = $sync->status();
        } catch (Throwable) {
            $this->error('No fue posible consultar el estado de entrega de la auditoría.');

            return self::FAILURE;
        }
        $rows = [
            ['Destino configurado', $status['store']],
            ['Entrega habilitada', $status['enabled'] ? 'Sí' : 'No'],
            ['Registros de origen', $status['total']],
            ['Pendientes de entrega', $status['pending']],
            ['Último recibo (UTC)', $status['last_delivered_at'] ?? 'Sin entregas registradas'],
        ];
        if ($status['enabled']) {
            $rows[] = ['Disponibilidad de MongoDB', $status['available'] ? 'Disponible' : 'No disponible'];
            $rows[] = ['Registros canónicos en MongoDB', $status['mongo_total'] ?? 'No consultados'];
            $rows[] = ['Conteo de origen y destino', match ($status['coverage']) {
                true => 'Coinciden', false => 'No coinciden', default => 'No verificado',
            }];
        }
        $this->table(['Dato', 'Estado'], $rows);
        $this->comment('Los recibos acreditan entregas anteriores; después de restaurar MongoDB ejecuta audit-sync --replay.');
        if ($status['enabled'] && (!$status['available'] || !$status['coverage'] || $status['pending'] > 0)) {
            $this->warn('La entrega de auditoría requiere atención; los registros de origen se conservan.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

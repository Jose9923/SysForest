<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditSyncService;
use Illuminate\Console\Command;

class SyncAudit extends Command
{
    protected $signature = 'sysforest:audit-sync
        {--max-batches=10 : Máximo de lotes por ejecución, entre 1 y 100000}
        {--replay : Recorre también los registros entregados para reconstruir MongoDB sin borrar historial}';

    protected $description = 'Entrega los eventos de auditoría pendientes a MongoDB con reintentos idempotentes.';

    public function handle(AuditSyncService $sync): int
    {
        $maxBatches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($maxBatches === false) {
            $this->error('Indica --max-batches entre 1 y 100000.');

            return self::INVALID;
        }
        $result = $sync->run($maxBatches, (bool) $this->option('replay'));
        if (!$result['enabled']) {
            $this->warn('La entrega a MongoDB está deshabilitada; la auditoría permanece en PostgreSQL.');

            return self::SUCCESS;
        }
        $this->info('Registros entregados o ya verificados en MongoDB: '.$result['processed']);
        if ($result['failed']) {
            $this->error('No se completó la entrega. Los registros de origen se conservaron y se reintentarán. Revisa la disponibilidad y configuración del almacenamiento.');

            return self::FAILURE;
        }
        $this->info('Registros sin recibo de entrega: '.$result['pending']);
        if (!$result['complete']) {
            $this->warn($result['replay']
                ? 'Reconstrucción parcial: repite --replay con un --max-batches mayor para recorrer todo el historial.'
                : 'Se alcanzó el límite de lotes; la siguiente ejecución continuará con los pendientes.');

            return $result['replay'] ? self::FAILURE : self::SUCCESS;
        }

        return self::SUCCESS;
    }
}

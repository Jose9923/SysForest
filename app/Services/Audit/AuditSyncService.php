<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Repositories\Audit\AuditDeliveryRepository;
use App\Repositories\Audit\AuditSourceRepository;
use App\Repositories\Audit\MongoAuditRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Entrega posterior al commit: un fallo remoto nunca elimina la fuente ni confirma un recibo. */
class AuditSyncService
{
    public function __construct(
        private AuditSourceRepository $source,
        private MongoAuditRepository $mongo,
        private AuditDeliveryRepository $deliveries,
    ) {
    }

    /** @return array{enabled: bool, replay: bool, processed: int, pending: ?int, complete: bool, failed: bool} */
    public function run(int $maxBatches = 10, bool $replay = false): array
    {
        $result = ['enabled' => $this->enabled(), 'replay' => $replay, 'processed' => 0,
            'pending' => null, 'complete' => false, 'failed' => false];
        if (!$result['enabled']) {
            return $result;
        }
        $batchSize = max(1, min(1000, (int) config('audit.batch_size', 500)));
        $maxBatches = max(1, min(100000, $maxBatches));
        $cursor = null;
        try {
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $records = $replay ? $this->source->replayBatch($batchSize, $cursor) : $this->source->pending($batchSize);
                if ($records->isEmpty()) {
                    $result['complete'] = true;
                    break;
                }
                foreach ($records as $record) {
                    // No se mantiene una transacción PostgreSQL mientras se espera a MongoDB.
                    $this->mongo->store($record);
                    $this->deliveries->acknowledge($record->source, $record->id);
                    $result['processed']++;
                    $cursor = ['occurred_at' => $record->occurred_at, 'source' => $record->source, 'id' => $record->id];
                }
            }
            $result['pending'] = $this->source->unsyncedCount();
            $result['complete'] = $replay
                ? $result['pending'] === 0 && $this->source->replayBatch(1, $cursor)->isEmpty()
                : $result['pending'] === 0;
        } catch (Throwable) {
            // Las excepciones del cliente pueden contener URI, credenciales o documentos privados.
            Log::warning('La entrega de auditoría a MongoDB está pendiente; se conservaron los registros de origen.', [
                'processed' => $result['processed'], 'replay' => $replay,
            ]);
            $result['failed'] = true;
            $result['complete'] = false;
        }

        return $result;
    }

    public function status(): array
    {
        $status = ['store' => config('audit.store', 'postgres'), 'enabled' => $this->enabled(),
            'total' => $this->source->totalCount(), 'pending' => $this->source->unsyncedCount(),
            'last_delivered_at' => $this->deliveries->lastDeliveredAt(),
            'available' => null, 'mongo_total' => null, 'coverage' => null];
        if ($status['enabled']) {
            try {
                $status['mongo_total'] = $this->mongo->totalCount();
                $status['available'] = true;
                $status['coverage'] = $status['mongo_total'] === $status['total'];
            } catch (Throwable) {
                // Un diagnóstico no revela URI, credenciales ni detalles de documentos remotos.
                $status['available'] = false;
            }
        }

        return $status;
    }

    private function enabled(): bool
    {
        return config('audit.store', 'postgres') === 'mongodb';
    }
}

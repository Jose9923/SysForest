<?php

declare(strict_types=1);

namespace App\Console\Commands\Evidences;

use App\Services\Evidences\EvidenceRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** Herramienta operativa explícita; no es una política de retención ni un recolector automático. */
class ReconcileEvidenceUpload extends Command
{
    protected $signature = 'evidences:reconcile-upload {operation : UUID exacto del intento pendiente}';
    protected $description = 'Reconcilia un intento de carga, conservando cualquier archivo referenciado incluso si está anulado.';

    public function handle(EvidenceRecoveryService $recovery): int
    {
        $id = (string) $this->argument('operation');
        if (!Str::isUuid($id)) {
            $this->error('Indica un UUID de operación válido.');

            return self::INVALID;
        }
        try {
            $changed = $recovery->recover(strtolower($id));
            $this->info($changed ? 'Intento reconciliado con la base de datos.' : 'No hay un intento pendiente con ese UUID.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No fue posible reconciliar el intento. Se conserva para una revisión posterior.');

            return self::FAILURE;
        }
    }
}

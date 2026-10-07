<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MobilePhotoReceipt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** El servicio toma el lock de organización antes de consultar o confirmar una clave. */
class MobilePhotoRepository
{
    public function receipt(string $organizationId, string $clientId): ?MobilePhotoReceipt
    {
        $this->requireTransaction();

        return MobilePhotoReceipt::query()->where('organization_id', $organizationId)
            ->where('client_id', $clientId)->first();
    }

    public function confirm(array $attributes): void
    {
        $this->requireTransaction();
        $receipt = new MobilePhotoReceipt();
        $receipt->forceFill($attributes)->save();
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('El recibo móvil requiere la transacción de carga.');
        }
    }
}

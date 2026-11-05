<?php

declare(strict_types=1);

namespace App\Repositories\Mobile;

use App\Models\Mobile\MobileInventoryReceipt;
use Illuminate\Support\Facades\DB;
use LogicException;

class MobileInventoryRepository
{
    public function receipt(string $organizationId, string $operationId): ?MobileInventoryReceipt
    {
        return MobileInventoryReceipt::query()->where('organization_id', $organizationId)
            ->where('operation_id', $operationId)->first();
    }

    public function confirm(array $attributes): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La campaña y su recibo deben confirmarse en una transacción.');
        }
        $receipt = new MobileInventoryReceipt();
        $receipt->forceFill($attributes)->save();
    }
}

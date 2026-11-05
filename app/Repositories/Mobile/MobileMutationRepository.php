<?php

declare(strict_types=1);

namespace App\Repositories\Mobile;

use App\Models\Mobile\MobileMutationReceipt;

class MobileMutationRepository
{
    public function receipt(string $organizationId, string $operationId): ?MobileMutationReceipt
    {
        return MobileMutationReceipt::query()->where('organization_id', $organizationId)
            ->where('operation_id', $operationId)->first();
    }

    public function confirm(array $attributes): void
    {
        $receipt = new MobileMutationReceipt();
        $receipt->forceFill($attributes)->save();
    }
}

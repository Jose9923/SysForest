<?php

declare(strict_types=1);

namespace App\Validation\Mobile;

use App\Validation\Maintenances\MaintenanceInput;

final class MobileMaintenanceInput
{
    public static function rules(): array
    {
        return array_intersect_key(MaintenanceInput::filters(), array_flip([
            'q', 'tree_id', 'maintenance_type_id', 'status', 'priority', 'date_from', 'date_to',
        ])) + ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}

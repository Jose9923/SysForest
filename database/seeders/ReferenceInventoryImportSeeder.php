<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Imports\ReferenceInventoryImportService;
use Illuminate\Database\Seeder;

/** Confirma la carga local autorizada después de preparar las cuentas y los catálogos. */
class ReferenceInventoryImportSeeder extends Seeder
{
    public function run(ReferenceInventoryImportService $service): void
    {
        $result = $service->run(function (array $progress): void {
            $this->command?->line('Inventario de referencia '.$progress['phase'].' '.$progress['chunk'].'/'.$progress['total_chunks']);
        });
        $this->command?->info('Inventario de referencia: '.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

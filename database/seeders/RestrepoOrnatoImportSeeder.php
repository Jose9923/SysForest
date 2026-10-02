<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\RestrepoOrnatoImportService;
use Illuminate\Database\Seeder;

/** Confirma la carga local autorizada después de preparar las cuentas y los catálogos. */
class RestrepoOrnatoImportSeeder extends Seeder
{
    public function run(RestrepoOrnatoImportService $service): void
    {
        $result = $service->run(function (array $progress): void {
            $this->command?->line('Ornato '.$progress['phase'].' '.$progress['chunk'].'/'.$progress['total_chunks']);
        });
        $this->command?->info('Ornato: '.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

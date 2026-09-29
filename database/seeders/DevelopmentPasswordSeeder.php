<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\DevelopmentPasswordService;
use Illuminate\Database\Seeder;

/** Restablecimiento explícito de claves locales, sin sembrar organizaciones ni sincronizar roles. */
class DevelopmentPasswordSeeder extends Seeder
{
    public function run(DevelopmentPasswordService $service): void
    {
        $result = $service->resetAll();
        $this->command?->info('Contraseñas locales actualizadas: '.$result['updated'].'. Sin cambios: '.$result['unchanged'].'.');
    }
}

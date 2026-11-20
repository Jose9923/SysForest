<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Instalación base explícita, sin cuentas piloto, inventarios ni importaciones históricas. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BaseSeeder::class);
    }
}

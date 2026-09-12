<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\InventoryRepository;
use App\Repositories\OrganizationRepository;
use Illuminate\Database\Seeder;

class PilotInventorySeeder extends Seeder
{
    public function run(): void
    {
        $organization = app(OrganizationRepository::class)->findByCode('INEM-VVC');
        app(InventoryRepository::class)->seedPilot($organization->id, [
            'code' => 'PILOT-SYSFOREST',
            'name' => 'Inventario Forestal Institucional - Piloto SysForest',
            'description' => 'Inventario institucional piloto de SYSFOREST.',
            'starts_at' => null, 'ends_at' => null, 'status' => 'active', 'active' => true,
        ]);
    }
}

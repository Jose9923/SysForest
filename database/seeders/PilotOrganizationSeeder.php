<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Organizations\PilotSeedService;
use Illuminate\Database\Seeder;

class PilotOrganizationSeeder extends Seeder
{
    public function run(PilotSeedService $service): void
    {
        $service->seedOrganization();
    }
}

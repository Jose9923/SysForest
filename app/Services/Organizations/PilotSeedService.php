<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Territories\Municipality;
use App\Models\Organizations\Organization;
use App\Repositories\Organizations\OrganizationRepository;
use Illuminate\Support\Facades\DB;
use App\Services\Territories\TerritorySeedService;

class PilotSeedService
{
    public function __construct(
        private TerritorySeedService $territories,
        private OrganizationRepository $organizations,
    ) {
    }

    /** La preparación del piloto respeta los territorios existentes, incluidos los desactivados. */
    public function seedTerritories(): Municipality
    {
        return $this->territories->seedPilotTerritories();
    }

    public function seedOrganization(): Organization
    {
        return DB::transaction(function (): Organization {
            $municipality = $this->seedTerritories();

            return $this->organizations->upsertPilot([
                'municipality_id' => $municipality->id,
                'type' => 'school',
                'name' => 'Institucion Educativa INEM Luis Lopez de Mesa',
                'short_name' => 'INEM Villavicencio',
                'code' => 'INEM-VVC',
                'tree_code_prefix' => 'COL-MET-VVC-INEM',
                'active' => true,
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Municipality;
use App\Models\Organization;
use App\Repositories\CountryRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\MunicipalityRepository;
use App\Repositories\OrganizationRepository;
use Illuminate\Support\Facades\DB;

class PilotSeedService
{
    public function __construct(
        private CountryRepository $countries,
        private DepartmentRepository $departments,
        private MunicipalityRepository $municipalities,
        private OrganizationRepository $organizations,
    ) {
    }

    /** Códigos UNSD/DANE confirmados en docs/phase-3.md; no se inventan límites territoriales. */
    public function seedTerritories(): Municipality
    {
        return DB::transaction(function (): Municipality {
            $country = $this->countries->upsert('170', ['name' => 'Colombia', 'iso_alpha_2' => 'CO', 'iso_alpha_3' => 'COL', 'active' => true]);
            $department = $this->departments->upsert($country->id, '50', ['name' => 'Meta', 'active' => true]);

            return $this->municipalities->upsert($department->id, '50001', ['name' => 'Villavicencio', 'active' => true]);
        });
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

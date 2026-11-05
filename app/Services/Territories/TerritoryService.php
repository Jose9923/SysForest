<?php

declare(strict_types=1);

namespace App\Services\Territories;

use App\Models\Territories\Municipality;
use App\Models\User;
use App\Repositories\Territories\CountryRepository;
use App\Repositories\Territories\MunicipalityRepository;
use App\Repositories\Memberships\OrganizationMembershipRepository;
use App\Repositories\Auth\RoleAssignmentRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Services\Auth\PermissionService;

class TerritoryService
{
    public function __construct(
        private CountryRepository $countries,
        private MunicipalityRepository $municipalities,
        private PermissionService $permissions,
        private RoleAssignmentRepository $assignments,
        private OrganizationMembershipRepository $memberships,
    ) {
    }

    /** Conservada para seeders y pruebas internas; las rutas usan overviewForActor. */
    public function overview(): Collection
    {
        return $this->countries->overview();
    }

    public function overviewForActor(User $actor): Collection
    {
        if ($this->permissions->allowsNational($actor, 'territories.view')) {
            return $this->countries->overview();
        }
        $departmentIds = $this->assignments->departmentsForUser((int) $actor->id)
            ->filter(fn ($assignment): bool => $this->permissions->allowsDepartment($actor, 'territories.view', $assignment->department_id))
            ->pluck('department_id')->unique()->values()->all();
        $municipalityIds = $this->memberships->forUser((int) $actor->id)
            ->filter(fn ($membership): bool => $membership->organization !== null
                && $this->permissions->allowsOrganization($actor, 'territories.view', $membership->organization_id))
            ->map(fn ($membership): string => $membership->organization->municipality_id)->unique()->values()->all();
        if ($departmentIds === [] && $municipalityIds === []) {
            throw new AuthorizationException('No tienes un alcance territorial autorizado.');
        }

        return $this->countries->overviewForScopes($departmentIds, $municipalityIds);
    }

    public function municipalityOptions(): Collection
    {
        return $this->municipalities->activeOptions();
    }

    public function requireActiveMunicipality(string $id): Municipality
    {
        $municipality = $this->municipalities->find($id);
        if (!$municipality->active || !$municipality->department->active || !$municipality->department->country->active) {
            throw ValidationException::withMessages(['municipality_id' => 'El municipio y su jerarquía territorial deben estar activos.']);
        }

        return $municipality;
    }

    public function containingPoint(float $longitude, float $latitude): Collection
    {
        if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90 || !is_finite($longitude) || !is_finite($latitude)) {
            throw ValidationException::withMessages(['coordinates' => 'Las coordenadas deben ser válidas en EPSG:4326.']);
        }

        return $this->municipalities->containingPoint($longitude, $latitude);
    }

    public function storeMunicipalityGeometry(User $actor, string $id, string $wkt): void
    {
        if (!$this->permissions->allowsNational($actor, 'territories.manage')) {
            throw new AuthorizationException('La modificación de límites requiere autorización nacional.');
        }
        Gate::forUser($actor)->authorize('territories.manage');
        DB::transaction(fn () => $this->municipalities->storeGeometry($this->municipalities->find($id), $wkt));
    }
}

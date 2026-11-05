<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\DTOs\Maps\MapTreeFilterDTO;
use App\Models\User;
use App\Repositories\Maps\MapOptionRepository;
use App\Validation\Maps\MapInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Trees\TreeFilterService;

/** Autoriza primero el alcance y reutiliza la validación forestal común para todos los consumidores. */
class MapFilterService
{
    public function __construct(
        private MapAccessService $access,
        private TreeFilterService $trees,
        private MapOptionRepository $options,
    ) {
    }

    public function context(User $actor, array $input, bool $requireBbox = false): array
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $input);
        $spatial = Validator::make($input, MapInput::rules($requireBbox), MapInput::messages())->validate();
        $scope = $this->access->resolve($actor, $spatial['scope'] ?? null);
        $forest = $this->trees->validate($input);
        if ($forest->hasInspectionFilters()) {
            $this->access->authorize($actor, $scope, 'inspections.view');
        }
        if ($forest->hasMaintenanceFilters()) {
            $this->access->authorize($actor, $scope, 'maintenances.view');
        }
        $values = $forest->values();
        foreach (['department_id', 'municipality_id', 'organization_id', 'inventory_id'] as $field) {
            if (!empty($values[$field])) {
                abort_unless($this->options->referenceExists(MapOptionRepository::FIELDS[$field], $scope, $values, $values[$field]), 404, 'La referencia no está disponible en este alcance.');
            }
        }
        $bbox = null;
        if (isset($spatial['west'])) {
            if ((float) $spatial['west'] >= (float) $spatial['east'] || (float) $spatial['south'] >= (float) $spatial['north']) {
                throw ValidationException::withMessages(['bbox' => 'El encuadre debe tener oeste menor que este y sur menor que norte.']);
            }
            $bbox = array_map('floatval', array_intersect_key($spatial, array_flip(['west', 'south', 'east', 'north'])));
        }
        $radius = isset($spatial['radius_meters']) ? ['latitude' => (float) $spatial['radius_latitude'],
            'longitude' => (float) $spatial['radius_longitude'], 'meters' => (float) $spatial['radius_meters']] : null;

        return ['scope' => $scope, 'filters' => new MapTreeFilterDTO($forest, $bbox, (int) ($spatial['zoom'] ?? 8), $radius)];
    }
}

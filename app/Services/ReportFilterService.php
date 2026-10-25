<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\MapTreeFilterDTO;
use App\DTOs\ReportSelectionDTO;
use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Validation\MapInput;
use App\Validation\TreeFilterInput;
use Illuminate\Validation\ValidationException;

class ReportFilterService
{
    /** El encuadre y la paginación no reducen el universo de tablas y reportes. */
    private const CONTROLS = ['report', 'format', 'page', 'render_mode', 'term', 'zoom', 'bbox', 'west', 'south', 'east', 'north'];

    public function __construct(
        private MapFilterService $maps,
        private PublicTreeFilterService $public,
        private ReportAccessService $access,
        private ReportCatalog $catalog,
    ) {
    }

    public function forUser(User $actor, array $input, string $report = 'inventory', bool $export = false): ReportSelectionDTO
    {
        $input = $this->input($input);
        $allowed = array_filter(array_keys(TreeFilterInput::rules() + MapInput::rules(false)), fn (string $key) => !str_contains($key, '.'));
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Este filtro no está disponible en los reportes.'));
        }
        $context = $this->maps->context($actor, $input);
        $map = $context['filters'];
        $values = $this->clean($map->trees->values());
        $values['scope'] = $context['scope']->kind;
        $selection = new ReportSelectionDTO($context['scope'], $this->filters($map), $this->radiusValues($values, $map));
        $this->access->authorize($actor, $selection, $report, $export);

        return $selection;
    }

    public function forPublic(array $input, string $report = 'inventory'): ReportSelectionDTO
    {
        if (in_array($report, ['risk', 'carbon', 'carbon_coverage'], true)) {
            throw ValidationException::withMessages(['report' => 'Este reporte es exclusivamente privado.']);
        }
        $definition = $this->catalog->get($report);
        if (!$definition['available']) {
            throw ValidationException::withMessages(['report' => $definition['notice']]);
        }
        $public = $this->public->validate($this->input($input));

        return new ReportSelectionDTO(TreeReadScope::published(), $this->filters($public->map), $this->clean($public->publicValues));
    }

    private function filters(MapTreeFilterDTO $filters): MapTreeFilterDTO
    {
        return new MapTreeFilterDTO(TreeFilterDTO::fromValidated($this->clean($filters->trees->values())), null, 8, $filters->radius);
    }

    private function input(array $input): array
    {
        // Un reporte operativo no presenta anulados ni admite banderas internas de autorización.
        foreach (['trashed', 'include_deleted', 'include_voided_trees', 'tree_id'] as $field) {
            if (array_key_exists($field, $input)) {
                throw ValidationException::withMessages([$field => 'Este filtro no está disponible en los reportes.']);
            }
        }

        return array_diff_key($input, array_flip(self::CONTROLS));
    }

    private function clean(array $values): array
    {
        return array_filter(array_diff_key($values, array_flip(self::CONTROLS)), fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function radiusValues(array $values, MapTreeFilterDTO $filters): array
    {
        if ($filters->radius !== null) {
            $values['radius_latitude'] = $filters->radius['latitude'];
            $values['radius_longitude'] = $filters->radius['longitude'];
            $values['radius_meters'] = $filters->radius['meters'];
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\DTOs\Maps\MapTreeFilterDTO;
use App\DTOs\Reports\ReportSelectionDTO;
use App\DTOs\Trees\TreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Validation\Maps\MapInput;
use App\Validation\Trees\TreeFilterInput;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use App\Repositories\Inspections\InspectionConditionRepository;
use App\Services\Maps\MapFilterService;
use App\Services\PublicPortal\PublicTreeFilterService;

class ReportFilterService
{
    /** El encuadre y la paginación no reducen el universo de tablas y reportes. */
    private const CONTROLS = ['report', 'format', 'page', 'render_mode', 'term', 'zoom', 'bbox', 'west', 'south', 'east', 'north'];

    public function __construct(
        private MapFilterService $maps,
        private PublicTreeFilterService $public,
        private ReportAccessService $access,
        private ReportCatalog $catalog,
        private InspectionConditionRepository $conditions,
    ) {
    }

    public function forUser(User $actor, array $input, string $report = 'inventory', bool $export = false): ReportSelectionDTO
    {
        $selection = $this->privateSelection($actor, $input);
        $this->access->authorize($actor, $selection, $report, $export);

        return $selection;
    }

    /** La bandeja conserva su permiso de consulta; no concede acceso a reportes ni descargas. */
    public function forRiskInbox(User $actor, array $input): ReportSelectionDTO
    {
        $this->rejectConditionCategories($input);
        $selection = $this->privateSelection($actor, array_replace($input, ['scope' => 'organization']));
        $this->access->authorizeRiskInbox($actor, $selection);

        return $selection;
    }

    private function privateSelection(User $actor, array $input): ReportSelectionDTO
    {
        $input = $this->input($input);
        $categoryInput = array_intersect_key($input, ReportSelectionDTO::CONDITION_CATEGORY_FIELDS);
        $input = array_diff_key($input, ReportSelectionDTO::CONDITION_CATEGORY_FIELDS);
        $categoryValues = $this->conditionCategories($categoryInput);
        $allowed = array_filter(array_keys(TreeFilterInput::rules() + MapInput::rules(false)), fn (string $key) => !str_contains($key, '.'));
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Este filtro no está disponible en los reportes.'));
        }
        $context = $this->maps->context($actor, $input);
        $map = $context['filters'];
        $this->validateCategoryConditions($categoryValues, $map->trees);
        $values = $this->clean($map->trees->values() + $categoryValues);
        $values['scope'] = $context['scope']->kind;
        return new ReportSelectionDTO($context['scope'], $this->filters($map), $this->radiusValues($values, $map));
    }

    public function forPublic(array $input, string $report = 'inventory'): ReportSelectionDTO
    {
        $this->rejectConditionCategories($input);
        if (in_array($report, ['risk', 'risk_trees', 'carbon', 'carbon_coverage'], true)) {
            throw ValidationException::withMessages(['report' => 'Este reporte es exclusivamente privado.']);
        }
        $definition = $this->catalog->get($report);
        if (!$definition['available']) {
            throw ValidationException::withMessages(['report' => $definition['notice']]);
        }
        $public = $this->public->validate($this->input($input));

        return new ReportSelectionDTO(TreeReadScope::published(), $this->filters($public->map), $this->clean($public->publicValues));
    }

    private function conditionCategories(array $input): array
    {
        $rules = [];
        foreach (ReportSelectionDTO::CONDITION_CATEGORY_FIELDS as $field => $type) {
            $rules[$field] = ['nullable', 'array', 'max:100'];
            $rules[$field.'.*'] = ['required', 'uuid', 'distinct:strict'];
        }
        $input = array_map(fn ($value) => $value === '' ? null : $value, $input);
        $values = Validator::make($input, $rules, TreeFilterInput::messages())->validate();
        foreach (ReportSelectionDTO::CONDITION_CATEGORY_FIELDS as $field => $type) {
            if (!$this->conditions->validateCategoriesForFilter($values[$field] ?? [], $type)) {
                throw ValidationException::withMessages([$field => 'Selecciona órganos existentes de la evaluación correspondiente.']);
            }
        }

        return $this->clean($values);
    }

    private function validateCategoryConditions(array $categories, TreeFilterDTO $trees): void
    {
        foreach (ReportSelectionDTO::CONDITION_CATEGORY_FIELDS as $field => $type) {
            $ids = $categories[$field] ?? [];
            $conditionField = $type === 'physical_mechanical' ? 'physical_condition_ids' : 'phytosanitary_condition_ids';
            $conditionIds = $trees->get($conditionField, []);
            if ($ids !== [] && $conditionIds !== []
                && !$this->conditions->conditionsBelongToCategories($conditionIds, $ids, $type)) {
                throw ValidationException::withMessages([$conditionField => 'Las condiciones seleccionadas deben pertenecer a los órganos elegidos.']);
            }
        }
    }

    private function rejectConditionCategories(array $input): void
    {
        $keys = array_intersect_key($input, ReportSelectionDTO::CONDITION_CATEGORY_FIELDS);
        if ($keys !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($keys), 'La selección de órganos para las series solo está disponible en gráficas y reportes privados.'));
        }
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

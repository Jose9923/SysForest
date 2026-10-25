<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\MapTreeFilterDTO;
use App\DTOs\PublicTreeFilterDTO;
use App\DTOs\TreeFilterDTO;
use App\Repositories\MapOptionRepository;
use App\Repositories\PublicReferenceRepository;
use App\Validation\MapInput;
use App\Validation\TreeFilterInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** La entrada anónima restringe campos; las reglas forestales siguen siendo las del motor común. */
class PublicTreeFilterService
{
    public const SORTS = ['code', 'common_name', 'scientific_name', 'municipality', 'latest_inspection_date'];
    private const EXCLUDED = ['risk_priority', 'scope', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id',
        'maintenance_priority', 'has_pending_maintenance'];

    public function __construct(private TreeFilterService $trees, private PublicReferenceRepository $references)
    {
    }

    public static function keys(): array
    {
        return array_values(array_filter(array_keys(self::rules(false)), fn (string $key) => !str_contains($key, '.')));
    }

    /** Claves persistibles del formulario; navegación y encuadre se administran por separado. */
    public static function publicFilterKeys(): array
    {
        return array_values(array_diff(self::keys(), ['sort', 'direction', 'page', 'term', 'zoom',
            'west', 'south', 'east', 'north']));
    }

    public function validate(array $input, bool $requireBbox = false): PublicTreeFilterDTO
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $input);
        $unknown = array_diff(array_keys($input), self::keys());
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Este filtro no está disponible en el portal público.'));
        }
        $values = Validator::make($input, self::rules($requireBbox), TreeFilterInput::messages() + MapInput::messages())->validate();
        $resolved = $this->references->resolve($values);
        $sort = $resolved['sort'] ?? 'code';
        unset($resolved['sort']);
        $trees = TreeFilterDTO::fromValidated($this->trees->validate($resolved)->values() + ['sort' => $sort]);
        $bbox = null;
        if (isset($values['west'])) {
            if ((float) $values['west'] >= (float) $values['east'] || (float) $values['south'] >= (float) $values['north']) {
                throw ValidationException::withMessages(['bbox' => 'El encuadre requiere oeste menor que este y sur menor que norte.']);
            }
            $bbox = array_map('floatval', array_intersect_key($values, array_flip(['west', 'south', 'east', 'north'])));
        }
        $radius = isset($values['radius_meters']) ? ['latitude' => (float) $values['radius_latitude'],
            'longitude' => (float) $values['radius_longitude'], 'meters' => (float) $values['radius_meters']] : null;

        return new PublicTreeFilterDTO($trees, $values, new MapTreeFilterDTO($trees, $bbox, (int) ($values['zoom'] ?? 8), $radius));
    }

    private static function rules(bool $requireBbox): array
    {
        $rules = array_diff_key(TreeFilterInput::rules() + MapInput::rules($requireBbox), array_flip(self::EXCLUDED));
        foreach (MapOptionRepository::FIELDS as $field => $kind) {
            if (str_ends_with($field, '_ids')) {
                $rules[$field.'.*'] = ['required', 'string', 'max:200', 'distinct:strict'];
            } else {
                $rules[$field] = ['nullable', 'string', 'max:200'];
            }
        }
        $rules['sort'] = ['nullable', Rule::in(self::SORTS)];
        $rules['page'] = ['nullable', 'integer', 'min:1', 'max:1000'];
        $rules['term'] = ['nullable', 'string', 'max:150'];
        $rules['maintenance_status'] = ['nullable', Rule::in(['executed', 'verified'])];

        return $rules;
    }
}

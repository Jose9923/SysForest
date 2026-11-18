<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Repositories\Identifiers\IdentifierRepository;
use App\Support\Identifiers\IdentifierSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

/** Frontera UUID pública. Nunca convierte recibos ni solicitudes ya confirmadas. */
final class MobileIdentifiers
{
    private const REFERENCES = [
        'organization_id' => 'organizations', 'inventory_id' => 'inventories',
        'inventory_tree_id' => 'inventory_trees', 'tree_id' => 'trees', 'mobile_tree_id' => 'trees',
        'inspection_id' => 'tree_inspections', 'tree_inspection_id' => 'tree_inspections',
        'maintenance_id' => 'maintenances', 'evidence_type_id' => 'evidence_types',
        'species_id' => 'species', 'family_id' => 'families', 'placement_id' => 'placements',
        'growth_habit_id' => 'growth_habits', 'establishment_origin_id' => 'establishment_origins',
        'biogeographic_origin_id' => 'biogeographic_origins', 'condition_category_id' => 'condition_categories',
        'maturity_state_id' => 'maturity_states', 'classification_rule_version_id' => 'classification_rule_versions',
        'risk_methodology_id' => 'risk_methodologies', 'methodology_id' => 'risk_methodologies',
        'maintenance_type_id' => 'maintenance_types', 'deletion_reason_id' => 'deletion_reasons',
    ];

    private const LISTS = [
        'physical_condition_ids' => 'conditions', 'phytosanitary_condition_ids' => 'conditions',
        'fauna_category_ids' => 'fauna_categories', 'inventory_ids' => 'inventories',
    ];

    public static function id(?Model $model): string|int|null
    {
        return $model === null ? null : app(IdentifierRepository::class)->publicId($model);
    }

    public static function external(string $table, string|int|null $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return Str::isUuid((string) $id) ? strtolower((string) $id) : app(IdentifierRepository::class)->external($table, $id);
    }

    public static function internal(string $table, string|int $id): string
    {
        return app(IdentifierRepository::class)->internal($table, $id);
    }

    /** Solo campos conocidos del dominio, no sustituciones recursivas en textos/snapshots. */
    public static function input(array $input): array
    {
        foreach (self::REFERENCES as $field => $table) {
            if (isset($input[$field])) {
                try {
                    $input[$field] = self::internal($table, $input[$field]);
                } catch (ModelNotFoundException) {
                    throw ValidationException::withMessages([$field => 'La referencia seleccionada no existe.']);
                }
            }
        }
        foreach (self::LISTS as $field => $table) {
            if (isset($input[$field])) {
                $publicIds = array_map(fn ($id): string => strtolower((string) $id), $input[$field]);
                $resolved = app(IdentifierRepository::class)->resolveMany($table, $publicIds);
                if (array_diff($publicIds, array_keys($resolved)) !== []) {
                    throw ValidationException::withMessages([$field => 'Una referencia seleccionada no existe.']);
                }
                $input[$field] = array_map(fn ($id): string => (string) $resolved[$id], $publicIds);
            }
        }

        return $input;
    }

    public static function output(array $data, ?string $table = null): array
    {
        if ($table !== null && isset($data['id'])) {
            $data['id'] = self::external($table, $data['id']);
        }
        foreach (self::REFERENCES as $field => $target) {
            if (isset($data[$field])) {
                $data[$field] = self::external($target, $data[$field]);
            }
        }

        return $data;
    }

    /** Las reglas compartidas validan PK internas; la API v1 sigue exigiendo UUID. */
    public static function publicRules(array $rules): array
    {
        foreach ($rules as $field => &$constraints) {
            $root = str_ends_with($field, '.*') ? substr($field, 0, -2) : $field;
            if (isset(self::REFERENCES[$root]) || str_ends_with($field, '.*') && isset(self::LISTS[$root])) {
                $constraints = array_map(fn ($rule) => $rule === 'record_id' ? 'uuid' : $rule, $constraints);
            }
        }
        unset($constraints);

        return $rules;
    }

    /** Precarga aliases de una página y de sus relaciones ya cargadas, sin lazy loading. */
    public static function prime(iterable $models): void
    {
        $ids = [];
        $seen = [];
        $collect = function (Model $model) use (&$collect, &$ids, &$seen): void {
            $key = spl_object_id($model);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            if ($model->getKey() !== null && in_array($model->getTable(), IdentifierSchema::TABLES, true)) {
                $ids[$model->getTable()][] = $model->getKey();
            }
            foreach (self::REFERENCES as $field => $table) {
                if (array_key_exists($field, $model->getAttributes()) && $model->getAttribute($field) !== null) {
                    $ids[$table][] = $model->getAttribute($field);
                }
            }
            foreach ($model->getRelations() as $relation) {
                foreach ($relation instanceof Model ? [$relation] : (is_iterable($relation) ? $relation : []) as $related) {
                    if ($related instanceof Model) {
                        $collect($related);
                    }
                }
            }
        };
        foreach ($models as $model) {
            $collect($model);
        }
        foreach ($ids as $table => $values) {
            app(IdentifierRepository::class)->prime($table, array_values(array_unique($values)));
        }
    }
}

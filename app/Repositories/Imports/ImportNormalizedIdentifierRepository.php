<?php

declare(strict_types=1);

namespace App\Repositories\Imports;

use App\Repositories\Identifiers\IdentifierRepository;
use App\Support\Identifiers\RecordIdentifier;
use Illuminate\Support\Str;

/** Compara vistas previas antiguas sin reescribir su JSON ni reinterpretar texto libre. */
class ImportNormalizedIdentifierRepository
{
    private const FIELDS = [
        'tree' => [
            'placement_id' => 'placements',
            'species_id' => 'species',
            'growth_habit_id' => 'growth_habits',
            'establishment_origin_id' => 'establishment_origins',
            'biogeographic_origin_id' => 'biogeographic_origins',
        ],
        'calculations' => [
            'maturity_state_id' => 'maturity_states',
            'classification_rule_version_id' => 'classification_rule_versions',
        ],
    ];

    private const LISTS = [
        'tree' => ['inventory_ids' => 'inventories'],
        'inspection' => [
            'physical_condition_ids' => 'conditions',
            'phytosanitary_condition_ids' => 'conditions',
            'fauna_category_ids' => 'fauna_categories',
        ],
    ];

    public function __construct(private IdentifierRepository $identifiers)
    {
    }

    /** Copia exclusivamente para comparar; nunca se utiliza como entrada de escritura. */
    public function forComparison(array $values): array
    {
        foreach (self::FIELDS as $section => $fields) {
            if (!is_array($values[$section] ?? null)) {
                continue;
            }
            foreach ($fields as $field => $table) {
                if (array_key_exists($field, $values[$section])) {
                    $values[$section][$field] = $this->reference($table, $values[$section][$field]);
                }
            }
        }
        foreach (self::LISTS as $section => $fields) {
            foreach ($fields as $field => $table) {
                if (!is_array($values[$section][$field] ?? null) || !array_is_list($values[$section][$field])) {
                    continue;
                }
                $values[$section][$field] = array_map(fn (mixed $id): mixed => $this->reference($table, $id), $values[$section][$field]);
            }
        }

        return $values;
    }

    private function reference(string $table, mixed $id): mixed
    {
        if (RecordIdentifier::isValid($id)) {
            return (string) $id;
        }
        if (!is_string($id) || !Str::isUuid($id)) {
            return $id;
        }

        // Una referencia ausente conserva su valor: la revalidación vigente
        // detectará el cambio/error; no se equipara silenciosamente con null.
        return $this->identifiers->resolveMany($table, [$id])[strtolower($id)] ?? $id;
    }
}

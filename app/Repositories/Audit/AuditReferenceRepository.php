<?php

declare(strict_types=1);

namespace App\Repositories\Audit;

use App\Repositories\Identifiers\IdentifierRepository;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Traduce solo referencias tipadas de capturas nuevas; nunca reescribe snapshots históricos. */
class AuditReferenceRepository
{
    private const RESOURCES = [
        'organization' => 'organizations', 'organization_membership' => 'organization_memberships',
        'national_role_assignment' => 'national_role_assignments',
        'department_role_assignment' => 'department_role_assignments',
        'organization_role_assignment' => 'organization_role_assignments', 'invitation' => 'invitations',
        'inventory' => 'inventories', 'inventory_tree' => 'inventory_trees', 'tree' => 'trees',
        'tree_inspection' => 'tree_inspections', 'evidence' => 'evidences', 'maintenance' => 'maintenances',
        'import_batch' => 'import_batches', 'risk_methodology' => 'risk_methodologies',
        'carbon_refresh_run' => 'carbon_refresh_runs', 'security_event' => 'security_events',
    ];

    private const CATALOGS = [
        'placements' => 'placements', 'establishment-origins' => 'establishment_origins',
        'biogeographic-origins' => 'biogeographic_origins', 'growth-habits' => 'growth_habits',
        'maturity-states' => 'maturity_states', 'evidence-types' => 'evidence_types',
        'maintenance-types' => 'maintenance_types', 'deletion-reasons' => 'deletion_reasons',
        'fauna-categories' => 'fauna_categories', 'location-change-types' => 'location_change_types',
        'classification-rules' => 'classification_rule_versions', 'taxonomy.families' => 'families',
        'taxonomy.species' => 'species', 'taxonomy.common-names' => 'species_common_names',
        'conditions.categories' => 'condition_categories', 'conditions.items' => 'conditions',
    ];

    private const FIELDS = [
        'organization' => ['municipality_id' => 'municipalities'],
        'national_role_assignment' => ['role_id' => 'roles'],
        'department_role_assignment' => ['department_id' => 'departments', 'role_id' => 'roles'],
        'organization_role_assignment' => ['membership_id' => 'organization_memberships', 'role_id' => 'roles'],
        'invitation' => ['role_id' => 'roles'],
        'inventory_tree' => ['inventory_id' => 'inventories', 'tree_id' => 'trees'],
        'tree' => ['placement_id' => 'placements', 'species_id' => 'species', 'growth_habit_id' => 'growth_habits',
            'establishment_origin_id' => 'establishment_origins', 'biogeographic_origin_id' => 'biogeographic_origins',
            'deletion_reason_id' => 'deletion_reasons'],
        'evidence' => ['tree_id' => 'trees', 'tree_inspection_id' => 'tree_inspections',
            'maintenance_id' => 'maintenances', 'evidence_type_id' => 'evidence_types'],
        'maintenance' => ['tree_id' => 'trees', 'maintenance_type_id' => 'maintenance_types'],
        'import_batch' => ['inventory_id' => 'inventories'],
        'catalog' => ['family_id' => 'families', 'species_id' => 'species', 'condition_category_id' => 'condition_categories'],
    ];

    public function __construct(private IdentifierRepository $identifiers)
    {
    }

    public function resource(string $type, string $id, ?string $catalogKey = null): string
    {
        if (Str::isUuid($id) || in_array($type, ['user', 'carbon_profile'], true)) {
            return $id;
        }
        $table = $type === 'catalog' ? (self::CATALOGS[$catalogKey ?? ''] ?? null) : (self::RESOURCES[$type] ?? null);
        if ($table === null) {
            throw new InvalidArgumentException('La referencia auditada necesita su tipo o catálogo explícito.');
        }

        return $this->external($table, $id);
    }

    public function snapshot(string $type, array $values): array
    {
        $values = $this->fields($values, self::FIELDS[$type] ?? []);
        if ($type === 'tree_inspection') {
            foreach ([
                'context' => ['tree_id' => 'trees', 'inventory_tree_id' => 'inventory_trees'],
                'calculations' => ['maturity_state_id' => 'maturity_states', 'classification_rule_version_id' => 'classification_rule_versions'],
                'risk' => ['evaluation_id' => 'risk_evaluations', 'methodology_id' => 'risk_methodologies'],
            ] as $group => $fields) {
                if (is_array($values[$group] ?? null)) {
                    $values[$group] = $this->fields($values[$group], $fields);
                }
            }
            if (is_array($values['conditions'] ?? null)) {
                $values['conditions'] = $this->references('conditions', $values['conditions']);
            }
            if (is_array($values['fauna']['category_ids'] ?? null)) {
                $values['fauna']['category_ids'] = $this->references('fauna_categories', $values['fauna']['category_ids']);
            }
        }

        return $values;
    }

    public function metadata(string $event, ?string $resourceType, array $metadata): array
    {
        $metadata = $this->fields($metadata, [
            'session_id' => 'auth_sessions', 'membership_id' => 'organization_memberships',
            'invitation_id' => 'invitations', 'department_id' => 'departments',
        ]);
        if (isset($metadata['resource_id']) && $resourceType !== null) {
            $metadata['resource_id'] = $this->resource($resourceType, (string) $metadata['resource_id'], $metadata['catalog_key'] ?? null);
        }
        if (isset($metadata['revision_id'])) {
            $table = match (true) {
                $event === 'publication.changed' => 'publication_revisions',
                $event === 'tree.status_changed' => 'tree_status_histories',
                $event === 'tree.location_changed' => 'tree_location_histories',
                $resourceType === 'tree_inspection' => 'inspection_revisions',
                $resourceType === 'evidence' => 'evidence_revisions',
                $resourceType === 'maintenance' => 'maintenance_revisions',
                default => null,
            };
            if ($table === null && !Str::isUuid((string) $metadata['revision_id'])) {
                throw new InvalidArgumentException('La revisión auditada necesita un origen explícito.');
            }
            if ($table !== null) {
                $metadata['revision_id'] = $this->external($table, (string) $metadata['revision_id']);
            }
        }

        return $metadata;
    }

    private function fields(array $values, array $fields): array
    {
        foreach ($fields as $field => $table) {
            if (isset($values[$field]) && (is_string($values[$field]) || is_int($values[$field]))) {
                $values[$field] = $this->external($table, (string) $values[$field]);
            }
        }

        return $values;
    }

    private function references(string $table, array $values): array
    {
        $ids = array_values(array_filter($values, fn ($value): bool => is_string($value) || is_int($value)));
        $this->identifiers->prime($table, array_values(array_filter(array_map('strval', $ids), fn (string $id): bool => !Str::isUuid($id))));

        return array_map(fn ($id): string => $this->external($table, (string) $id), $ids);
    }

    private function external(string $table, string $id): string
    {
        return Str::isUuid($id) ? $id : $this->identifiers->external($table, $id);
    }
}

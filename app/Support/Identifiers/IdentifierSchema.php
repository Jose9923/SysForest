<?php

declare(strict_types=1);

namespace App\Support\Identifiers;

/** Identidades SQL y referencias internas; los UUID externos y de operación siguen separados. */
final class IdentifierSchema
{
    /** Tablas con id BIGINT identity y external_id UUID público estable. */
    public const TABLES = [
        'account_action_tokens',
        'auth_sessions',
        'biogeographic_origins',
        'carbon_refresh_runs',
        'classification_rule_versions',
        'condition_categories',
        'conditions',
        'countries',
        'deletion_reasons',
        'department_role_assignments',
        'departments',
        'establishment_origins',
        'evidence_revisions',
        'evidence_types',
        'evidences',
        'families',
        'fauna_categories',
        'growth_habits',
        'import_batches',
        'import_rows',
        'inspection_revisions',
        'inventories',
        'inventory_trees',
        'invitations',
        'location_change_types',
        'maintenance_revisions',
        'maintenance_status_histories',
        'maintenance_types',
        'maintenances',
        'maturity_rule_bands',
        'maturity_states',
        'mobile_inventory_receipts',
        'mobile_mutation_receipts',
        'mobile_photo_receipts',
        'mobile_tree_receipts',
        'municipalities',
        'national_role_assignments',
        'organization_memberships',
        'organization_role_assignments',
        'organization_sequences',
        'organizations',
        'permissions',
        'placements',
        'publication_revisions',
        'refresh_tokens',
        'risk_evaluations',
        'risk_methodologies',
        'roles',
        'security_events',
        'species',
        'species_common_names',
        'tree_inspections',
        'tree_location_histories',
        'tree_status_histories',
        'trees',
    ];

    /** Referencias internas simples, incluidas columnas de FKs compuestas y el cursor de carbono. */
    public const REFERENCES = [
        'auth_sessions' => [
            'organization_id' => 'organizations',
        ],
        'carbon_estimates' => [
            'inspection_id' => 'tree_inspections',
            'organization_id' => 'organizations',
            'species_id' => 'species',
        ],
        'carbon_profiles' => [
            'organization_id' => 'organizations',
            'species_id' => 'species',
        ],
        'carbon_refresh_runs' => [
            'last_inspection_id' => 'tree_inspections',
            'organization_id' => 'organizations',
        ],
        'conditions' => [
            'condition_category_id' => 'condition_categories',
        ],
        'department_role_assignments' => [
            'department_id' => 'departments',
            'role_id' => 'roles',
        ],
        'departments' => [
            'country_id' => 'countries',
        ],
        'evidence_revisions' => [
            'evidence_id' => 'evidences',
            'organization_id' => 'organizations',
        ],
        'evidences' => [
            'evidence_type_id' => 'evidence_types',
            'maintenance_id' => 'maintenances',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
            'tree_inspection_id' => 'tree_inspections',
        ],
        'import_batches' => [
            'inventory_id' => 'inventories',
            'organization_id' => 'organizations',
        ],
        'import_rows' => [
            'import_batch_id' => 'import_batches',
            'inspection_id' => 'tree_inspections',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'inspection_conditions' => [
            'condition_id' => 'conditions',
            'inspection_id' => 'tree_inspections',
            'organization_id' => 'organizations',
        ],
        'inspection_fauna' => [
            'fauna_category_id' => 'fauna_categories',
            'inspection_id' => 'tree_inspections',
            'organization_id' => 'organizations',
        ],
        'inspection_revisions' => [
            'inspection_id' => 'tree_inspections',
            'organization_id' => 'organizations',
        ],
        'inventories' => [
            'organization_id' => 'organizations',
        ],
        'inventory_trees' => [
            'inventory_id' => 'inventories',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'invitations' => [
            'organization_id' => 'organizations',
            'role_id' => 'roles',
        ],
        'maintenance_revisions' => [
            'maintenance_id' => 'maintenances',
            'organization_id' => 'organizations',
        ],
        'maintenance_status_histories' => [
            'maintenance_id' => 'maintenances',
            'organization_id' => 'organizations',
        ],
        'maintenances' => [
            'maintenance_type_id' => 'maintenance_types',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'maturity_rule_bands' => [
            'maturity_state_id' => 'maturity_states',
            'rule_version_id' => 'classification_rule_versions',
        ],
        'mobile_inventory_receipts' => [
            'inventory_id' => 'inventories',
            'organization_id' => 'organizations',
        ],
        'mobile_mutation_receipts' => [
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'mobile_photo_receipts' => [
            'evidence_id' => 'evidences',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'mobile_tree_receipts' => [
            'inventory_id' => 'inventories',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'municipalities' => [
            'department_id' => 'departments',
        ],
        'national_role_assignments' => [
            'role_id' => 'roles',
        ],
        'organization_memberships' => [
            'organization_id' => 'organizations',
        ],
        'organization_role_assignments' => [
            'membership_id' => 'organization_memberships',
            'organization_id' => 'organizations',
            'role_id' => 'roles',
        ],
        'organization_sequences' => [
            'organization_id' => 'organizations',
        ],
        'organizations' => [
            'municipality_id' => 'municipalities',
        ],
        'publication_revisions' => [
            'evidence_id' => 'evidences',
            'inventory_id' => 'inventories',
            'organization_id' => 'organizations',
            'organization_subject_id' => 'organizations',
            'tree_id' => 'trees',
            'tree_inspection_id' => 'tree_inspections',
        ],
        'refresh_tokens' => [
            'auth_session_id' => 'auth_sessions',
        ],
        'risk_condition_rules' => [
            'condition_id' => 'conditions',
            'methodology_id' => 'risk_methodologies',
        ],
        'risk_evaluations' => [
            'inspection_id' => 'tree_inspections',
            'methodology_id' => 'risk_methodologies',
            'organization_id' => 'organizations',
        ],
        'risk_methodologies' => [
            'organization_id' => 'organizations',
        ],
        'risk_settings' => [
            'methodology_id' => 'risk_methodologies',
            'organization_id' => 'organizations',
        ],
        'role_permissions' => [
            'permission_id' => 'permissions',
            'role_id' => 'roles',
        ],
        'security_events' => [
            'organization_id' => 'organizations',
        ],
        'species' => [
            'family_id' => 'families',
        ],
        'species_common_names' => [
            'species_id' => 'species',
        ],
        'tree_inspections' => [
            'classification_rule_version_id' => 'classification_rule_versions',
            'inventory_tree_id' => 'inventory_trees',
            'maturity_state_id' => 'maturity_states',
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'tree_location_histories' => [
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'tree_status_histories' => [
            'organization_id' => 'organizations',
            'tree_id' => 'trees',
        ],
        'trees' => [
            'biogeographic_origin_id' => 'biogeographic_origins',
            'deletion_reason_id' => 'deletion_reasons',
            'establishment_origin_id' => 'establishment_origins',
            'growth_habit_id' => 'growth_habits',
            'organization_id' => 'organizations',
            'placement_id' => 'placements',
            'species_id' => 'species',
        ],
    ];

    /** Referencias internas sin FK SQL cuyo destino depende de un discriminador cerrado. */
    public const POLYMORPHIC_REFERENCES = [
        'audit_deliveries' => [
            'entry_id' => [
                'discriminator' => 'source',
                'targets' => [
                    'events' => 'security_events',
                    'evidences' => 'evidence_revisions',
                    'inspections' => 'inspection_revisions',
                    'locations' => 'tree_location_histories',
                    'maintenances' => 'maintenance_revisions',
                    'publications' => 'publication_revisions',
                    'statuses' => 'tree_status_histories',
                ],
            ],
        ],
        'mobile_mutation_receipts' => [
            'target_id' => [
                'discriminator' => 'action',
                'targets' => [
                    'inspection.create' => 'trees',
                    'inspection.update' => 'tree_inspections',
                    'tree.update' => 'trees',
                ],
            ],
        ],
    ];

    /** Identificadores de operación/cliente que nunca se convierten ni regeneran. */
    public const UUID_CORRELATIONS = [
        'mobile_inventory_receipts' => [
            'operation_id',
        ],
        'mobile_mutation_receipts' => [
            'operation_id',
        ],
        'mobile_photo_receipts' => [
            'client_id',
        ],
        'mobile_tree_receipts' => [
            'client_id',
        ],
    ];

}

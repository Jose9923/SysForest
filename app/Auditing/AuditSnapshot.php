<?php

declare(strict_types=1);

namespace App\Auditing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Proyección explícita: agregar atributos al modelo nunca amplía automáticamente la auditoría. */
class AuditSnapshot
{
    private const FIELDS = [
        'user' => ['name', 'active', 'email_verified_at', 'created_at'],
        'organization' => ['municipality_id', 'type', 'name', 'short_name', 'code', 'tree_code_prefix', 'active', 'deleted_at', 'lock_version', 'is_public', 'public_code', 'publication_version'],
        'organization_membership' => ['user_id', 'active', 'joined_at', 'left_at', 'role_name'],
        'national_role_assignment' => ['user_id', 'role_id', 'scope', 'active', 'assigned_at', 'revoked_at'],
        'department_role_assignment' => ['user_id', 'department_id', 'role_id', 'scope', 'active', 'assigned_at', 'revoked_at'],
        'organization_role_assignment' => ['membership_id', 'role_id', 'scope', 'active', 'assigned_at', 'revoked_at'],
        'invitation' => ['role_id', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at'],
        'inventory' => ['code', 'name', 'description', 'starts_at', 'ends_at', 'status', 'active', 'created_by', 'updated_by', 'created_at', 'lock_version', 'is_public', 'publication_version'],
        'inventory_tree' => ['inventory_id', 'tree_id', 'included_by', 'included_at', 'notes'],
        'tree' => ['code', 'external_number', 'location_description', 'placement_id', 'species_id', 'growth_habit_id', 'establishment_origin_id', 'biogeographic_origin_id', 'is_protected', 'is_heritage', 'current_status', 'coordinate_raw', 'coordinate_format', 'latitude', 'longitude', 'datum', 'accuracy_m', 'gps_brand', 'gps_model', 'captured_at', 'created_by', 'updated_by', 'created_at', 'lock_version', 'deleted_at', 'deleted_by', 'deletion_reason_id', 'deletion_notes', 'restored_by', 'restored_at', 'is_public', 'publication_version'],
        'carbon_profile' => ['configuration_json'],
        'carbon_refresh_run' => ['configuration_json'],
        'risk_methodology' => ['version', 'name', 'configuration_json'],
        'tree_inspection' => ['carbon', 'risk', 'context', 'measurements', 'calculations', 'phenology', 'observations', 'conditions', 'fauna', 'created_by', 'updated_by', 'created_at', 'lock_version', 'is_public', 'publication_version'],
        'evidence' => ['tree_id', 'tree_inspection_id', 'maintenance_id', 'evidence_type_id', 'caption', 'notes', 'captured_at', 'uploaded_by', 'updated_by', 'created_at', 'deleted_at', 'deleted_by', 'deletion_notes', 'lock_version', 'is_public', 'publication_version', 'public_id', 'public_caption'],
        'maintenance' => ['tree_id', 'code', 'maintenance_type_id', 'status', 'priority', 'scheduled_date', 'performed_date', 'assigned_to', 'notes', 'result', 'created_by', 'updated_by', 'created_at', 'lock_version', 'deleted_at', 'deleted_by', 'deletion_notes'],
        'import_batch' => ['inventory_id', 'code', 'schema_version', 'file_sha256', 'file_size', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'imported_rows', 'lock_version', 'created_by', 'created_at', 'expires_at', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at'],
        'catalog' => ['code', 'name', 'scientific_name', 'common_name', 'description', 'active', 'sort_order', 'lock_version', 'family_id', 'species_id', 'is_primary', 'assessment_type', 'condition_category_id', 'diameter_step', 'height_step', 'sealed_at'],
    ];

    private const GROUPS = [
        'carbon' => ['estimate_id', 'profile_id', 'status', 'method_version', 'biomass_kg', 'carbon_kg', 'co2_kg'],
        'risk' => ['evaluation_id', 'methodology_id', 'priority', 'suggested_priority', 'status', 'confirmed_by', 'confirmed_at'],
        'context' => ['tree_id', 'inventory_tree_id', 'inspection_date'],
        'measurements' => ['cap_cm', 'number_of_stems', 'form_factor', 'total_height_m', 'commercial_height_m', 'crown_x_m', 'crown_y_m'],
        'calculations' => ['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3', 'diameter_class_number', 'height_class_number', 'maturity_state_id', 'classification_rule_version_id', 'calculation_rule_version'],
        'phenology' => ['flowering_date', 'flowering_development', 'fruiting_date', 'fruiting_development'],
        'observations' => ['general_observations'],
        'fauna' => ['category_ids', 'observations'],
    ];

    public function type(Model $model): string
    {
        $type = Str::snake(class_basename($model));
        if (isset(self::FIELDS[$type])) {
            return $type;
        }
        if ($model instanceof \App\Models\CatalogEntry
            || in_array($type, ['family', 'species', 'species_common_name', 'common_name', 'condition', 'condition_category', 'classification_rule_version'], true)) {
            return 'catalog';
        }
        throw new InvalidArgumentException('El modelo no tiene una proyección de auditoría definida.');
    }

    public function snapshot(Model $model): array
    {
        return $this->sanitize($this->type($model), $model->attributesToArray());
    }

    /** La lista permitida se aplica también en persistencia: nunca se serializa un modelo completo. */
    public function sanitize(string $type, array $values): array
    {
        if (!isset(self::FIELDS[$type])) {
            throw new InvalidArgumentException('El tipo de recurso auditado no está permitido.');
        }
        $safe = array_intersect_key($values, array_flip(self::FIELDS[$type]));
        foreach ($safe as $key => $value) {
            if (is_array($value) && $type === 'tree_inspection') {
                if (isset(self::GROUPS[$key])) {
                    $value = array_intersect_key($value, array_flip(self::GROUPS[$key]));
                    foreach ($value as $nestedKey => $nestedValue) {
                        if ($key === 'fauna' && $nestedKey === 'category_ids') {
                            $value[$nestedKey] = $this->identifiers($nestedValue);
                        } elseif (!is_scalar($nestedValue) && $nestedValue !== null) {
                            unset($value[$nestedKey]);
                        }
                    }
                    $safe[$key] = $value;
                } elseif ($key === 'conditions') {
                    $safe[$key] = $this->identifiers($value);
                } else {
                    unset($safe[$key]);
                }
            } elseif (!is_scalar($value) && $value !== null) {
                unset($safe[$key]);
            }
        }

        return $safe;
    }

    public function types(): array
    {
        return array_keys(self::FIELDS);
    }

    private function identifiers(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, fn ($value): bool => is_string($value) && Str::isUuid($value))) : [];
    }
}

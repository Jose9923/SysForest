<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/** Proyección de lectura independiente de Blade; conserva nulos, ceros y falsos sin convertirlos en ausencia. */
class AuditPresentationService
{
    public const TYPES = [
        'user' => 'Cuenta de usuario', 'organization' => 'Organización', 'organization_membership' => 'Membresía',
        'national_role_assignment' => 'Rol nacional', 'department_role_assignment' => 'Rol departamental',
        'organization_role_assignment' => 'Rol institucional', 'invitation' => 'Invitación',
        'inventory' => 'Inventario', 'inventory_tree' => 'Participación en inventario', 'tree' => 'Individuo forestal',
        'carbon_profile' => 'Referencia institucional de carbono', 'tree_inspection' => 'Inspección', 'evidence' => 'Evidencia fotográfica', 'maintenance' => 'Mantenimiento',
        'import_batch' => 'Lote de importación', 'catalog' => 'Catálogo', 'security_event' => 'Evento de seguridad',
    ];

    private const LABELS = [
        'carbon' => 'Carbono aéreo almacenado', 'estimate_id' => 'Estimación (identificador)',
        'profile_id' => 'Referencia institucional (versión)', 'method_version' => 'Versión del método de carbono',
        'biomass_kg' => 'Biomasa aérea seca (kg)', 'carbon_kg' => 'Carbono aéreo (kg C)', 'co2_kg' => 'CO₂ correspondiente al stock (kg)',
        'name' => 'Nombre', 'active' => 'Activo', 'code' => 'Código', 'description' => 'Descripción',
        'lock_version' => 'Versión de edición', 'publication_version' => 'Versión de publicación', 'is_public' => 'Publicado',
        'status' => 'Estado', 'current_status' => 'Estado del individuo', 'coordinate_raw' => 'Coordenada original',
        'latitude' => 'Latitud', 'longitude' => 'Longitud', 'location' => 'Geometría GeoJSON', 'location_metadata' => 'Datos de ubicación',
        'context' => 'Contexto de inspección', 'measurements' => 'Mediciones', 'calculations' => 'Resultados calculados',
        'phenology' => 'Fenología', 'observations' => 'Observaciones', 'conditions' => 'Condiciones', 'fauna' => 'Fauna',
        'cap_cm' => 'CAP (cm)', 'dap_m' => 'DAP (m)', 'total_height_m' => 'Altura total (m)',
        'commercial_height_m' => 'Altura comercial (m)', 'basal_area_m2' => 'Área basal (m²)',
        'total_volume_m3' => 'Volumen total (m³)', 'commercial_volume_m3' => 'Volumen comercial (m³)',
        'number_of_stems' => 'Número de fustes', 'form_factor' => 'Factor de forma', 'crown_x_m' => 'Copa X (m)', 'crown_y_m' => 'Copa Y (m)',
        'created_by' => 'Autor original', 'updated_by' => 'Último editor', 'created_at' => 'Fecha de creación',
        'deleted_at' => 'Fecha de anulación', 'deleted_by' => 'Autor de anulación', 'deletion_notes' => 'Motivo de anulación',
        'reason' => 'Motivo', 'change_type' => 'Tipo de cambio de ubicación', 'catalog_key' => 'Catálogo', 'resource_code' => 'Código del recurso', 'rule_version' => 'Regla aplicada',
        'role_name' => 'Rol', 'target_user_id' => 'Usuario afectado', 'revision_id' => 'Revisión vinculada',
        'starts_at' => 'Inicio', 'ends_at' => 'Fin', 'notes' => 'Notas', 'inspection_date' => 'Fecha de inspección',
        'email_verified_at' => 'Fecha de verificación de correo',
        'municipality_id' => 'Municipio (identificador)',
        'type' => 'Tipo de organización',
        'short_name' => 'Nombre corto',
        'tree_code_prefix' => 'Prefijo de código de individuo',
        'public_code' => 'Código público',
        'user_id' => 'Usuario (identificador)',
        'joined_at' => 'Fecha de ingreso',
        'left_at' => 'Fecha de salida',
        'role_id' => 'Rol (identificador)',
        'scope' => 'Ámbito del rol',
        'assigned_at' => 'Fecha de asignación',
        'revoked_at' => 'Fecha de revocación',
        'department_id' => 'Departamento (identificador)',
        'membership_id' => 'Membresía (identificador)',
        'invited_by' => 'Usuario que invitó',
        'expires_at' => 'Fecha de vencimiento',
        'accepted_at' => 'Fecha de aceptación',
        'inventory_id' => 'Inventario (identificador)',
        'tree_id' => 'Individuo (identificador)',
        'included_by' => 'Usuario que incorporó',
        'included_at' => 'Fecha de incorporación',
        'external_number' => 'Número externo',
        'location_description' => 'Descripción de ubicación',
        'placement_id' => 'Emplazamiento (identificador)',
        'species_id' => 'Especie (identificador)',
        'growth_habit_id' => 'Hábito de crecimiento (identificador)',
        'establishment_origin_id' => 'Origen de establecimiento (identificador)',
        'biogeographic_origin_id' => 'Origen biogeográfico (identificador)',
        'is_protected' => 'Protegido',
        'is_heritage' => 'Patrimonial',
        'coordinate_format' => 'Formato de coordenadas',
        'datum' => 'Datum',
        'accuracy_m' => 'Precisión (m)',
        'gps_brand' => 'Marca del GPS',
        'gps_model' => 'Modelo del GPS',
        'captured_at' => 'Fecha y hora de captura',
        'deletion_reason_id' => 'Motivo de anulación (identificador)',
        'restored_by' => 'Usuario que restauró',
        'restored_at' => 'Fecha de restauración',
        'tree_inspection_id' => 'Inspección (identificador)',
        'maintenance_id' => 'Mantenimiento (identificador)',
        'evidence_type_id' => 'Tipo de evidencia (identificador)',
        'caption' => 'Descripción de evidencia',
        'uploaded_by' => 'Usuario que cargó',
        'public_id' => 'Identificador público',
        'public_caption' => 'Descripción pública',
        'maintenance_type_id' => 'Tipo de mantenimiento (identificador)',
        'priority' => 'Prioridad',
        'scheduled_date' => 'Fecha programada',
        'performed_date' => 'Fecha de ejecución',
        'assigned_to' => 'Usuario asignado',
        'result' => 'Resultado',
        'schema_version' => 'Versión de plantilla',
        'file_sha256' => 'Huella SHA-256 del archivo',
        'file_size' => 'Tamaño del archivo (bytes)',
        'total_rows' => 'Total de filas',
        'valid_rows' => 'Filas válidas',
        'invalid_rows' => 'Filas con errores',
        'imported_rows' => 'Filas importadas',
        'confirmed_by' => 'Usuario que confirmó',
        'confirmed_at' => 'Fecha de confirmación',
        'cancelled_by' => 'Usuario que canceló',
        'cancelled_at' => 'Fecha de cancelación',
        'scientific_name' => 'Nombre científico',
        'common_name' => 'Nombre común',
        'sort_order' => 'Orden de presentación',
        'family_id' => 'Familia (identificador)',
        'is_primary' => 'Nombre común principal',
        'assessment_type' => 'Tipo de evaluación',
        'condition_category_id' => 'Categoría de condición (identificador)',
        'diameter_step' => 'Intervalo de clase diamétrica (cm)',
        'height_step' => 'Intervalo de clase de altura (m)',
        'sealed_at' => 'Fecha de cierre de la versión',
        'inventory_tree_id' => 'Participación en inventario (identificador)',
        'radius_m' => 'Radio (m)',
        'diameter_class_number' => 'Clase diamétrica',
        'height_class_number' => 'Clase de altura',
        'maturity_state_id' => 'Estado de madurez (identificador)',
        'classification_rule_version_id' => 'Regla de clasificación (identificador)',
        'calculation_rule_version' => 'Versión de cálculo',
        'flowering_date' => 'Fecha de floración',
        'flowering_development' => 'Desarrollo de floración',
        'fruiting_date' => 'Fecha de fructificación',
        'fruiting_development' => 'Desarrollo de fructificación',
        'general_observations' => 'Observaciones generales',
        'category_ids' => 'Categorías de fauna (identificadores)',
    ];

    public function __construct(private \App\Auditing\AuditRecordProjection $snapshots)
    {
    }

    public function row(object $record): array
    {
        return [
            'id' => $record->id, 'source' => $record->source,
            'user_id' => $record->actor_user_id === null ? null : (string) $record->actor_user_id,
            'module' => \App\Auditing\AuditCatalog::moduleFor($record->event),
            'module_label' => \App\Auditing\AuditCatalog::moduleLabel($record->event),
            'occurred_at' => CarbonImmutable::parse($record->occurred_at)->utc()->format('Y-m-d H:i:s.u'),
            'event' => $record->event, 'event_label' => $this->eventLabel($record->event),
            'resource_type' => $record->resource_type,
            'resource_label' => self::TYPES[$record->resource_type] ?? 'Recurso histórico', 'resource_id' => $record->resource_id,
            'actor_name' => $record->actor_name ?? 'No registrado',
            'organization_name' => $record->organization_name ?? 'Ámbito nacional',
        ];
    }

    public function detail(object $record): array
    {
        $row = $this->row($record);
        $before = $this->safeSnapshot($record->resource_type, $record->before_values, $record->source);
        $after = $this->safeSnapshot($record->resource_type, $record->after_values, $record->source);
        $old = $this->flatten($before ?? []);
        $new = $this->flatten($after ?? []);
        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (array_key_exists($key, $old) && array_key_exists($key, $new) && $old[$key] === $new[$key]) {
                continue;
            }
            $changes[] = ['label' => implode(' · ', array_map($this->label(...), explode('.', $key))),
                'before' => array_key_exists($key, $old) ? $this->snapshotValue($key, $old[$key]) : 'Sin dato previo',
                'after' => array_key_exists($key, $new) ? $this->snapshotValue($key, $new[$key]) : 'Sin dato posterior'];
        }
        $metadata = array_intersect_key($this->decode($record->metadata) ?? [], array_flip([
            'reason', 'change_type', 'catalog_key', 'resource_code', 'rule_version', 'role_name', 'target_user_id', 'revision_id',
        ]));
        ksort($metadata);
        $context = $this->decode($record->context) ?? [];
        $requestId = $context['request_id'] ?? null;

        return $row + [
            'source_label' => match ($record->source) {
                'events' => 'Evento de auditoría', 'inspections' => 'Revisión histórica de inspección',
                'evidences' => 'Revisión histórica de evidencia', 'maintenances' => 'Revisión histórica de mantenimiento',
                'publications' => 'Revisión histórica de publicación', 'statuses' => 'Historial de estado',
                'locations' => 'Historial de ubicación', default => 'Historial',
            },
            'version' => $record->version,
            'request_id' => is_string($requestId) && \Illuminate\Support\Str::isUuid($requestId) ? $requestId : null,
            'metadata' => collect($metadata)->filter(fn ($value) => is_scalar($value) || $value === null)
                ->mapWithKeys(fn ($value, $key) => [$this->label($key) => $this->metadataValue($key, $value)])->all(),
            'changes' => $changes, 'has_snapshots' => $before !== null && $after !== null,
        ];
    }

    private function safeSnapshot(string $type, mixed $raw, string $source): ?array
    {
        return $this->snapshots->snapshot($type, $raw, $source);
    }

    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value) && !array_is_list($value) && $key !== 'location') {
                $flat += $this->flatten($value, $path.'.');
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private function decode(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : null;
    }

    private function snapshotValue(string $key, mixed $value): string
    {
        $attribute = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
        if (in_array($attribute, ['description', 'general_observations', 'observations', 'reason'], true) && is_string($value)) {
            return \App\Support\ExcelReferencePresentation::text($value);
        }
        if ($key === 'configuration_json' && is_string($value)) {
            return \App\Support\ExcelReferencePresentation::configuration($value);
        }
        if ($key === 'current_status' && is_string($value)) {
            return \App\Enums\TreeStatus::tryFrom($value)?->label() ?? $this->value($value);
        }

        return $this->value($value);
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'Nulo', $value === true => 'Sí', $value === false => 'No', $value === '' => '(Vacío)',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    private function metadataValue(string $key, mixed $value): string
    {
        if ($key === 'reason' && is_string($value)) {
            return \App\Support\ExcelReferencePresentation::text($value);
        }
        if ($key === 'change_type') {
            return $value === null ? 'Captura inicial'
                : (is_string($value) ? \App\Enums\LocationChangeType::tryFrom($value)?->label() : null) ?? 'No registrado';
        }

        return $this->value($value);
    }

    private function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    private function eventLabel(string $event): string
    {
        return \App\Auditing\AuditCatalog::eventLabel($event);
    }
}

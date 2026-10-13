<?php

declare(strict_types=1);

namespace App\Auditing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Proyección común de lectura y transporte: nunca exporta el registro SQL completo. */
class AuditRecordProjection
{
    public function __construct(private AuditSnapshot $snapshots)
    {
    }

    public function document(object $record): array
    {
        $date = CarbonImmutable::parse($record->occurred_at)->utc();
        $metadata = array_intersect_key($this->decode($record->metadata) ?? [], array_flip([
            'session_id', 'reason', 'membership_id', 'invitation_id', 'target_user_id', 'role_name',
            'department_id', 'catalog_key', 'resource_id', 'resource_code', 'rule_version', 'revision_id', 'change_type',
        ]));
        $metadata = array_filter($metadata, fn ($value): bool => is_scalar($value) || $value === null);
        $requestId = ($this->decode($record->context) ?? [])['request_id'] ?? null;
        $context = is_string($requestId) && Str::isUuid($requestId) ? ['request_id' => $requestId] : [];
        $document = [
            '_id' => $record->source.':'.$record->id, 'schema_version' => 1,
            'source' => $record->source, 'id' => (string) $record->id,
            'organization_id' => $record->organization_id === null ? null : (string) $record->organization_id,
            'actor_user_id' => $record->actor_user_id === null ? null : (string) $record->actor_user_id,
            'occurred_at_iso' => $date->format('Y-m-d\TH:i:s.u\Z'),
            'event' => $record->event, 'resource_type' => $record->resource_type,
            'resource_id' => $record->resource_id === null ? null : (string) $record->resource_id,
            'before_values' => $this->snapshot($record->resource_type, $record->before_values, $record->source),
            'after_values' => $this->snapshot($record->resource_type, $record->after_values, $record->source),
            'metadata' => $metadata, 'context' => $context,
            'version' => $record->version === null ? null : (int) $record->version,
        ];
        // Orden estable para reintentos aunque cambie el orden de las claves JSON de PostgreSQL.
        $document = $this->sortKeys($document);
        foreach (['before_values', 'after_values', 'metadata', 'context'] as $key) {
            if ($document[$key] !== null) {
                $document[$key] = (object) $document[$key];
            }
        }
        $document['payload_hash'] = hash('sha256', json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $document['occurred_at'] = new \MongoDB\BSON\UTCDateTime($date);

        return $document;
    }

    public function snapshot(string $type, mixed $raw, string $source): ?array
    {
        $values = $this->decode($raw);
        if ($values === null || !in_array($type, $this->snapshots->types(), true)) {
            return null;
        }
        $safe = $this->snapshots->sanitize($type, $values);
        if ($source === 'locations') {
            $location = $values['location'] ?? null;
            if (is_array($location) && ($location['type'] ?? null) === 'Point'
                && is_array($location['coordinates'] ?? null) && count($location['coordinates']) === 2
                && is_numeric($location['coordinates'][0]) && is_numeric($location['coordinates'][1])) {
                $safe['location'] = ['type' => 'Point', 'coordinates' => $location['coordinates']];
            }
            $safe['location_metadata'] = array_filter(
                array_intersect_key(
                    (array) ($values['location_metadata'] ?? []),
                    array_flip(['datum', 'accuracy_m', 'gps_brand', 'gps_model', 'captured_at', 'coordinate_format'])
                ),
                fn ($value) => is_scalar($value) || $value === null
            );
        }

        return $this->sortKeys($safe);
    }

    private function decode(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;

        return is_array($decoded) ? $decoded : null;
    }

    private function sortKeys(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortKeys($value);
            }
        }

        return $values;
    }
}

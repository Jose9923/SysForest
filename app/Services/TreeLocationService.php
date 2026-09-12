<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ParsedCoordinates;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\SecurityEventRepository;
use App\Repositories\TreeLocationRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reglas de captura; la autorización, la versión y el commit corresponden a TreeService. */
class TreeLocationService
{
    private const METADATA_KEYS = ['datum', 'accuracy_m', 'gps_brand', 'gps_model', 'captured_at'];

    public function __construct(
        private CoordinateParserService $parser,
        private TreeLocationRepository $locations,
        private SecurityEventRepository $events,
    ) {
    }

    /** Omitir un campo conserva su dato; un valor NULL explícito lo vacía. */
    public function apply(Tree $tree, User $actor, array $input, bool $initial = false): bool
    {
        $values = Validator::make($input, [
            'coordinate_raw' => ['nullable', 'string', 'max:2000'],
            'datum' => ['nullable', 'string', 'max:80'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'gps_brand' => ['nullable', 'string', 'max:100'],
            'gps_model' => ['nullable', 'string', 'max:100'],
            'captured_at' => ['nullable', 'date'],
            'location_change_type' => ['nullable', 'in:correction,relocation,transplant'],
            'location_reason' => ['nullable', 'string', 'max:5000'],
        ], [
            'string' => 'El campo :attribute debe ser texto.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'min' => 'El campo :attribute no puede ser negativo.',
            'max' => 'El campo :attribute supera el límite permitido.',
            'decimal' => 'La precisión admite como máximo tres decimales.',
            'date' => 'Indica una fecha y hora de captura válidas.',
            'in' => 'Selecciona un tipo de cambio de ubicación válido.',
        ])->validate();
        $current = $this->locations->snapshot($tree->organization_id, $tree->id);
        $before = $this->currentCoordinates($current);
        $coordinates = $before;
        if (array_key_exists('coordinate_raw', $values)) {
            $raw = $values['coordinate_raw'];
            $coordinates = $raw === null || trim($raw) === '' ? null : $this->parser->parse($raw);
        }

        $oldMetadata = $this->metadata($current);
        $metadata = $oldMetadata;
        foreach (self::METADATA_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $metadata[$key] = $this->normalizeMetadata($key, $values[$key]);
            }
        }
        $metadata['coordinate_format'] = $coordinates?->format;
        if ($before?->toArray() === $coordinates?->toArray() && $oldMetadata === $metadata) {
            return false;
        }

        $changeType = $initial ? null : ($values['location_change_type'] ?? null);
        $reason = isset($values['location_reason']) ? trim($values['location_reason']) : null;
        if (! $initial && ($changeType === null || $reason === null || $reason === '')) {
            throw ValidationException::withMessages(['location_reason' => 'Indica el tipo y el motivo del cambio de ubicación o de sus metadatos.']);
        }
        $this->locations->apply($current, $coordinates, $oldMetadata, $metadata, $actor, $changeType, $reason, $initial);
        $this->events->record('tree.location_changed', $actor, $current->organization_id, ['resource_id' => $current->id, 'resource_code' => $current->code]);

        return true;
    }

    private function currentCoordinates(Tree $tree): ?ParsedCoordinates
    {
        if ($tree->latitude === null || $tree->longitude === null) {
            return null;
        }

        return new ParsedCoordinates((float) $tree->latitude, (float) $tree->longitude, (string) $tree->coordinate_raw, (string) $tree->coordinate_format);
    }

    private function metadata(Tree $tree): array
    {
        $metadata = ['coordinate_format' => $tree->coordinate_format];
        foreach (self::METADATA_KEYS as $key) {
            $metadata[$key] = $this->normalizeMetadata($key, $tree->getAttribute($key));
        }

        return $metadata;
    }

    private function normalizeMetadata(string $key, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if ($key === 'datum') {
            $datum = strtoupper(trim((string) $value));
            if (in_array($datum, ['UNKNOWN', 'DESCONOCIDO'], true)) {
                return null;
            }
            if (! in_array($datum, ['WGS84', 'WGS 84', 'WGS-84'], true)) {
                throw ValidationException::withMessages(['datum' => 'Solo se admite WGS84 cuando fue indicado. Para otro datum se requiere una transformación que esta fase no implementa; deja vacío si se desconoce.']);
            }

            return 'WGS84';
        }
        if ($key === 'accuracy_m') {
            return number_format((float) $value, 3, '.', '');
        }
        if ($key === 'captured_at') {
            if (! $value instanceof DateTimeInterface
                && preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?\z/D', (string) $value) !== 1) {
                throw ValidationException::withMessages(['captured_at' => 'Indica fecha y hora de captura válidas, por ejemplo 2026-09-12T14:30, y la zona horaria si se conoce.']);
            }

            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.uP');
        }

        return trim((string) $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\PublicTreeFilterDTO;
use App\DTOs\TreeReadScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Los UUID se resuelven internamente; Select2 y las URL reciben exclusivamente códigos públicos. */
class PublicReferenceRepository
{
    public function __construct(private MapOptionRepository $options)
    {
    }

    public function resolve(array $publicValues): array
    {
        $resolved = $publicValues;
        $criteria = [];
        foreach (MapOptionRepository::FIELDS as $field => $kind) {
            $value = $publicValues[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $records = $this->query($kind, $criteria)->whereIn('code', $value)->get()->keyBy('code');
                if ($records->count() !== count(array_unique($value))) {
                    $this->unavailable($field);
                }
                $resolved[$field] = array_map(fn (string $code) => (string) $records->get($code)->id, $value);
            } else {
                $query = $this->query($kind, $criteria, false);
                $this->byCode($query, $kind, $value, $field);
                $record = $query->first();
                if ($record === null) {
                    $this->unavailable($field);
                }
                $resolved[$field] = (string) $record->id;
            }
            $criteria[$field] = $resolved[$field];
        }

        return $resolved;
    }

    public function options(string $kind, PublicTreeFilterDTO $filters, string $term = '', int $page = 1): array
    {
        abort_unless(in_array($kind, MapOptionRepository::FIELDS, true), 404);
        $query = $this->query($kind, $filters->trees->values());
        $name = in_array($kind, ['species', 'families'], true) ? 'scientific_name' : 'name';
        $this->options->search($query, $kind, $term);
        $records = $query->orderBy($name)->orderBy('id')->offset((max(1, min(1000, $page)) - 1) * 30)->limit(31)->get();

        return ['results' => $records->take(30)->map(fn ($record) => $this->label($record, $kind))->values()->all(),
            'pagination' => ['more' => $records->count() > 30]];
    }

    public function selected(PublicTreeFilterDTO $filters): array
    {
        $selected = [];
        foreach (MapOptionRepository::FIELDS as $field => $kind) {
            $value = $filters->trees->get($field);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $selected[$field] = $this->query($kind, $filters->trees->values(), false)
                ->whereKey(is_array($value) ? $value : [$value])->get()
                ->map(fn ($record) => $this->label($record, $kind))->values()->all();
        }

        return $selected;
    }

    private function query(string $kind, array $filters, bool $taxonomyCascade = true): Builder
    {
        // Los códigos se resuelven sin cascada taxonómica: criterios válidos incompatibles seleccionan cero árboles.
        // El alcance territorial se conserva incluso al recuperar etiquetas seleccionadas.
        $fields = ['department_id', 'municipality_id', 'organization_id'];
        if ($taxonomyCascade) {
            $fields = [...$fields, 'family_id', 'species_id'];
        }
        $criteria = array_filter(
            array_intersect_key($filters, array_flip($fields)),
            fn ($value) => is_string($value) && \Illuminate\Support\Str::isUuid($value)
        );
        $query = $this->options->query($kind, TreeReadScope::published(), $criteria);
        return match ($kind) {
            'departments' => $query->with('country:id,code'),
            'municipalities' => $query->with('department:id,country_id,code', 'department.country:id,code'),
            'inventories' => $query->with('organization:id,public_code'),
            default => $query,
        };
    }

    private function byCode(Builder $query, string $kind, string $code, string $field): void
    {
        $parts = explode('~', $code);
        $expected = match ($kind) {
            'departments', 'inventories' => 2, 'municipalities' => 3, default => 1
        };
        if (count($parts) !== $expected || in_array('', $parts, true)) {
            $this->unavailable($field);
        }
        if ($kind === 'departments') {
            $query->where('code', $parts[1])->whereHas('country', fn (Builder $country) => $country->where('code', $parts[0]));
        } elseif ($kind === 'municipalities') {
            $query->where('code', $parts[2])->whereHas('department', fn (Builder $department) => $department
                ->where('code', $parts[1])->whereHas('country', fn (Builder $country) => $country->where('code', $parts[0])));
        } elseif ($kind === 'inventories') {
            $query->where('code', $parts[1])->whereHas('organization', fn (Builder $organization) => $organization->where('public_code', $parts[0]));
        } else {
            $query->where($kind === 'organizations' ? 'public_code' : 'code', $code);
        }
    }

    private function label($record, string $kind): array
    {
        $code = match ($kind) {
            'departments' => $record->country->code.'~'.$record->code,
            'municipalities' => $record->department->country->code.'~'.$record->department->code.'~'.$record->code,
            'organizations' => $record->public_code,
            'inventories' => $record->organization->public_code.'~'.$record->code,
            default => $record->code,
        };
        return ['id' => (string) $code, 'text' => $this->options->optionText($record, $kind)];
    }

    private function unavailable(string $field): never
    {
        throw ValidationException::withMessages([$field => 'La opción seleccionada no está disponible para consulta pública.']);
    }
}

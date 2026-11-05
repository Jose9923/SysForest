<?php

declare(strict_types=1);

namespace App\Services\Territories;

use App\Models\Territories\Country;
use App\Models\Territories\Municipality;
use App\Repositories\Territories\TerritorySeedRepository;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Carga la copia revisada de DIVIPOLA; no descarga datos ni infiere límites geográficos. */
class TerritorySeedService
{
    public function __construct(private TerritorySeedRepository $repository)
    {
    }

    /**
     * Conserva UUID, nombres, vigencia, geometrías y relaciones de registros ya existentes.
     *
     * @return array{country_id: string, created: array<string, int>, existing: array<string, int>}
     * @throws LogicException Si la fuente o los códigos existentes tienen referencias incompatibles.
     */
    public function seed(): array
    {
        return $this->seedManifest($this->readManifest());
    }

    /** Mantiene la preparación aislada del piloto sin sobrescribir el catálogo nacional. */
    public function seedPilotTerritories(): Municipality
    {
        $manifest = $this->readManifest();
        $manifest['departments'] = array_values(array_filter($manifest['departments'], fn (array $row): bool => $row['code'] === '50'));
        $manifest['municipalities'] = array_values(array_filter($manifest['municipalities'], fn (array $row): bool => $row['code'] === '50001'));
        if (count($manifest['departments']) !== 1 || count($manifest['municipalities']) !== 1) {
            throw new LogicException('El catálogo revisado debe incluir Meta y Villavicencio para preparar el piloto.');
        }
        $result = $this->seedManifest($manifest);

        return $this->repository->municipality($result['country_id'], '50001');
    }

    private function seedManifest(array $manifest): array
    {
        return DB::transaction(function () use ($manifest): array {
            $countryData = $manifest['country'];
            foreach ($this->repository->countryCandidates($countryData) as $candidate) {
                $this->assertCountryCompatible($candidate, $countryData);
            }
            $country = $this->repository->ensureCountry($countryData);
            $countryCreated = $country->wasRecentlyCreated;
            $country = $this->repository->lockCountry($country->id);
            $this->assertCountryCompatible($country, $countryData);
            $result = [
                'country_id' => $country->id,
                'created' => ['countries' => (int) $countryCreated, 'departments' => 0, 'municipalities' => 0],
                'existing' => ['countries' => (int) !$countryCreated, 'departments' => 0, 'municipalities' => 0],
            ];
            $departments = $this->repository->departments($country->id)->keyBy('code');
            $municipalities = $this->repository->municipalities($country->id)->groupBy('code');

            foreach ($manifest['departments'] as $row) {
                $department = $departments->get($row['code']);
                if ($department === null) {
                    $department = $this->repository->ensureDepartment($country->id, $row);
                    $departments->put($row['code'], $department);
                    $result[$department->wasRecentlyCreated ? 'created' : 'existing']['departments']++;
                } else {
                    $result['existing']['departments']++;
                }
            }
            foreach ($manifest['municipalities'] as $row) {
                $department = $departments->get($row['department_code']);
                $matches = $municipalities->get($row['code']);
                // DIVIPOLA identifica el municipio dentro de Colombia: nunca se traslada ni duplica entre departamentos.
                if ($matches !== null && ($matches->count() !== 1 || $matches->first()->department_id !== $department->id)) {
                    throw new LogicException('El municipio '.$row['code'].' tiene una relación territorial incompatible; no se modifica.');
                }
                if ($matches !== null) {
                    $result['existing']['municipalities']++;

                    continue;
                }
                $municipality = $this->repository->ensureMunicipality($department->id, $row);
                $result[$municipality->wasRecentlyCreated ? 'created' : 'existing']['municipalities']++;
            }

            return $result;
        }, 3);
    }

    private function assertCountryCompatible(Country $country, array $expected): void
    {
        foreach (['code', 'iso_alpha_2', 'iso_alpha_3'] as $field) {
            if ($country->getAttribute($field) !== $expected[$field]) {
                throw new LogicException('El código de Colombia o sus identificadores ISO requieren revisión; no se sobrescriben.');
            }
        }
    }

    /** Valida toda la copia local antes de abrir una transacción o realizar cualquier alta. */
    private function readManifest(): array
    {
        $path = database_path('seeders/data/colombia-territories.json');
        if (!is_file($path) || !is_readable($path)) {
            throw new LogicException('No se puede leer el catálogo territorial revisado.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new LogicException('No se pudo cargar el catálogo territorial revisado.');
        }
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !$this->hasKeys($manifest, ['source', 'country', 'departments', 'municipalities'])
            || !is_array($manifest['source']) || $manifest['source'] === []
            || !is_array($manifest['country']) || !$this->hasKeys($manifest['country'], ['code', 'name', 'iso_alpha_2', 'iso_alpha_3'])
            || $manifest['country']['code'] !== '170' || $manifest['country']['iso_alpha_2'] !== 'CO'
            || $manifest['country']['iso_alpha_3'] !== 'COL' || !$this->validName($manifest['country']['name'])) {
            throw new LogicException('El manifiesto territorial no identifica correctamente a Colombia.');
        }
        foreach (['departments', 'municipalities'] as $key) {
            if (!is_array($manifest[$key]) || !array_is_list($manifest[$key]) || $manifest[$key] === []) {
                throw new LogicException('El catálogo '.$key.' debe ser una lista territorial no vacía.');
            }
        }
        $departmentCodes = [];
        foreach ($manifest['departments'] as $row) {
            if (!is_array($row) || !$this->hasKeys($row, ['code', 'name'])
                || !$this->validCode($row['code'], 2) || !$this->validName($row['name'])
                || isset($departmentCodes[$row['code']])) {
                throw new LogicException('El catálogo contiene un departamento inválido o repetido.');
            }
            $departmentCodes[$row['code']] = true;
        }
        $municipalityCodes = [];
        foreach ($manifest['municipalities'] as $row) {
            if (!is_array($row) || !$this->hasKeys($row, ['code', 'department_code', 'name', 'source_type'])
                || !$this->validCode($row['code'], 5) || !$this->validCode($row['department_code'], 2)
                || !$this->validName($row['name']) || !in_array($row['source_type'], ['MUNICIPIO', 'ISLA'], true)
                || ($row['source_type'] === 'ISLA' && $row['code'] !== '88001')
                || !isset($departmentCodes[$row['department_code']])
                || !str_starts_with($row['code'], $row['department_code']) || isset($municipalityCodes[$row['code']])) {
                throw new LogicException('El catálogo contiene un municipio inválido, repetido o asociado a otro departamento.');
            }
            $municipalityCodes[$row['code']] = true;
        }

        return $manifest;
    }

    private function hasKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    private function validCode(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && ctype_digit($value);
    }

    private function validName(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= 150
            && preg_match('/[\x00-\x1F\x7F]/u', $value) === 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ClassificationRuleVersion;
use App\Models\MaturityRuleBand;
use App\Models\MaturityState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ClassificationRuleRepository
{
    public function find(string $id, bool $lock = false): ClassificationRuleVersion
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(ClassificationRuleVersion::class, [$id]);
        }

        $query = ClassificationRuleVersion::query()->with('maturityBands.maturityState');

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    /** Las versiones inactivas siguen disponibles para reproducir resultados históricos. */
    public function findSealed(string $id): ClassificationRuleVersion
    {
        $version = $this->find($id);
        if ($version->sealed_at === null) {
            throw (new ModelNotFoundException())->setModel(ClassificationRuleVersion::class, [$id]);
        }

        return $version;
    }

    public function findByCode(string $code): ClassificationRuleVersion
    {
        return ClassificationRuleVersion::query()->with('maturityBands.maturityState')->where('code', $code)->firstOrFail();
    }

    /** La captura mantiene disponibles la versión y sus estados hasta confirmar la inspección. */
    public function findForCapture(string $code): ClassificationRuleVersion
    {
        $lock = DB::transactionLevel() > 0;
        $query = ClassificationRuleVersion::query()->where('code', $code);
        $version = ($lock ? $query->sharedLock() : $query)->firstOrFail();
        if ($lock) {
            $stateIds = $version->maturityBands()->pluck('maturity_state_id')->all();
            MaturityState::query()->whereIn('id', $stateIds)->orderBy('id')->sharedLock()->get(['id']);
        }

        // Se cargan después de adquirir los bloqueos; una lectura previa podría traer active obsoleto.
        return $version->load('maturityBands.maturityState');
    }

    public function activeVersions(): Collection
    {
        return ClassificationRuleVersion::query()->whereNotNull('sealed_at')->where('active', true)
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    public function paginate(?string $search = null, ?bool $active = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = ClassificationRuleVersion::query()->whereNotNull('sealed_at');
        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $pattern)->orWhere('code', 'ilike', $pattern));
        }
        if ($active !== null) {
            $query->where('active', $active);
        }

        return $query->orderBy('sort_order')->orderBy('code')->paginate(max(1, min($perPage, 100)));
    }

    /** Persistencia para el servicio administrativo, que autoriza y audita dentro de su transacción. */
    public function updateMetadata(ClassificationRuleVersion $version, array $attributes): ClassificationRuleVersion
    {
        if (array_diff(array_keys($attributes), ['name', 'description', 'active', 'sort_order']) !== []) {
            throw new InvalidArgumentException('Solo se pueden editar los metadatos de una versión.');
        }

        $version->fill($attributes)->save();

        return $this->find($version->id);
    }

    /** El seeder conserva identidades, etiquetas, orden y desactivaciones administrativas. */
    public function seedExcelVersion(): ClassificationRuleVersion
    {
        return DB::transaction(function (): ClassificationRuleVersion {
            $existing = ClassificationRuleVersion::query()->where('code', 'EXCEL_V1')->lockForUpdate()->first();
            if ($existing !== null) {
                $existing->load('maturityBands.maturityState');
                $this->assertExcelDefinition($existing);

                return $existing;
            }

            $definitions = [
                ['MAT_YOUNG', 1, 3],
                ['MAT_MEDIUM', 4, 6],
                ['MAT_MATURE', 7, 9],
                ['MAT_OVERMATURE', 10, null],
            ];
            $states = MaturityState::query()->whereIn('code', array_column($definitions, 0))->pluck('id', 'code');
            if ($states->count() !== 4) {
                throw new LogicException('Siembra primero los cuatro estados de madurez del Excel.');
            }

            $version = new ClassificationRuleVersion();
            $version->forceFill([
                'code' => 'EXCEL_V1',
                'name' => 'Clasificación del inventario Excel · versión 1',
                'description' => 'DAP: intervalos de 5 cm; altura total: intervalos de 10 m. Madurez por clases diamétricas 1–3, 4–6, 7–9 y mayores de 9.',
                'algorithm' => 'positive_ceiling_v1',
                'diameter_step' => '5.000000',
                'height_step' => '10.000000',
                'diameter_unit' => 'cm',
                'height_unit' => 'm',
                'active' => true,
                'sort_order' => 0,
            ])->save();

            foreach ($definitions as [$stateCode, $minimum, $maximum]) {
                MaturityRuleBand::query()->create([
                    'rule_version_id' => $version->id,
                    'maturity_state_id' => $states[$stateCode],
                    'min_class' => $minimum,
                    'max_class' => $maximum,
                ]);
            }

            $version->forceFill(['sealed_at' => now()])->save();

            return $this->findSealed($version->id);
        });
    }

    private function assertExcelDefinition(ClassificationRuleVersion $version): void
    {
        $bands = $version->maturityBands->map(fn (MaturityRuleBand $band): array => [
            $band->maturityState->code, $band->min_class, $band->max_class,
        ])->all();
        if ($version->sealed_at === null || $version->algorithm !== 'positive_ceiling_v1'
            || $version->diameter_step !== '5.000000' || $version->height_step !== '10.000000'
            || $version->diameter_unit !== 'cm' || $version->height_unit !== 'm'
            || $bands !== [['MAT_YOUNG', 1, 3], ['MAT_MEDIUM', 4, 6], ['MAT_MATURE', 7, 9], ['MAT_OVERMATURE', 10, null]]) {
            throw new LogicException('EXCEL_V1 ya existe con otra definición. No se sobrescriben reglas históricas.');
        }
    }
}

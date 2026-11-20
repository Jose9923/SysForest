<?php

declare(strict_types=1);

namespace App\Repositories\Imports;

use App\Imports\ImportCatalogSelection;
use App\Repositories\Catalogs\CatalogCodeRepository;
use App\Enums\Catalogs\CatalogCodeKind;
use App\Models\Catalogs\BiogeographicOrigin;
use App\Models\Conditions\Condition;
use App\Models\Catalogs\ClassificationRuleVersion;
use App\Models\Catalogs\MaturityState;
use App\Models\Conditions\ConditionCategory;
use App\Models\Catalogs\EstablishmentOrigin;
use App\Models\Taxonomy\Family;
use App\Models\Catalogs\FaunaCategory;
use App\Models\Catalogs\GrowthHabit;
use App\Models\Catalogs\Placement;
use App\Models\Taxonomy\Species;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Resuelve códigos y selecciones nacionales exactos; nunca confía en catálogos del archivo. */
class ImportCatalogRepository
{
    public function __construct(private CatalogCodeRepository $codes)
    {
    }

    private const MODELS = [
        'placement_code' => Placement::class,
        'species_code' => Species::class,
        'growth_habit_code' => GrowthHabit::class,
        'establishment_origin_code' => EstablishmentOrigin::class,
        'biogeographic_origin_code' => BiogeographicOrigin::class,
        'fauna_category_codes' => FaunaCategory::class,
        'condition' => Condition::class,
    ];

    /** El bloqueo de confirmación mantiene vigente la referencia y su catálogo padre hasta el commit. */
    public function resolve(string $field, string $code, bool $lock = false): ?string
    {
        if (! isset(self::MODELS[$field])) {
            throw new InvalidArgumentException('Referencia de importación desconocida.');
        }
        if ($lock && DB::transactionLevel() < 1) {
            throw new LogicException('Bloquear catálogos requiere una transacción.');
        }
        $selection = ImportCatalogSelection::parse($code);
        $selectedCode = $selection['code'] ?? $code;
        $query = self::MODELS[$field]::query();
        if (in_array($field, ['species_code', 'placement_code'], true)) {
            $kind = $field === 'species_code' ? CatalogCodeKind::Species : CatalogCodeKind::Placements;
            $id = $this->codes->resolveId($kind, $selectedCode);
            if ($id === null) {
                return null;
            }
            $query->whereKey($id);
        } else {
            $query->where('code', $selectedCode);
        }
        $record = ($lock ? $query->sharedLock() : $query)->first();
        if ($record === null || ! $record->active || ! $this->parentAvailable($record, $lock)) {
            return null;
        }
        $label = $field === 'species_code' ? $record->scientific_name : $record->name;
        if ($selection !== null && $selection['label'] !== $label) {
            return null;
        }

        return $record->id;
    }

    /** Conserva disponible la versión sellada y sus estados mientras se confirma el lote. */
    public function lockClassification(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Bloquear reglas requiere una transacción.');
        }
        $version = ClassificationRuleVersion::query()->where('code', 'EXCEL_V1')->sharedLock()->first();
        if ($version !== null) {
            $ids = $version->maturityBands()->pluck('maturity_state_id')->all();
            MaturityState::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
        }
    }

    public function conditionType(string $id): ?string
    {
        return Condition::query()->whereKey($id)->value('assessment_type');
    }

    /** @return list<array{catalog:string,code:string,label:string,details:string}> */
    public function templateOptions(): array
    {
        $result = [];
        foreach (self::MODELS as $field => $model) {
            $query = $model::query()->where('active', true)->orderBy('code');
            if ($field === 'species_code') {
                $query->with(['family', 'commonNames'])->whereHas('family', fn ($parent) => $parent->where('active', true));
            } elseif ($field === 'condition') {
                $query->with('category')->whereHas('category', fn ($parent) => $parent->where('active', true));
            }
            foreach ($query->get() as $record) {
                $details = match ($field) {
                    'species_code' => 'Familia: '.$record->family->scientific_name.'; nombres comunes: '.$record->commonNames->pluck('name')->implode(' | '),
                    'condition' => match ($record->assessment_type) {
                        'physical_mechanical' => 'Física y mecánica',
                        'phytosanitary' => 'Fitosanitaria',
                        default => 'Evaluación sin clasificación reconocida',
                    }.'; órgano o categoría: '.$record->category->name,
                    default => '',
                };
                $result[] = ['catalog' => $field, 'code' => $record->code,
                    'label' => $field === 'species_code' ? $record->scientific_name : $record->name, 'details' => $details];
            }
        }

        return $result;
    }

    private function parentAvailable(Model $record, bool $lock): bool
    {
        $query = match (true) {
            $record instanceof Species => Family::query()->whereKey($record->family_id),
            $record instanceof Condition => ConditionCategory::query()->whereKey($record->condition_category_id),
            default => null,
        };
        if ($query === null) {
            return true;
        }
        $parent = ($lock ? $query->sharedLock() : $query)->first();

        return $parent !== null && $parent->active;
    }
}

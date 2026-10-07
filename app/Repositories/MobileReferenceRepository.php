<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\BiogeographicOrigin;
use App\Models\Condition;
use App\Models\ConditionCategory;
use App\Models\DeletionReason;
use App\Models\EstablishmentOrigin;
use App\Models\EvidenceType;
use App\Models\Family;
use App\Models\FaunaCategory;
use App\Models\GrowthHabit;
use App\Models\Inventory;
use App\Models\MaintenanceType;
use App\Models\MaturityState;
use App\Models\Placement;
use App\Models\Species;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro cerrado de catálogos nacionales; el cliente nunca elige tablas o columnas. */
class MobileReferenceRepository
{
    public const CATALOGS = [
        'placements' => Placement::class,
        'species' => Species::class,
        'families' => Family::class,
        'growth_habits' => GrowthHabit::class,
        'establishment_origins' => EstablishmentOrigin::class,
        'biogeographic_origins' => BiogeographicOrigin::class,
        'condition_categories' => ConditionCategory::class,
        'physical_conditions' => Condition::class,
        'phytosanitary_conditions' => Condition::class,
        'fauna_categories' => FaunaCategory::class,
        'maturity_states' => MaturityState::class,
        'deletion_reasons' => DeletionReason::class,
        'evidence_types' => EvidenceType::class,
        'maintenance_types' => MaintenanceType::class,
    ];

    public function catalog(string $catalog, array $filters): LengthAwarePaginator
    {
        abort_unless(isset(self::CATALOGS[$catalog]), 404);
        $query = self::CATALOGS[$catalog]::query()->where('active', true);
        $name = in_array($catalog, ['species', 'families'], true) ? 'scientific_name' : 'name';

        if ($catalog === 'species') {
            $query->whereHas('family', fn (Builder $family) => $family->where('active', true))
                ->with(['commonNames' => fn (HasMany $names) => $names->where('active', true)
                    ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('name')->orderBy('id')]);
            if (!empty($filters['family_id'])) {
                $query->where('family_id', $filters['family_id']);
            }
        }
        if (in_array($catalog, ['physical_conditions', 'phytosanitary_conditions'], true)) {
            $type = $catalog === 'physical_conditions' ? 'physical_mechanical' : 'phytosanitary';
            $query->where('assessment_type', $type)
                ->whereHas('category', fn (Builder $category) => $category->where('active', true));
            if (!empty($filters['condition_category_id'])) {
                $query->where('condition_category_id', $filters['condition_category_id']);
            }
        }
        $this->search($query, $filters['q'] ?? '', $name, $catalog === 'species');

        return $query->orderBy('sort_order')->orderBy($name)->orderBy('id')->paginate(
            (int) ($filters['per_page'] ?? 100),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
    }

    /** El alcance se recibe únicamente del contexto JWT revalidado por el servicio. */
    public function inventories(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Inventory::query()->where('organization_id', $organizationId)
            ->where('active', true)->where('status', 'active');
        $this->search($query, $filters['q'] ?? '', 'name');

        return $query->orderBy('name')->orderBy('id')->paginate(
            (int) ($filters['per_page'] ?? 100),
            ['id', 'organization_id', 'code', 'name', 'description', 'starts_at', 'ends_at', 'status', 'active', 'updated_at'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
    }

    private function search(Builder $query, string $term, string $name, bool $commonNames = false): void
    {
        if ($term === '') {
            return;
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query->where(function (Builder $matches) use ($pattern, $name, $commonNames): void {
            $matches->where($name, 'ilike', $pattern)->orWhere('code', 'ilike', $pattern);
            if ($commonNames) {
                $matches->orWhereHas('commonNames', fn (Builder $names) => $names
                    ->where('active', true)->where('name', 'ilike', $pattern));
            }
        });
    }
}

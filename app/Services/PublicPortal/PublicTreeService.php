<?php

declare(strict_types=1);

namespace App\Services\PublicPortal;

use App\Http\Resources\PublicPortal\PublicEvidenceResource;
use App\Http\Resources\PublicPortal\PublicInspectionResource;
use App\Http\Resources\PublicPortal\PublicMaintenanceSummaryResource;
use App\Http\Resources\PublicPortal\PublicTreeDetailResource;
use App\Http\Resources\PublicPortal\PublicTreeSummaryResource;
use App\Repositories\PublicPortal\PublicTreeRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Una consulta filtrada alimenta Blade, API y las futuras exportaciones de la fase siguiente. */
class PublicTreeService
{
    public function __construct(
        private PublicTreeFilterService $filters,
        private PublicTreeRepository $trees,
        private PublicMapService $map,
    ) {
    }

    public function listing(array $input): array
    {
        $filters = $this->filters->validate($input);
        $records = $this->trees->paginate($filters, (int) config('public-portal.page_size'));
        $records->through(fn ($tree) => (new PublicTreeSummaryResource($tree))->resolve())->appends($filters->publicValues);

        return $this->map->pageFor($filters) + [
            'trees' => $records, 'indicators' => $this->trees->indicators($filters),
            'sortOptions' => ['code' => 'Código', 'common_name' => 'Nombre común', 'scientific_name' => 'Especie',
                'municipality' => 'Municipio', 'latest_inspection_date' => 'Última inspección publicada'],
            'mapUrl' => route('public.map', $filters->publicValues, false),
        ];
    }

    public function indicators(array $input): array
    {
        return $this->trees->indicators($this->filters->validate($input));
    }

    public function detail(string $organizationCode, string $treeCode, array $input = []): array
    {
        $unknown = array_diff(array_keys($input), ['inspections_page', 'maintenances_page', 'evidences_page']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => 'La ficha solo admite páginas de sus historiales.']);
        }
        $pages = Validator::make($input, [
            'inspections_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'maintenances_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'evidences_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ])->validate();
        $tree = $this->trees->find($organizationCode, $treeCode);

        return [
            'tree' => (new PublicTreeDetailResource($tree))->resolve(),
            'inspectionHistory' => $this->trees->inspections($tree, (int) ($pages['inspections_page'] ?? 1))
                ->through(fn ($inspection) => (new PublicInspectionResource($inspection))->resolve())->appends($pages),
            'maintenanceHistory' => $this->trees->maintenances($tree, (int) ($pages['maintenances_page'] ?? 1))
                ->through(fn ($maintenance) => (new PublicMaintenanceSummaryResource($maintenance))->resolve())->appends($pages),
            'gallery' => $this->trees->evidences($tree, (int) ($pages['evidences_page'] ?? 1))
                ->through(fn ($evidence) => (new PublicEvidenceResource($evidence))->resolve())->appends($pages),
            'mapConfig' => $this->map->configuration(),
        ];
    }
}

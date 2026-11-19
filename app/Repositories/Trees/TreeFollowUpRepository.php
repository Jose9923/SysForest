<?php

declare(strict_types=1);

namespace App\Repositories\Trees;

use App\DTOs\Trees\TreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Proyección breve de seguimiento; no carga visitas, evidencias ni historiales por fila. */
class TreeFollowUpRepository
{
    public function __construct(private TreeQueryRepository $queries)
    {
    }

    public function paginate(string $organizationId, TreeFilterDTO $filters, bool $includeInspections): LengthAwarePaginator
    {
        $query = $this->queries->query(TreeReadScope::organization($organizationId), $filters)
            ->select(['trees.id', 'trees.organization_id', 'trees.code', 'trees.external_number',
                'trees.species_id', 'trees.placement_id', 'trees.current_status',
                'trees.location_description', 'trees.deleted_at'])
            ->with([
                'species:id,scientific_name',
                'species.commonNames:id,species_id,name',
                'placement:id,name',
            ]);

        if ($includeInspections) {
            $query->addSelect(
                'latest_inspection.inspection_date as latest_inspection_date',
                'latest_inspection.id as latest_inspection_id'
            )
                ->selectSub(DB::table('tree_inspections')->selectRaw('COUNT(*)')
                    ->whereColumn('tree_inspections.organization_id', 'trees.organization_id')
                    ->whereColumn('tree_inspections.tree_id', 'trees.id'), 'inspection_count')
                ->withCasts(['inspection_count' => 'integer', 'latest_inspection_date' => 'immutable_date']);
        }

        return $this->queries->ordered($query, $filters)->paginate(20)->withQueryString();
    }
}

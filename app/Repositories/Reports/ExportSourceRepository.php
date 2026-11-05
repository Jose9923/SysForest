<?php

declare(strict_types=1);

namespace App\Repositories\Reports;

use App\DTOs\Maps\MapTreeFilterDTO;
use App\DTOs\Trees\TreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;
use App\Models\Inspections\TreeInspection;
use Illuminate\Validation\ValidationException;
use App\Repositories\Maps\MapTreeRepository;
use App\Repositories\PublicPortal\PublicTreeRepository;
use App\Repositories\PublicPortal\PublicVisibilityRepository;

/** Revalida fuentes públicas una vez terminada la instantánea y antes de entregar un archivo. */
class ExportSourceRepository
{
    public function __construct(
        private MapTreeRepository $trees,
        private PublicVisibilityRepository $visibility,
        private PublicTreeRepository $publicTrees,
    ) {
    }

    public function assertPublic(array $manifest): void
    {
        $filters = new MapTreeFilterDTO(TreeFilterDTO::fromValidated([]), null);
        foreach (array_chunk(array_keys($manifest['trees'] ?? []), 200) as $ids) {
            $count = $this->trees->query(TreeReadScope::published(), $filters)->whereIn('trees.id', $ids)->count();
            $this->matchCount(count($ids), $count);
        }
        foreach (array_chunk(array_keys($manifest['inspections'] ?? []), 200) as $ids) {
            $query = TreeInspection::query()->whereIn('id', $ids);
            $this->visibility->inspection($query);
            $this->matchCount(count($ids), $query->count());
        }
        foreach (array_chunk(array_keys($manifest['evidences'] ?? []), 200) as $ids) {
            $this->matchCount(count($ids), $this->publicTrees->evidenceQuery()->whereIn('evidences.id', $ids)->count());
        }
    }

    private function matchCount(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw ValidationException::withMessages(['publication' => 'La publicación cambió durante la preparación. Actualiza la consulta y descarga nuevamente.']);
        }
    }
}

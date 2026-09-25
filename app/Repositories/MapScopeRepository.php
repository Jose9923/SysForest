<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\TreeReadScope;
use App\Models\Organization;
use App\Models\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MapScopeRepository
{
    public function __construct(private PublicVisibilityRepository $visibility)
    {
    }

    /** Consulta interna: el Service debe autorizar previamente el alcance y el actor. */
    public function organizations(TreeReadScope $scope): Builder
    {
        $query = Organization::query()->where('organizations.active', true)
            ->whereHas('municipality', fn (Builder $municipality) => $municipality->where('active', true)
                ->whereHas('department', fn (Builder $department) => $department->where('active', true)
                    ->whereHas('country', fn (Builder $country) => $country->where('active', true))));

        if ($scope->kind === 'organization') {
            $query->where('organizations.id', $scope->organizationId);
        } elseif ($scope->kind === 'department') {
            $query->whereHas('municipality', fn (Builder $municipality) => $municipality
                ->whereIn('department_id', $scope->departmentIds));
        } elseif ($scope->kind === 'published') {
            $query->where('organizations.is_public', true)->whereNotNull('organizations.public_code');
        } elseif ($scope->kind !== 'national') {
            throw new InvalidArgumentException('El alcance de lectura no es válido.');
        }

        return $query;
    }

    /** EXISTS preserva una fila por árbol; el alias es interno y nunca proviene del formulario. */
    public function applyTo(Builder $query, TreeReadScope $scope, string $treeAlias = 'trees'): void
    {
        if (! preg_match('/\A[a-z_][a-z0-9_]*\z/D', $treeAlias)) {
            throw new InvalidArgumentException('El alias de la consulta no es válido.');
        }

        $organizations = $this->organizations($scope)->selectRaw('1')
            ->whereColumn('organizations.id', $treeAlias.'.organization_id');

        $query->whereExists($organizations->toBase());
        if ($scope->kind === 'published') {
            $this->visibility->tree($query, $treeAlias);
        }
    }

    /** La navegación del mapa excluye árboles anulados incluso para administradores. */
    public function findTree(TreeReadScope $scope, string $treeId): Tree
    {
        if (! Str::isUuid($treeId)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Tree::class);
        }

        $query = Tree::query()->whereKey($treeId);
        $this->applyTo($query, $scope);

        return $query->firstOrFail();
    }
}

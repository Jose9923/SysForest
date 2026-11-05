<?php

declare(strict_types=1);

namespace App\Repositories\PublicPortal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/** Predicados de publicación reutilizables; nunca conceden acceso administrativo ni modifican datos. */
class PublicVisibilityRepository
{
    public function inventory(Builder|QueryBuilder $query, string $alias = 'inventories'): void
    {
        $this->alias($alias);
        $query->where($alias.'.is_public', true)->where($alias.'.active', true)->where($alias.'.status', '<>', 'draft');
    }

    /** MapScopeRepository añade por separado la organización publicada y su jerarquía activa. */
    public function tree(Builder|QueryBuilder $query, string $alias = 'trees'): void
    {
        $this->alias($alias);
        $query->where($alias.'.is_public', true)->whereNull($alias.'.deleted_at');
        $query->whereExists(function (QueryBuilder $inventory) use ($alias): void {
            $inventory->selectRaw('1')->from('inventory_trees as published_participation')
                ->join('inventories as published_inventory', function ($join): void {
                    $join->on('published_inventory.id', '=', 'published_participation.inventory_id')
                        ->on('published_inventory.organization_id', '=', 'published_participation.organization_id');
                })
                ->whereColumn('published_participation.organization_id', $alias.'.organization_id')
                ->whereColumn('published_participation.tree_id', $alias.'.id');
            $this->inventory($inventory, 'published_inventory');
        });
    }

    /** Conserva la campaña de cada visita: publicar otro inventario del árbol no publica esta visita. */
    public function inspection(Builder|QueryBuilder $query, string $alias = 'tree_inspections'): void
    {
        $this->alias($alias);
        $query->where($alias.'.is_public', true)->where(function ($campaign) use ($alias): void {
            $campaign->whereNull($alias.'.inventory_tree_id')
                ->orWhereExists(function (QueryBuilder $inventory) use ($alias): void {
                    $inventory->selectRaw('1')->from('inventory_trees as published_inspection_participation')
                        ->join('inventories as published_inspection_inventory', function ($join): void {
                            $join->on('published_inspection_inventory.id', '=', 'published_inspection_participation.inventory_id')
                                ->on('published_inspection_inventory.organization_id', '=', 'published_inspection_participation.organization_id');
                        })
                        ->whereColumn('published_inspection_participation.id', $alias.'.inventory_tree_id')
                        ->whereColumn('published_inspection_participation.tree_id', $alias.'.tree_id')
                        ->whereColumn('published_inspection_participation.organization_id', $alias.'.organization_id');
                    $this->inventory($inventory, 'published_inspection_inventory');
                });
        });
    }

    public function maintenance(Builder|QueryBuilder $query, string $alias = 'maintenances'): void
    {
        $this->alias($alias);
        $query->whereNull($alias.'.deleted_at')->whereIn($alias.'.status', ['executed', 'verified']);
    }

    private function alias(string $alias): void
    {
        if (!preg_match('/\A[a-z_][a-z0-9_]*\z/D', $alias)) {
            throw new InvalidArgumentException('El alias interno de publicación no es válido.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Organization;
use App\Models\Tree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Datos cartográficos sintéticos solamente en la transacción de sysforest_testing. */
abstract class MapTestCase extends MaintenanceTestCase
{
    protected function point(float $longitude, float $latitude, array $attributes = [], ?Organization $organization = null): Tree
    {
        $tree = new Tree();
        $tree->forceFill(array_replace(['organization_id' => ($organization ?? $this->organization)->id,
            'code' => 'MAP-'.strtoupper(Str::random(12)), 'current_status' => 'active',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id], $attributes))->save();
        DB::update('UPDATE trees SET geometry = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?', [$longitude, $latitude, $tree->id]);

        return $tree;
    }

    protected function bbox(array $extra = []): array
    {
        return array_replace(['west' => -74, 'south' => 3, 'east' => -73, 'north' => 5, 'zoom' => 15], $extra);
    }
}

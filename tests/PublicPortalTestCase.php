<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Evidence;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeInspection;
use Illuminate\Support\Str;

/** Publicación sintética aislada en sysforest_testing; no sustituye el servicio administrativo. */
abstract class PublicPortalTestCase extends MapTestCase
{
    protected function publishOrganization(?Organization $organization = null): Organization
    {
        $organization ??= $this->organization;
        $organization->forceFill(['is_public' => true, 'public_code' => $organization->public_code ?? $organization->code])->save();

        if ($this->organization->is($organization)) {
            $this->organization->refresh();
        }

        return $organization->refresh();
    }

    protected function publishInventory(?Inventory $inventory = null): Inventory
    {
        $inventory ??= $this->inventory;
        $inventory->forceFill(['is_public' => true])->save();

        return $inventory->refresh();
    }

    protected function publishTree(?Tree $tree = null, ?Inventory $inventory = null): Tree
    {
        $tree ??= $this->tree;
        $inventory ??= $this->inventory;
        $this->publishOrganization($tree->organization);
        $this->publishInventory($inventory);
        if (! $tree->inventories()->whereKey($inventory->id)->exists()) {
            $tree->inventories()->attach($inventory->id, [
                'id' => (string) Str::uuid(), 'organization_id' => $tree->organization_id,
                'included_at' => now(), 'included_by' => $this->admin->id,
            ]);
        }
        $tree->forceFill(['is_public' => true])->save();

        return $tree->refresh();
    }

    protected function publishInspection(TreeInspection $inspection): TreeInspection
    {
        $inspection->forceFill(['is_public' => true])->save();

        return $inspection->refresh();
    }

    protected function publishEvidence(Evidence $evidence, ?string $caption = null): Evidence
    {
        $evidence->forceFill(['is_public' => true, 'public_id' => $evidence->public_id ?? (string) Str::ulid(),
            'public_caption' => $caption])->save();

        return $evidence->refresh();
    }

    protected function publicTreeUrl(?Tree $tree = null): string
    {
        $tree ??= $this->tree;

        return '/portal/organizations/'.$tree->organization->public_code.'/trees/'.$tree->code;
    }
}

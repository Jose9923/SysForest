<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Condition;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Services\TreeInspectionService;
use App\Services\TreeService;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\ConditionCatalogSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Database\Seeders\TaxonomySeeder;

/** Fixtures exclusivamente transaccionales sobre sysforest_testing. */
abstract class InspectionTestCase extends PostgresTestCase
{
    protected Organization $organization;
    protected User $admin;
    protected Inventory $inventory;
    protected Tree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->pilot();
        $this->admin = User::factory()->create();
        $this->member($this->admin, $this->organization);
        $this->allowManagement($this->admin);
        $this->seed([ForestryCatalogSeeder::class, MaintenanceCatalogSeeder::class, TaxonomySeeder::class,
            ConditionCatalogSeeder::class, ClassificationRuleSeeder::class]);
        $this->activateInspectionTenant();
        $this->inventory = app(InventoryService::class)->create(
            $this->admin,
            ['code' => 'INSPECTION-TEST', 'name' => 'Campaña de inspección', 'status' => 'active', 'active' => true]
        );
        $this->tree = app(TreeService::class)->create($this->admin, ['inventory_ids' => [$this->inventory->id], 'current_status' => 'active']);
    }

    protected function activateInspectionTenant(?User $actor = null, ?Organization $organization = null): void
    {
        app(TenantContextService::class)->activate($actor ?? $this->admin, ($organization ?? $this->organization)->id);
    }

    protected function capture(array $overrides = [], ?User $actor = null, ?Tree $tree = null): TreeInspection
    {
        return app(TreeInspectionService::class)->create(
            $actor ?? $this->admin,
            ($tree ?? $this->tree)->id,
            array_replace($this->inspectionInput(), $overrides)
        );
    }

    protected function inspectionInput(): array
    {
        return ['inspection_date' => '2026-09-12', 'cap_cm' => '154', 'number_of_stems' => 3,
            'form_factor' => '0.5', 'total_height_m' => '12', 'commercial_height_m' => '0.8',
            'crown_x_m' => '4.125', 'crown_y_m' => '3.75'];
    }

    protected function condition(string $code): Condition
    {
        return Condition::query()->where('code', $code)->sole();
    }
}

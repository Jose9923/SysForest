<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TreeStatus;
use App\Exceptions\StaleRecordException;
use App\Models\DeletionReason;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Species;
use App\Models\Tree;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Services\TreeLifecycleService;
use App\Services\TreeReadService;
use App\Services\TreeService;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class TreeDomainTest extends PostgresTestCase
{
    private Organization $organization;
    private User $admin;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->pilot();
        $this->admin = User::factory()->create();
        $this->member($this->admin, $this->organization);
        $this->allowManagement($this->admin);
        $this->seed([ForestryCatalogSeeder::class, MaintenanceCatalogSeeder::class, TaxonomySeeder::class]);
        $this->activate();
        $this->inventory = app(InventoryService::class)->create($this->admin, ['code' => 'TEST-2026', 'name' => 'Campaña de pruebas', 'status' => 'active', 'active' => true]);
    }

    public function test_tree_has_uuid_permanent_identity_and_independent_nullable_fields(): void
    {
        $tree = $this->create();
        $this->assertTrue(Str::isUuid($tree->id));
        $this->assertSame('COL-MET-VVC-INEM-000001', $tree->code);
        $this->assertSame($this->organization->id, $tree->organization_id);
        $this->assertSame($this->admin->id, $tree->created_by);
        $this->assertSame(1, $tree->lock_version);
        $this->assertNull($tree->species_id);
        $this->assertNull($tree->geometry);
        $this->assertNull($tree->datum);
        $this->assertNull($tree->gps_model);
        $this->assertSame([$this->inventory->id], $tree->inventories->modelKeys());
        $this->assertDatabaseHas('tree_status_histories', ['tree_id' => $tree->id, 'from_status' => null, 'to_status' => 'active']);
        foreach (['inventory_id', 'family_id', 'cap_cm', 'dap_m', 'maturity_state_id', 'total_height_m'] as $column) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('trees', $column));
        }
    }

    public function test_taxonomy_and_both_origin_dimensions_remain_separate(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $establishment = DB::table('establishment_origins')->where('code', 'EST_PLANTED')->value('id');
        $biogeographic = DB::table('biogeographic_origins')->where('code', 'BIO_NATIVE')->value('id');
        $tree = $this->create(['species_id' => $species->id, 'establishment_origin_id' => $establishment,
            'biogeographic_origin_id' => $biogeographic, 'external_number' => '0007-A',
            'location_description' => 'Andén del bloque A', 'is_protected' => true, 'is_heritage' => true]);
        $this->assertSame($species->family_id, $tree->species->family->id);
        $this->assertSame('Oití', $tree->species->commonNames->sole()->name);
        $this->assertSame('0007-A', $tree->external_number);
        $this->assertSame($establishment, $tree->establishment_origin_id);
        $this->assertSame($biogeographic, $tree->biogeographic_origin_id);
        $this->assertSame(TreeStatus::Active, $tree->current_status);
        $this->assertTrue($tree->is_protected && $tree->is_heritage);
        $this->assertNull($tree->geometry);
    }

    public function test_same_tree_can_join_multiple_campaigns_without_duplicate_memberships(): void
    {
        $tree = $this->create();
        $second = app(InventoryService::class)->create($this->admin, ['code' => 'SECOND', 'name' => 'Otra campaña', 'status' => 'active', 'active' => true]);
        $result = app(TreeService::class)->include($this->admin, $tree->id, ['inventory_ids' => [$second->id], 'lock_version' => 1]);
        $result = app(TreeService::class)->include($this->admin, $tree->id, ['inventory_ids' => [$second->id], 'lock_version' => 2]);
        $this->assertCount(2, $result->inventories);
        $this->assertSame($tree->id, $result->id);
        $this->assertDatabaseCount('trees', 1);
        $this->assertDatabaseCount('inventory_trees', 2);
        $this->assertSame(1, app(TreeReadService::class)->index($this->admin, ['inventory_id' => $second->id])['trees']->total());
    }

    public function test_officials_collaborate_without_changing_original_author(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->member($a, $this->organization);
        $this->member($b, $this->organization);
        $this->activate($a);
        $tree = app(TreeService::class)->create($a, $this->input());
        $this->activate($b);
        $edited = app(TreeService::class)->update($b, $tree->id, ['location_description' => 'Revisado por otro funcionario', 'lock_version' => 1]);
        $this->assertSame($a->id, $edited->created_by);
        $this->assertSame($b->id, $edited->updated_by);
        $this->assertSame(2, $edited->lock_version);
        $this->assertSame($tree->code, $edited->code);
    }

    public function test_official_cannot_edit_an_individual_without_an_active_campaign(): void
    {
        $tree = $this->create();
        app(InventoryService::class)->update($this->admin, $this->inventory->id, ['code' => $this->inventory->code, 'name' => $this->inventory->name, 'status' => 'closed', 'active' => true, 'lock_version' => 1]);
        $actor = User::factory()->create();
        $this->member($actor, $this->organization);
        $this->activate($actor);
        $this->expectException(AuthorizationException::class);
        app(TreeService::class)->update($actor, $tree->id, ['location_description' => 'No permitido', 'lock_version' => 1]);
    }

    public function test_create_is_atomic_when_gps_validation_fails(): void
    {
        $this->assertValidation(fn () => $this->create(['coordinate_raw' => '4, -73; DROP TABLE trees']));
        foreach (['trees', 'inventory_trees', 'tree_status_histories', 'tree_location_histories', 'organization_sequences'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('COL-MET-VVC-INEM-000001', $this->create()->code);
    }

    public function test_reference_to_another_tenant_is_rejected_and_every_creation_is_rolled_back(): void
    {
        $other = $this->anotherOrganization();
        $this->member($this->admin, $other);
        $this->allowManagement($this->admin);
        $this->activate($this->admin, $other);
        $inventory = app(InventoryService::class)->create($this->admin, ['code' => 'OTHER', 'name' => 'Ajeno', 'status' => 'active', 'active' => true]);
        $this->activate();
        $this->assertValidation(fn () => $this->create(['inventory_ids' => [$inventory->id]]));
        $this->assertDatabaseCount('trees', 0);
        $this->assertDatabaseCount('organization_sequences', 0);
    }

    #[DataProvider('lifecycleStates')]
    public function test_lifecycle_transitions_keep_history_and_never_soft_delete(string $state): void
    {
        $tree = $this->create();
        $changed = app(TreeLifecycleService::class)->status($this->admin, $tree->id, ['current_status' => $state, 'reason' => 'Verificación de campo', 'lock_version' => 1]);
        $this->assertSame($state, $changed->current_status->value);
        $this->assertNull($changed->deleted_at);
        $this->assertSame(2, $changed->lock_version);
        $this->assertDatabaseHas('tree_status_histories', ['tree_id' => $tree->id, 'from_status' => 'active', 'to_status' => $state, 'changed_by' => $this->admin->id]);
        $this->assertDatabaseCount('tree_status_histories', 2);
    }

    public static function lifecycleStates(): array
    {
        return array_map(fn ($state) => [$state], ['under_observation', 'dead', 'felled', 'transplanted', 'removed', 'replaced']);
    }

    public function test_no_op_status_does_not_fabricate_a_transition(): void
    {
        $tree = $this->create();
        app(TreeLifecycleService::class)->status($this->admin, $tree->id, ['current_status' => 'active', 'lock_version' => 1]);
        $this->assertDatabaseCount('tree_status_histories', 1);
    }

    public function test_void_restore_preserve_identity_campaigns_and_nonrecyclable_code(): void
    {
        $tree = $this->create();
        $void = app(TreeLifecycleService::class)->void($this->admin, $tree->id, $this->voidInput());
        $this->assertSoftDeleted('trees', ['id' => $tree->id]);
        $this->assertSame(TreeStatus::Active, $void->current_status);
        $this->assertSame(0, app(TreeReadService::class)->index($this->admin)['trees']->total());
        $this->assertSame(1, app(TreeReadService::class)->index($this->admin, ['trashed' => 'only'])['trees']->total());
        $next = $this->create();
        $this->assertSame('COL-MET-VVC-INEM-000002', $next->code);
        $restored = app(TreeLifecycleService::class)->restore($this->admin, $tree->id, ['lock_version' => 2]);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->deleted_by);
        $this->assertNull($restored->deletion_reason_id);
        $this->assertSame($tree->id, $restored->id);
        $this->assertSame($tree->code, $restored->code);
        $this->assertSame($tree->created_by, $restored->created_by);
        $this->assertSame($this->admin->id, $restored->restored_by);
        $this->assertSame(3, $restored->lock_version);
        $this->assertCount(1, $restored->inventories);
        foreach (['tree.created', 'tree.voided', 'tree.restored'] as $event) {
            $this->assertDatabaseHas('security_events', ['event' => $event, 'organization_id' => $this->organization->id]);
        }
    }

    public function test_void_requires_a_valid_active_reason(): void
    {
        $tree = $this->create();
        $this->assertValidation(fn () => app(TreeLifecycleService::class)->void($this->admin, $tree->id, ['lock_version' => 1]));
        $reason = DeletionReason::query()->firstOrFail();
        $reason->update(['active' => false]);
        $this->assertValidation(fn () => app(TreeLifecycleService::class)->void($this->admin, $tree->id, ['lock_version' => 1, 'deletion_reason_id' => $reason->id]));
        $this->assertNotSoftDeleted('trees', ['id' => $tree->id]);
    }

    public function test_stale_edit_cannot_overwrite_new_data_or_create_history(): void
    {
        $tree = $this->create();
        app(TreeService::class)->update($this->admin, $tree->id, ['external_number' => 'FIRST', 'lock_version' => 1]);
        try {
            app(TreeService::class)->update($this->admin, $tree->id, ['external_number' => 'STALE', 'lock_version' => 1]);
            $this->fail('Se aceptó una versión obsoleta.');
        } catch (StaleRecordException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.', $exception->getMessage());
        }
        $this->assertSame('FIRST', $tree->fresh()->external_number);
        $this->assertSame(2, $tree->fresh()->lock_version);
        $this->assertDatabaseCount('tree_status_histories', 1);
    }

    #[DataProvider('internalAttributes')]
    public function test_mass_assignment_cannot_replace_internal_identity(string $key, mixed $value): void
    {
        $tree = $this->create();
        $this->assertValidation(fn () => app(TreeService::class)->update($this->admin, $tree->id, [$key => $value, 'lock_version' => 1]));
        $this->assertSame($tree->code, $tree->fresh()->code);
        $this->assertSame(1, $tree->fresh()->lock_version);
    }

    public static function internalAttributes(): array
    {
        return [['organization_id', '11111111-1111-4111-8111-111111111111'], ['code', 'HACK-001'], ['created_by', 99],
            ['updated_by', 99], ['family_id', '11111111-1111-4111-8111-111111111111'], ['geometry', 'POINT(0 0)'],
            ['coordinate_format', 'decimal'], ['deleted_at', '2026-09-12']];
    }

    public function test_database_prevents_cross_tenant_memberships_and_duplicate_codes(): void
    {
        $tree = $this->create();
        $other = $this->anotherOrganization();
        $otherInventory = new Inventory();
        $otherInventory->forceFill(['organization_id' => $other->id, 'code' => 'OTHER', 'name' => 'Otra organización'])->save();
        $operations = [
            fn () => DB::table('inventory_trees')->insert(['id' => Str::uuid(), 'organization_id' => $other->id, 'inventory_id' => $otherInventory->id, 'tree_id' => $tree->id, 'included_at' => now(), 'included_by' => $this->admin->id]),
            fn () => DB::table('trees')->where('id', $tree->id)->update(['organization_id' => $other->id]),
            fn () => DB::table('trees')->where('id', $tree->id)->update(['code' => 'CHANGED']),
            fn () => DB::table('trees')->where('id', $tree->id)->update(['created_by' => null]),
            fn () => DB::table('trees')->insert(['id' => Str::uuid(), 'organization_id' => $this->organization->id, 'code' => $tree->code, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]),
            fn () => DB::table('tree_status_histories')->where('tree_id', $tree->id)->update(['to_status' => 'dead']),
            fn () => DB::table('trees')->where('id', $tree->id)->delete(),
        ];
        foreach ($operations as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('La base aceptó una operación incompatible.');
            } catch (QueryException) {
                $this->assertDatabaseHas('trees', ['id' => $tree->id, 'organization_id' => $this->organization->id]);
            }
        }
    }

    public function test_existing_inactive_species_is_preserved_but_cannot_be_newly_selected(): void
    {
        $species = Species::query()->firstOrFail();
        $tree = $this->create(['species_id' => $species->id]);
        $species->update(['active' => false]);
        $updated = app(TreeService::class)->update($this->admin, $tree->id, ['species_id' => $species->id, 'lock_version' => 1]);
        $this->assertSame($species->id, $updated->species_id);
        $this->assertValidation(fn () => $this->create(['species_id' => $species->id]));
    }

    public function test_tree_filters_search_common_name_and_do_not_leak_other_campaigns(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $tree = $this->create(['species_id' => $species->id]);
        $this->create();
        $reader = app(TreeReadService::class);
        foreach ([['q' => 'Oití'], ['q' => 'Licania'], ['species_id' => $species->id], ['q' => $tree->code]] as $filters) {
            $this->assertSame([$tree->id], $reader->index($this->admin, $filters)['trees']->getCollection()->modelKeys());
        }
        $this->assertSame(0, $reader->index($this->admin, ['inventory_id' => (string) Str::uuid()])['trees']->total());
        $this->assertSame(0, $reader->index($this->admin, ['q' => '%'])['trees']->total());
    }

    #[DataProvider('malformedVersions')]
    public function test_direct_services_reject_malformed_versions_without_side_effects(mixed $version): void
    {
        $tree = $this->create();
        $voided = $this->create();
        app(TreeLifecycleService::class)->void($this->admin, $voided->id, $this->voidInput());
        $operations = [
            fn () => app(TreeService::class)->update($this->admin, $tree->id, ['lock_version' => $version, 'external_number' => 'DENIED']),
            fn () => app(TreeService::class)->include($this->admin, $tree->id, ['lock_version' => $version, 'inventory_ids' => [$this->inventory->id]]),
            fn () => app(TreeLifecycleService::class)->location($this->admin, $tree->id, ['lock_version' => $version, 'coordinate_raw' => '4, -73', 'location_change_type' => 'correction', 'location_reason' => 'No guardar']),
            fn () => app(TreeLifecycleService::class)->status($this->admin, $tree->id, ['lock_version' => $version, 'current_status' => 'dead']),
            fn () => app(TreeLifecycleService::class)->void($this->admin, $tree->id, array_replace($this->voidInput(), ['lock_version' => $version])),
            fn () => app(TreeLifecycleService::class)->restore($this->admin, $voided->id, ['lock_version' => $version]),
        ];
        foreach ($operations as $operation) {
            $this->assertValidation($operation);
        }
        $this->assertSame(1, $tree->fresh()->lock_version);
        $this->assertNull($tree->fresh()->external_number);
        $this->assertNotSoftDeleted('trees', ['id' => $tree->id]);
        $this->assertSoftDeleted('trees', ['id' => $voided->id]);
        $this->assertDatabaseCount('tree_location_histories', 0);
        $this->assertDatabaseCount('tree_status_histories', 2);
    }

    public static function malformedVersions(): array
    {
        return [['1abc'], [1.9], [true], [0], [null], [[]], [2147483647]];
    }

    private function activate(?User $actor = null, ?Organization $organization = null): void
    {
        app(TenantContextService::class)->activate($actor ?? $this->admin, ($organization ?? $this->organization)->id);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['inventory_ids' => [$this->inventory->id], 'current_status' => 'active'], $extra);
    }

    private function create(array $extra = []): Tree
    {
        return app(TreeService::class)->create($this->admin, $this->input($extra));
    }

    private function voidInput(): array
    {
        return ['lock_version' => 1, 'deletion_reason_id' => DeletionReason::query()->where('code', 'DEL_DUPLICATE')->value('id'), 'deletion_notes' => 'Duplicado confirmado'];
    }

    private function assertValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La entrada debía rechazarse.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}

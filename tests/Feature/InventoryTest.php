<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryStatus;
use App\Exceptions\StaleRecordException;
use App\Models\Inventory;
use App\Models\InventoryTree;
use App\Models\Organization;
use App\Models\SecurityEvent;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\PilotInventorySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\PostgresTestCase;

class InventoryTest extends PostgresTestCase
{
    private Organization $organization;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->organization = $this->pilot();
        $this->actor = User::factory()->create();
        $this->member($this->actor, $this->organization);
        $this->allowManagement($this->actor);
        app(TenantContextService::class)->activate($this->actor, $this->organization->id);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_create_and_collaborative_update_keep_original_author_and_increment_version(): void
    {
        $service = app(InventoryService::class);
        $inventory = $service->create($this->actor, $this->input());
        $this->assertTrue(Str::isUuid($inventory->id));
        $this->assertSame($this->organization->id, $inventory->organization_id);
        $this->assertSame((int) $this->actor->id, (int) $inventory->created_by);
        $this->assertSame(1, $inventory->lock_version);
        $this->assertSame(InventoryStatus::Active, $inventory->status);

        $editor = User::factory()->create();
        $this->member($editor, $this->organization);
        $this->allowManagement($editor);
        app(TenantContextService::class)->activate($editor, $this->organization->id);
        $updated = $service->update($editor, $inventory->id, $this->input(['name' => 'Campaña corregida', 'lock_version' => 1]));

        $this->assertSame($inventory->id, $updated->id);
        $this->assertSame((int) $this->actor->id, (int) $updated->created_by);
        $this->assertSame((int) $editor->id, (int) $updated->updated_by);
        $this->assertSame(2, $updated->lock_version);
        $this->assertSame('Campaña corregida', $updated->name);
    }

    public function test_old_version_cannot_overwrite_a_saved_update(): void
    {
        $service = app(InventoryService::class);
        $inventory = $service->create($this->actor, $this->input());
        $service->update($this->actor, $inventory->id, $this->input(['name' => 'Cambio vigente', 'lock_version' => 1]));

        try {
            $service->update($this->actor, $inventory->id, $this->input(['name' => 'Cambio obsoleto', 'lock_version' => 1]));
            $this->fail('La versión obsoleta no puede sobrescribir la campaña.');
        } catch (StaleRecordException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Cambio vigente', $inventory->fresh()->name);
            $this->assertSame(2, $inventory->fresh()->lock_version);
        }
    }

    public function test_repository_compares_version_in_the_update_statement(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $repository = app(InventoryRepository::class);
        $first = $repository->updateWithVersion($this->organization->id, $inventory->id, 1, ['name' => 'Ganador']);
        $second = $repository->updateWithVersion($this->organization->id, $inventory->id, 1, ['name' => 'Perdedor']);

        $this->assertSame(2, $first->lock_version);
        $this->assertNull($second);
        $this->assertSame('Ganador', $inventory->fresh()->name);
    }

    public function test_codes_are_unique_per_organization_but_can_exist_in_another_organization(): void
    {
        $service = app(InventoryService::class);
        $inventory = $service->create($this->actor, $this->input());
        $other = $this->anotherOrganization($this->organization);
        $otherInventory = $this->inventoryFor($other, $inventory->code);
        $this->assertSame($inventory->code, $otherInventory->code);

        $this->expectException(ValidationException::class);
        $service->create($this->actor, $this->input());
    }

    public function test_database_rejects_duplicate_codes_in_one_organization(): void
    {
        app(InventoryService::class)->create($this->actor, $this->input());
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        $this->inventoryFor($this->organization, 'TEST-INVENTORY');
    }

    public function test_foreign_inventory_uuid_does_not_resolve_in_the_current_tenant(): void
    {
        $other = $this->inventoryFor($this->anotherOrganization($this->organization), 'FOREIGN-INVENTORY');
        $this->expectException(ModelNotFoundException::class);
        app(InventoryService::class)->show($this->actor, $other->id);
    }

    public function test_owner_and_authorship_cannot_be_injected(): void
    {
        $this->expectException(ValidationException::class);
        app(InventoryService::class)->create($this->actor, $this->input([
            'organization_id' => $this->anotherOrganization($this->organization)->id,
            'created_by' => User::factory()->create()->id,
        ]));
    }

    public function test_database_protects_original_author(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $other = User::factory()->create();
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('inventories')->where('id', $inventory->id)->update(['created_by' => $other->id]);
    }

    public function test_database_protects_inventory_tenant_identity(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $other = $this->anotherOrganization($this->organization);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('inventories')->where('id', $inventory->id)->update(['organization_id' => $other->id]);
    }

    public function test_optional_dates_allow_unknown_start_and_blank_input(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input([
            'starts_at' => '', 'ends_at' => '2027-12-31',
        ]));

        $this->assertNull($inventory->starts_at);
        $this->assertSame('2027-12-31', $inventory->ends_at->format('Y-m-d'));
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $this->expectException(ValidationException::class);
        app(InventoryService::class)->create($this->actor, $this->input([
            'starts_at' => '2027-03-02', 'ends_at' => '2027-03-01',
        ]));
    }

    public function test_database_enforces_date_order(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(InventoryRepository::class)->create(array_merge($this->input([
            'starts_at' => '2027-03-02', 'ends_at' => '2027-03-01',
        ]), ['organization_id' => $this->organization->id, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]));
    }

    public function test_database_rejects_an_unknown_inventory_status(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('inventories')->where('id', $inventory->id)->update(['status' => 'unknown']);
    }

    public function test_operational_options_require_active_status_and_administrative_availability(): void
    {
        $repository = app(InventoryRepository::class);
        $eligible = $this->inventoryFor($this->organization, 'OPTION-ACTIVE');
        $draft = $this->inventoryFor($this->organization, 'OPTION-DRAFT', ['status' => 'draft']);
        $closed = $this->inventoryFor($this->organization, 'OPTION-CLOSED', ['status' => 'closed']);
        $disabled = $this->inventoryFor($this->organization, 'OPTION-DISABLED', ['active' => false]);
        $ids = [$eligible->id, $draft->id, $closed->id, $disabled->id];

        $this->assertSame([$eligible->id], $repository->options($this->organization->id, true)->whereIn('id', $ids)->pluck('id')->all());
        $this->assertCount(4, $repository->options($this->organization->id)->whereIn('id', $ids));
    }

    public function test_listing_filters_remain_scoped_and_accept_boolean_active_values(): void
    {
        $active = $this->inventoryFor($this->organization, 'FILTER-ACTIVE', ['name' => 'Coincidencia 20%']);
        $inactive = $this->inventoryFor($this->organization, 'FILTER-INACTIVE', ['name' => 'Coincidencia 20%', 'active' => false]);
        $this->inventoryFor($this->organization, 'FILTER-PLAIN', ['name' => 'Coincidencia sin símbolo']);
        $this->inventoryFor($this->anotherOrganization($this->organization), 'FILTER-FOREIGN', ['name' => 'Coincidencia 20%']);
        foreach ([[1, $active->id], [true, $active->id], [0, $inactive->id], [false, $inactive->id]] as [$filter, $expected]) {
            $page = app(InventoryService::class)->index($this->actor, ['q' => '%', 'active' => $filter])['inventories'];
            $this->assertSame([$expected], $page->getCollection()->pluck('id')->all());
        }
    }

    public function test_pilot_seed_preserves_identity_and_administrative_changes_without_creating_trees(): void
    {
        $this->seed(PilotInventorySeeder::class);
        $pilot = Inventory::query()->where('organization_id', $this->organization->id)->where('code', 'PILOT-SYSFOREST')->sole();
        $treeCount = Tree::withTrashed()->count();
        $this->assertNull($pilot->created_by);
        $this->assertSame('Inventario Forestal Institucional - Piloto SysForest', $pilot->name);
        app(InventoryService::class)->update($this->actor, $pilot->id, $this->input([
            'code' => $pilot->code, 'name' => 'Piloto ajustado', 'status' => 'closed', 'active' => false,
            'lock_version' => $pilot->lock_version,
        ]));
        $this->seed(PilotInventorySeeder::class);
        $this->seed(PilotInventorySeeder::class);

        $this->assertSame($pilot->id, Inventory::query()->where('organization_id', $this->organization->id)->where('code', 'PILOT-SYSFOREST')->sole()->id);
        $this->assertSame('Piloto ajustado', $pilot->fresh()->name);
        $this->assertFalse($pilot->fresh()->active);
        $this->assertSame(InventoryStatus::Closed, $pilot->fresh()->status);
        $this->assertNull($pilot->fresh()->created_by);
        $this->assertSame($treeCount, Tree::withTrashed()->count());
    }

    public function test_one_tree_can_join_multiple_campaigns_and_repeated_attachment_is_idempotent(): void
    {
        $first = $this->inventoryFor($this->organization, 'CAMPAIGN-ONE');
        $second = $this->inventoryFor($this->organization, 'CAMPAIGN-TWO');
        $tree = $this->treeFor($this->organization);
        $repository = app(InventoryRepository::class);
        $initial = $repository->attach($this->organization->id, $first->id, $tree->id, (int) $this->actor->id, 'Inclusión original');
        $repeat = $repository->attach($this->organization->id, $first->id, $tree->id, (int) User::factory()->create()->id, 'Reintento');
        $repository->attach($this->organization->id, $second->id, $tree->id, (int) $this->actor->id);

        $this->assertSame($initial->id, $repeat->id);
        $this->assertSame('Inclusión original', $repeat->notes);
        $this->assertSame((int) $this->actor->id, (int) $repeat->included_by);
        $this->assertSame(2, $tree->inventories()->count());
        $this->assertSame(1, $repository->findForTenant($this->organization->id, $first->id)->trees_count);
    }

    public function test_repository_rejects_a_tree_from_another_tenant(): void
    {
        $inventory = $this->inventoryFor($this->organization, 'LOCAL-CAMPAIGN');
        $tree = $this->treeFor($this->anotherOrganization($this->organization));
        $this->expectException(ModelNotFoundException::class);
        app(InventoryRepository::class)->attach($this->organization->id, $inventory->id, $tree->id, (int) $this->actor->id);
    }

    public function test_database_rejects_a_cross_tenant_tree_inclusion(): void
    {
        $inventory = $this->inventoryFor($this->organization, 'LOCAL-CAMPAIGN');
        $tree = $this->treeFor($this->anotherOrganization($this->organization));
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        InventoryTree::query()->create([
            'organization_id' => $this->organization->id, 'inventory_id' => $inventory->id,
            'tree_id' => $tree->id, 'included_by' => $this->actor->id, 'included_at' => now(),
        ]);
    }

    public function test_database_rejects_a_cross_tenant_inventory_inclusion(): void
    {
        $inventory = $this->inventoryFor($this->anotherOrganization($this->organization), 'FOREIGN-CAMPAIGN');
        $tree = $this->treeFor($this->organization);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        InventoryTree::query()->create([
            'organization_id' => $this->organization->id, 'inventory_id' => $inventory->id,
            'tree_id' => $tree->id, 'included_by' => $this->actor->id, 'included_at' => now(),
        ]);
    }

    public function test_database_rejects_duplicate_inclusions(): void
    {
        $inventory = $this->inventoryFor($this->organization, 'LOCAL-CAMPAIGN');
        $tree = $this->treeFor($this->organization);
        $repository = app(InventoryRepository::class);
        $inclusion = $repository->attach($this->organization->id, $inventory->id, $tree->id, (int) $this->actor->id);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        InventoryTree::query()->create($inclusion->only(['organization_id', 'inventory_id', 'tree_id', 'included_at', 'included_by']));
    }

    public function test_audit_is_tenant_scoped_and_part_of_the_inventory_transaction(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $event = SecurityEvent::query()->where('event', 'inventory.created')->where('metadata->resource_id', $inventory->id)->sole();

        $this->assertSame($this->organization->id, $event->organization_id);
        $this->assertSame((int) $this->actor->id, (int) $event->actor_user_id);
        $this->assertSame($inventory->code, $event->metadata['resource_code']);
    }

    public function test_creation_rolls_back_when_audit_fails(): void
    {
        $this->mock(SecurityEventRepository::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Auditoría no disponible'));
        try {
            app(InventoryService::class)->create($this->actor, $this->input());
            $this->fail('La operación debía revertirse al fallar la auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Auditoría no disponible', $exception->getMessage());
            $this->assertDatabaseMissing('inventories', ['organization_id' => $this->organization->id, 'code' => 'TEST-INVENTORY']);
        }
    }

    public function test_http_create_edit_and_stale_update_use_real_csrf(): void
    {
        $this->actingTenant($this->actor, $this->organization);
        $token = $this->csrf();
        $this->get(route('inventories.create'))->assertOk();
        $this->post(route('inventories.store'), $this->input(['_token' => $token]))->assertRedirect();
        $inventory = Inventory::query()->where('organization_id', $this->organization->id)->where('code', 'TEST-INVENTORY')->sole();
        $this->get(route('inventories.show', $inventory->id))->assertOk()->assertSee('Campaña de prueba');
        $this->get(route('inventories.edit', $inventory->id))->assertOk();
        $this->put(route('inventories.update', $inventory->id), $this->input([
            'name' => 'Versión vigente', 'lock_version' => 1, '_token' => $token,
        ]))->assertRedirect(route('inventories.show', $inventory->id));
        $this->putJson(route('inventories.update', $inventory->id), $this->input([
            'name' => 'Versión obsoleta', 'lock_version' => 1, '_token' => $token,
        ]))->assertConflict();
        $this->assertSame('Versión vigente', $inventory->fresh()->name);
    }

    public function test_http_mutations_without_csrf_do_not_create_or_update(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $this->actingTenant($this->actor, $this->organization);
        $this->csrf();
        $this->post(route('inventories.store'), $this->input(['code' => 'NO-CSRF']))->assertStatus(419);
        $this->put(route('inventories.update', $inventory->id), $this->input(['lock_version' => 1]))->assertStatus(419);

        $this->assertDatabaseMissing('inventories', ['organization_id' => $this->organization->id, 'code' => 'NO-CSRF']);
        $this->assertSame(1, $inventory->fresh()->lock_version);
    }

    public function test_http_foreign_inventory_uuid_returns_not_found_for_read_and_write(): void
    {
        $other = $this->inventoryFor($this->anotherOrganization($this->organization), 'FOREIGN-CAMPAIGN');
        $this->actingTenant($this->actor, $this->organization);
        $token = $this->csrf();
        $this->get(route('inventories.show', $other->id))->assertNotFound()->assertDontSee($other->name);
        $this->get(route('inventories.edit', $other->id))->assertNotFound();
        $this->putJson(route('inventories.update', $other->id), $this->input(['lock_version' => 1, '_token' => $token]))->assertNotFound();
    }

    public function test_inventory_code_is_permanent_after_creation(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        try {
            app(InventoryService::class)->update($this->actor, $inventory->id, $this->input([
                'code' => 'RENAMED', 'lock_version' => 1,
            ]));
            $this->fail('No se puede modificar la identidad estable de una campaña.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
            $this->assertSame('TEST-INVENTORY', $inventory->fresh()->code);
            $this->assertSame(1, $inventory->fresh()->lock_version);
        }
    }

    public function test_database_also_protects_the_inventory_code(): void
    {
        $inventory = app(InventoryService::class)->create($this->actor, $this->input());
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('inventories')->where('id', $inventory->id)->update(['code' => 'RENAMED']);
    }

    public function test_service_validation_messages_are_spanish_and_not_translation_keys(): void
    {
        try {
            app(InventoryService::class)->create($this->actor, []);
            $this->fail('Faltan campos obligatorios de la campaña.');
        } catch (ValidationException $exception) {
            $this->assertSame('Este campo es obligatorio.', $exception->errors()['code'][0]);
            $this->assertStringNotContainsString('validation.', $exception->getMessage());
        }
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'code' => 'TEST-INVENTORY', 'name' => 'Campaña de prueba', 'description' => null,
            'starts_at' => null, 'ends_at' => null, 'status' => 'active', 'active' => true,
        ], $overrides);
    }

    private function inventoryFor(Organization $organization, string $code, array $overrides = []): Inventory
    {
        return app(InventoryRepository::class)->create(array_merge($this->input(['code' => $code]), [
            'organization_id' => $organization->id, 'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id, 'lock_version' => 1,
        ], $overrides));
    }

    private function treeFor(Organization $organization): Tree
    {
        $tree = new Tree();
        $tree->forceFill([
            'organization_id' => $organization->id, 'code' => 'TEST-TREE-'.strtoupper(Str::random(10)),
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ])->save();

        return $tree;
    }

    private function csrf(): string
    {
        $response = $this->get('/login')->assertOk();
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null) {
            $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        }
        preg_match('/name="_token"\s+value="([^"]+)"/', (string) $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}

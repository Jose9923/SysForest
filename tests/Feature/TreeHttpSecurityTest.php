<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DeletionReason;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\TenantContextService;
use App\Services\TreeService;
use App\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class TreeHttpSecurityTest extends PostgresTestCase
{
    private Organization $organization;
    private User $author;
    private Inventory $inventory;
    private Tree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AuthorizationSeeder::class, ForestryCatalogSeeder::class, MaintenanceCatalogSeeder::class]);
        $this->organization = $this->pilot();
        $this->author = $this->actor('ORGANIZATION_ADMIN');
        $this->inventory = $this->inventoryFor($this->organization);
        app(TenantContextService::class)->activate($this->author, $this->organization->id);
        $this->tree = app(TreeService::class)->create($this->author, [
            'inventory_ids' => [$this->inventory->id], 'current_status' => 'active',
            'external_number' => 'ORIGINAL-HTTP',
        ]);
        // Activa el middleware CSRF real: el fixture solo evita su excepción especial para tests.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    #[DataProvider('roles')]
    public function test_four_roles_read_and_mutate_only_with_their_real_permissions(string $role, bool $treeWrite, bool $administrative): void
    {
        $actor = $this->actor($role);
        $this->actingTenant($actor, $this->organization);
        $token = $this->csrf();
        $this->get(route('trees.index'))->assertOk()->assertSee($this->tree->code);
        $this->get(route('trees.show', $this->tree->id))->assertOk()->assertSee('ORIGINAL-HTTP');
        $this->get(route('inventories.index'))->assertOk()->assertSee($this->inventory->name);
        $this->get(route('inventories.show', $this->inventory->id))->assertOk();
        $this->get(route('trees.create'))->assertStatus($treeWrite ? 200 : 403);
        $this->get(route('trees.edit', $this->tree->id))->assertStatus($treeWrite ? 200 : 403);

        $this->putJson(route('trees.update', $this->tree->id), [
            '_token' => $token, 'external_number' => 'COLLABORATIVE-HTTP', 'lock_version' => 1,
        ])->assertStatus($treeWrite ? 302 : 403);
        $this->assertSame($treeWrite ? 'COLLABORATIVE-HTTP' : 'ORIGINAL-HTTP', $this->tree->fresh()->external_number);
        $this->assertSame((int) $this->author->id, (int) $this->tree->fresh()->created_by);
        $this->postJson(route('trees.store'), [
            '_token' => $token, 'inventory_ids' => [$this->inventory->id], 'current_status' => 'active',
        ])->assertStatus($treeWrite ? 302 : 403);
        $this->get(route('inventories.create'))->assertStatus($administrative ? 200 : 403);
        $this->postJson(route('inventories.store'), [
            '_token' => $token, 'code' => 'HTTP-CAMPAIGN', 'name' => 'Nueva campaña HTTP',
            'status' => 'active', 'active' => true,
        ])->assertStatus($administrative ? 302 : 403);
        $this->assertFalse(app(TenantContext::class)->isActive());
        if ($role === 'SUPER_ADMIN' || $role === 'DEPARTMENT_ADMIN') {
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        }
    }

    public static function roles(): array
    {
        return [
            ['SUPER_ADMIN', true, true], ['DEPARTMENT_ADMIN', false, false],
            ['ORGANIZATION_ADMIN', true, true], ['OFFICIAL', true, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_institutional_and_national_administrators_can_void_and_restore(string $role, bool $treeWrite, bool $administrative): void
    {
        $actor = $this->actor($role);
        $voided = $this->treeFor($this->organization, true);
        $this->actingTenant($actor, $this->organization);
        $token = $this->csrf();
        $this->deleteJson(route('trees.void', $this->tree->id), [
            '_token' => $token, 'lock_version' => 1, 'deletion_reason_id' => $this->reason(),
        ])->assertStatus($administrative ? 302 : 403);
        $this->postJson(route('trees.restore', $voided->id), [
            '_token' => $token, 'lock_version' => 1,
        ])->assertStatus($administrative ? 302 : 403);

        $this->assertSame($administrative, Tree::withTrashed()->findOrFail($this->tree->id)->trashed());
        $this->assertSame(!$administrative, Tree::withTrashed()->findOrFail($voided->id)->trashed());
        $this->assertSame($voided->code, Tree::withTrashed()->findOrFail($voided->id)->code);
    }

    #[DataProvider('foreignOperations')]
    public function test_foreign_tree_uuid_returns_404_for_every_read_and_mutation(string $method, string $route): void
    {
        $foreign = $this->treeFor($this->anotherOrganization($this->organization), $route === 'trees.restore');
        $this->actingTenant($this->author, $this->organization);
        $token = $this->csrf();
        $input = [
            '_token' => $token, 'lock_version' => 1, 'external_number' => 'INTRUSION',
            'current_status' => 'dead', 'coordinate_raw' => '4.123, -73.123',
            'location_change_type' => 'correction', 'location_reason' => 'Sin permiso',
            'deletion_reason_id' => $this->reason(), 'inventory_ids' => [$this->inventory->id],
        ];
        $response = $method === 'GET'
            ? $this->get(route($route, $foreign->id))
            : $this->json($method, route($route, $foreign->id), $input);
        $response->assertNotFound()->assertDontSee($foreign->code);
        $persisted = Tree::withTrashed()->findOrFail($foreign->id);
        $this->assertSame(1, $persisted->lock_version);
        $this->assertSame($foreign->organization_id, $persisted->organization_id);
        $this->assertNull($persisted->external_number);
        $this->assertDatabaseMissing('inventory_trees', ['tree_id' => $foreign->id]);
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public static function foreignOperations(): array
    {
        return [
            ['GET', 'trees.show'], ['GET', 'trees.edit'], ['PUT', 'trees.update'],
            ['PATCH', 'trees.status'], ['PATCH', 'trees.location'], ['DELETE', 'trees.void'],
            ['POST', 'trees.restore'], ['POST', 'trees.inventories'],
        ];
    }

    public function test_foreign_campaign_injection_is_rejected_without_partial_creation_or_attachment(): void
    {
        $foreign = $this->inventoryFor($this->anotherOrganization($this->organization));
        $this->actingTenant($this->author, $this->organization);
        $token = $this->csrf();
        $this->postJson(route('trees.store'), [
            '_token' => $token, 'current_status' => 'active', 'inventory_ids' => [$foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_ids');
        $this->postJson(route('trees.inventories', $this->tree->id), [
            '_token' => $token, 'lock_version' => 1, 'inventory_ids' => [$foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_ids');

        $this->assertDatabaseCount('trees', 1);
        $this->assertDatabaseCount('tree_status_histories', 1);
        $this->assertDatabaseCount('inventory_trees', 1);
        $this->assertSame(1, $this->tree->fresh()->lock_version);
        $this->assertSame(1, (int) DB::table('organization_sequences')->where('organization_id', $this->organization->id)->value('value'));
    }

    public function test_list_search_and_campaign_filter_never_include_foreign_trees(): void
    {
        $foreign = $this->treeFor($this->anotherOrganization($this->organization));
        $this->actingTenant($this->author, $this->organization);
        $this->get(route('trees.index'))->assertOk()->assertSee($this->tree->code)->assertDontSee($foreign->code);
        $this->get(route('trees.index', ['q' => $foreign->code]))->assertOk()
            ->assertViewHas('trees', fn ($trees): bool => $trees->total() === 0)
            ->assertDontSee(route('trees.show', $foreign->id));
        $this->get(route('trees.index', ['inventory_id' => $this->inventory->id]))
            ->assertOk()->assertSee($this->tree->code)->assertDontSee($foreign->code);
    }

    public function test_official_loses_edit_permission_when_the_last_active_campaign_closes(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $token = $this->csrf();
        $this->get(route('trees.edit', $this->tree->id))->assertOk();
        $this->inventory->forceFill(['status' => 'closed'])->save();
        $this->get(route('trees.edit', $this->tree->id))->assertForbidden();
        $this->putJson(route('trees.update', $this->tree->id), [
            '_token' => $token, 'lock_version' => 1, 'external_number' => 'NO-WRITE',
        ])->assertForbidden();
        $this->get(route('trees.show', $this->tree->id))->assertOk();
        $this->assertSame('ORIGINAL-HTTP', $this->tree->fresh()->external_number);
    }

    public function test_revoked_membership_invalidates_the_selection_on_the_next_http_request(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $token = $this->csrf();
        $this->get(route('trees.show', $this->tree->id))->assertOk();
        $actor->memberships()->where('organization_id', $this->organization->id)->update(['active' => false, 'left_at' => now()]);
        $this->putJson(route('trees.update', $this->tree->id), [
            '_token' => $token, 'lock_version' => 1, 'external_number' => 'REVOKED',
        ])->assertForbidden()->assertSessionMissing('active_organization_id');
        $this->assertSame(1, $this->tree->fresh()->lock_version);
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_revoked_national_assignment_cannot_reuse_its_selected_tenant(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->actingTenant($actor, $this->organization);
        $this->get(route('trees.show', $this->tree->id))->assertOk();
        DB::table('national_role_assignments')->where('user_id', $actor->id)->update(['active' => false, 'revoked_at' => now()]);
        $this->getJson(route('trees.show', $this->tree->id))->assertForbidden()->assertSessionMissing('active_organization_id');
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_disabled_account_cannot_reuse_a_valid_signed_access_cookie(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $this->get(route('trees.show', $this->tree->id))->assertOk();
        User::query()->whereKey($actor->id)->update(['active' => false]);
        $this->getJson(route('trees.show', $this->tree->id))->assertUnauthorized();
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_superadmin_without_selection_cannot_read_or_create_tenant_resources(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->actingAs($actor);
        $token = $this->csrf();
        $this->getJson(route('trees.index'))->assertForbidden();
        $this->postJson(route('trees.store'), [
            '_token' => $token, 'current_status' => 'active', 'inventory_ids' => [$this->inventory->id],
        ])->assertForbidden();
        $this->assertDatabaseCount('trees', 1);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    public function test_csrf_is_required_for_all_tree_mutations(): void
    {
        $this->actingTenant($this->author, $this->organization);
        $this->csrf();
        foreach ([
            ['POST', 'trees.store', null], ['PUT', 'trees.update', $this->tree->id],
            ['PATCH', 'trees.status', $this->tree->id], ['PATCH', 'trees.location', $this->tree->id],
            ['DELETE', 'trees.void', $this->tree->id], ['POST', 'trees.restore', $this->tree->id],
            ['POST', 'trees.inventories', $this->tree->id],
        ] as [$method, $name, $id]) {
            $this->json($method, route($name, $id), ['lock_version' => 1])->assertStatus(419);
        }
        $this->assertSame(1, $this->tree->fresh()->lock_version);
        $this->assertDatabaseCount('trees', 1);
        $this->assertDatabaseCount('tree_status_histories', 1);
    }

    public function test_old_http_version_cannot_overwrite_a_committed_update(): void
    {
        $this->actingTenant($this->author, $this->organization);
        $token = $this->csrf();
        $this->putJson(route('trees.update', $this->tree->id), [
            '_token' => $token, 'lock_version' => 1, 'external_number' => 'CURRENT',
        ])->assertRedirect();
        $this->patchJson(route('trees.status', $this->tree->id), [
            '_token' => $token, 'lock_version' => 1, 'current_status' => 'dead',
        ])->assertConflict();
        $this->assertSame('CURRENT', $this->tree->fresh()->external_number);
        $this->assertSame('active', $this->tree->fresh()->current_status->value);
        $this->assertDatabaseCount('tree_status_histories', 1);
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $assignments = app(RoleAssignmentRepository::class);
        if ($role === 'SUPER_ADMIN') {
            $assignments->grantNational((int) $actor->id);
        } elseif ($role === 'DEPARTMENT_ADMIN') {
            $assignments->grantDepartment((int) $actor->id, $this->organization->municipality->department_id);
        } else {
            $assignments->grantOrganization($this->member($actor, $this->organization), $role);
        }

        return $actor;
    }

    private function inventoryFor(Organization $organization): Inventory
    {
        return app(InventoryRepository::class)->create([
            'organization_id' => $organization->id, 'code' => 'HTTP-'.strtoupper(Str::random(10)),
            'name' => 'Campaña HTTP '.Str::random(8), 'status' => 'active', 'active' => true,
            'created_by' => $this->author->id, 'updated_by' => $this->author->id,
        ]);
    }

    private function treeFor(Organization $organization, bool $deleted = false): Tree
    {
        $tree = new Tree();
        $tree->forceFill([
            'organization_id' => $organization->id, 'code' => 'HTTP-TREE-'.strtoupper(Str::random(10)),
            'created_by' => $this->author->id, 'updated_by' => $this->author->id,
            'deleted_at' => $deleted ? now() : null, 'deleted_by' => $deleted ? $this->author->id : null,
            'deletion_reason_id' => $deleted ? $this->reason() : null,
        ])->save();

        return $tree;
    }

    private function reason(): string
    {
        return DeletionReason::query()->where('code', 'DEL_DUPLICATE')->value('id');
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeLocationHistory;
use App\Models\TreeStatusHistory;
use App\Models\User;
use App\Policies\InventoryPolicy;
use App\Policies\TreePolicy;
use App\Repositories\InventoryRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\ForestryAccessService;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class ForestryAccessTest extends PostgresTestCase
{
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->organization = $this->pilot();
    }

    #[DataProvider('rolePermissions')]
    public function test_each_role_has_only_the_approved_forestry_permissions(string $role, bool $inventoryWrite, bool $treeWrite, bool $void): void
    {
        $actor = $this->actor($role);
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $access = app(ForestryAccessService::class);

        $this->assertTrue($access->allows($actor, 'inventories.view'));
        $this->assertTrue($access->allows($actor, 'trees.view'));
        $this->assertSame($inventoryWrite, $access->allows($actor, 'inventories.create'));
        $this->assertSame($inventoryWrite, $access->allows($actor, 'inventories.update'));
        $this->assertSame($treeWrite, $access->allows($actor, 'trees.create'));
        $this->assertSame($treeWrite, $access->allows($actor, 'trees.update'));
        $this->assertSame($void, $access->allows($actor, 'trees.void'));
        $this->assertSame($void, $access->allows($actor, 'trees.restore'));
    }

    public static function rolePermissions(): array
    {
        return [
            ['SUPER_ADMIN', true, true, true], ['DEPARTMENT_ADMIN', false, false, false],
            ['ORGANIZATION_ADMIN', true, true, true], ['OFFICIAL', false, true, false],
        ];
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_non_administrators_cannot_create_inventories_through_services(string $role): void
    {
        $actor = $this->actor($role);
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $this->expectException(AuthorizationException::class);
        app(InventoryService::class)->create($actor, [
            'code' => 'FORBIDDEN', 'name' => 'Campaña no autorizada', 'status' => 'active', 'active' => true,
        ]);
    }

    public static function nonAdministrativeRoles(): array
    {
        return [['DEPARTMENT_ADMIN'], ['OFFICIAL']];
    }

    public function test_even_superadmin_needs_an_explicit_selected_tenant_and_no_artificial_membership(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $access = app(ForestryAccessService::class);
        $this->assertFalse($access->allows($actor, 'trees.create'));
        app(TenantContextService::class)->activate($actor, $this->organization->id);

        $this->assertSame('national', app(TenantContext::class)->accessMode());
        $this->assertTrue($access->allows($actor, 'trees.create'));
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    public function test_a_different_actor_cannot_reuse_an_active_tenant_context(): void
    {
        $owner = $this->actor('ORGANIZATION_ADMIN');
        $other = $this->actor('ORGANIZATION_ADMIN');
        app(TenantContextService::class)->activate($owner, $this->organization->id);

        $this->assertFalse(app(ForestryAccessService::class)->allows($other, 'trees.update'));
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_revoked_membership_removes_forestry_permissions_from_existing_context(): void
    {
        $actor = $this->actor('OFFICIAL');
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $this->assertTrue(app(ForestryAccessService::class)->allows($actor, 'trees.create'));
        $actor->memberships()->firstOrFail()->update(['active' => false, 'left_at' => now()]);

        $this->assertFalse(app(ForestryAccessService::class)->allows($actor, 'trees.create'));
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_revoked_role_and_disabled_account_are_revalidated(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $this->assertTrue(app(ForestryAccessService::class)->allows($actor, 'trees.update'));
        DB::table('national_role_assignments')->where('user_id', $actor->id)->update(['active' => false, 'revoked_at' => now()]);
        $this->assertFalse(app(ForestryAccessService::class)->allows($actor, 'trees.update'));
        app(RoleAssignmentRepository::class)->grantNational((int) $actor->id);
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        User::query()->whereKey($actor->id)->update(['active' => false]);

        $this->assertFalse(app(ForestryAccessService::class)->allows($actor, 'trees.update'));
    }

    public function test_department_policy_reads_its_tenant_but_never_writes(): void
    {
        $actor = $this->actor('DEPARTMENT_ADMIN');
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $inventory = $this->inventoryFor($this->organization, $actor);
        $tree = $this->treeFor($this->organization, $actor);
        $treePolicy = app(TreePolicy::class);
        $inventoryPolicy = app(InventoryPolicy::class);

        $this->assertTrue($treePolicy->view($actor, $tree));
        $this->assertTrue($inventoryPolicy->view($actor, $inventory));
        $this->assertFalse($treePolicy->update($actor, $tree));
        $this->assertFalse($treePolicy->void($actor, $tree));
        $this->assertFalse($inventoryPolicy->update($actor, $inventory));
        $this->assertFalse($treePolicy->forceDelete($actor, $tree));
    }

    public function test_policies_reject_foreign_resources_even_for_superadmin(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $other = $this->anotherOrganization($this->organization);
        $tree = $this->treeFor($other, $actor);
        $inventory = $this->inventoryFor($other, $actor);

        $this->assertFalse(app(TreePolicy::class)->view($actor, $tree));
        $this->assertFalse(app(TreePolicy::class)->update($actor, $tree));
        $this->assertFalse(app(TreePolicy::class)->void($actor, $tree));
        $this->assertFalse(app(InventoryPolicy::class)->view($actor, $inventory));
        $this->assertFalse(app(InventoryPolicy::class)->update($actor, $inventory));
    }

    public function test_official_can_edit_another_officials_tree_only_during_an_active_campaign(): void
    {
        $actor = $this->actor('OFFICIAL');
        $author = $this->actor('OFFICIAL');
        $tree = $this->treeFor($this->organization, $author);
        $inventory = $this->inventoryFor($this->organization, $actor);
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $policy = app(TreePolicy::class);
        $this->assertFalse($policy->update($actor, $tree));
        app(InventoryRepository::class)->attach($this->organization->id, $inventory->id, $tree->id, (int) $actor->id);
        $this->assertTrue($policy->update($actor, $tree));
        $this->assertNotSame((int) $actor->id, (int) $tree->created_by);
        $inventory->forceFill(['status' => 'closed'])->save();
        $this->assertFalse($policy->update($actor, $tree));
        $this->assertTrue($policy->view($actor, $tree));
        $this->assertFalse($policy->void($actor, $tree));
        $this->assertFalse($policy->restore($actor, $tree));
    }

    public function test_department_mode_does_not_gain_write_through_an_accidental_permission_assignment(): void
    {
        $actor = $this->actor('DEPARTMENT_ADMIN');
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $role = DB::table('roles')->where('name', 'DEPARTMENT_ADMIN')->value('id');
        $permission = DB::table('permissions')->where('code', 'trees.update')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);

        $this->assertFalse(app(ForestryAccessService::class)->allows($actor, 'trees.update'));
    }

    public function test_status_history_foreign_key_rejects_a_tree_from_another_tenant(): void
    {
        $actor = $this->actor('ORGANIZATION_ADMIN');
        $tree = $this->treeFor($this->anotherOrganization($this->organization), $actor);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        TreeStatusHistory::query()->create([
            'organization_id' => $this->organization->id, 'tree_id' => $tree->id, 'from_status' => null,
            'to_status' => 'active', 'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
    }

    public function test_location_history_foreign_key_rejects_a_tree_from_another_tenant(): void
    {
        $actor = $this->actor('ORGANIZATION_ADMIN');
        $tree = $this->treeFor($this->anotherOrganization($this->organization), $actor);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        TreeLocationHistory::query()->create([
            'organization_id' => $this->organization->id, 'tree_id' => $tree->id,
            'old_metadata' => (object) [], 'new_metadata' => (object) [], 'change_type' => null,
            'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
    }

    public function test_history_records_cannot_be_rewritten(): void
    {
        $actor = $this->actor('ORGANIZATION_ADMIN');
        $tree = $this->treeFor($this->organization, $actor);
        $history = TreeStatusHistory::query()->create([
            'organization_id' => $this->organization->id, 'tree_id' => $tree->id, 'from_status' => null,
            'to_status' => 'active', 'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('tree_status_histories')->where('id', $history->id)->update(['to_status' => 'dead']);
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

    private function inventoryFor(Organization $organization, User $actor): Inventory
    {
        return app(InventoryRepository::class)->create([
            'organization_id' => $organization->id, 'code' => 'INV-'.strtoupper(Str::random(10)),
            'name' => 'Campaña de prueba', 'status' => 'active', 'active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function treeFor(Organization $organization, User $actor): Tree
    {
        $tree = new Tree();
        $tree->forceFill([
            'organization_id' => $organization->id, 'code' => 'TREE-'.strtoupper(Str::random(10)),
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ])->save();

        return $tree;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\TreeReadScope;
use App\Models\DeletionReason;
use App\Models\Department;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\MapScopeRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\MapAccessService;
use App\Services\MapTreeNavigationService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Database\Seeders\MaintenanceCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PostgresTestCase;

class MapScopeSecurityTest extends PostgresTestCase
{
    private Organization $organization;
    private Organization $neighbor;
    private Organization $foreign;
    private User $author;
    private Tree $tree;
    private Tree $neighborTree;
    private Tree $foreignTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->pilot();
        $this->neighbor = $this->anotherOrganization($this->organization);
        $department = Department::create([
            'country_id' => $this->organization->municipality->department->country_id,
            'code' => 'MAP-'.strtoupper(Str::random(6)), 'name' => 'Departamento externo de prueba', 'active' => true,
        ]);
        $municipality = Municipality::create([
            'department_id' => $department->id, 'code' => 'MAP-'.strtoupper(Str::random(6)),
            'name' => 'Municipio externo de prueba', 'active' => true,
        ]);
        $input = $this->organizationInput($this->organization);
        $input['municipality_id'] = $municipality->id;
        $this->foreign = app(OrganizationRepository::class)->create($input);
        $this->author = $this->actor('ORGANIZATION_ADMIN');
        $this->tree = $this->treeFor($this->organization);
        $this->neighborTree = $this->treeFor($this->neighbor);
        $this->foreignTree = $this->treeFor($this->foreign);
        app(TenantContext::class)->clear();
    }

    #[DataProvider('organizationRoles')]
    public function test_organization_roles_only_read_the_explicitly_selected_organization(string $role): void
    {
        $actor = $this->actor($role);
        $this->select($actor);
        $scope = app(MapAccessService::class)->resolve($actor);

        $this->assertSame('organization', $scope->kind);
        $this->assertSame($this->organization->id, $scope->organizationId);
        $this->assertSame([$this->tree->id], $this->treeIds($scope));
        $this->assertSame([$this->organization->id], app(MapScopeRepository::class)->organizations($scope)->pluck('id')->all());
        $this->assertSame($this->organization->id, app(TenantContext::class)->organizationId());
        $this->assertTrue(app(MapAccessService::class)->allows($actor, $scope, 'inspections.view'));
        $this->assertTrue(app(MapAccessService::class)->allows($actor, $scope, 'maintenances.view'));
    }

    public static function organizationRoles(): array
    {
        return [['OFFICIAL'], ['ORGANIZATION_ADMIN']];
    }

    public function test_department_read_needs_no_selection_and_never_creates_a_membership_or_write_permission(): void
    {
        $actor = $this->actor('DEPARTMENT_ADMIN');
        $scope = app(MapAccessService::class)->resolve($actor);

        $this->assertSame('department', $scope->kind);
        $this->assertEqualsCanonicalizing([$this->tree->id, $this->neighborTree->id], $this->treeIds($scope));
        $this->assertFalse(app(TenantContext::class)->isActive());
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);

        $opened = app(MapTreeNavigationService::class)->open($actor, $this->neighborTree->id);
        $this->assertSame($this->neighborTree->id, $opened->id);
        $this->assertSame($this->neighbor->id, app(TenantContext::class)->organizationId());
        $this->assertSame('department_read', app(TenantContext::class)->accessMode());
        $this->assertTrue(Gate::forUser($actor)->allows('view', $opened));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $opened));
        $this->assertFalse(Gate::forUser($actor)->allows('create', Tree::class));
    }

    public function test_national_map_does_not_fabricate_tenant_context_or_memberships(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $scope = app(MapAccessService::class)->resolve($actor);

        $this->assertSame('national', $scope->kind);
        $this->assertCount(3, $this->treeIds($scope));
        $this->assertFalse(app(TenantContext::class)->isActive());
        $this->assertFalse(Gate::forUser($actor)->allows('create', Tree::class));
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    public function test_national_navigation_selects_the_real_owner_and_preserves_the_existing_policy(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $tree = app(MapTreeNavigationService::class)->open($actor, $this->foreignTree->id);

        $this->assertSame($this->foreignTree->id, $tree->id);
        $this->assertSame($this->foreign->id, app(TenantContext::class)->organizationId());
        $this->assertSame('national', app(TenantContext::class)->accessMode());
        $this->assertTrue(Gate::forUser($actor)->allows('view', $tree));
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    #[DataProvider('unavailableKinds')]
    public function test_an_explicit_unauthorized_scope_never_silently_falls_back(string $kind): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->select($actor);
        $this->expectException(AuthorizationException::class);
        app(MapAccessService::class)->resolve($actor, $kind);
    }

    public static function unavailableKinds(): array
    {
        return [['department'], ['national'], ['all'], [''], ['ORGANIZATION']];
    }

    public function test_organization_member_without_selection_cannot_read_a_map(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->assertFalse(app(MapAccessService::class)->canView($actor));
        $this->expectException(AuthorizationException::class);
        app(MapAccessService::class)->resolve($actor);
    }

    public function test_a_different_actor_cannot_reuse_the_selected_tenant(): void
    {
        $this->select($this->author);
        $actor = $this->actor('OFFICIAL');

        $this->assertFalse(app(MapAccessService::class)->canView($actor));
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_a_structural_scope_does_not_grant_access_even_to_another_membership_of_the_same_actor(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->member($actor, $this->neighbor);
        $this->select($actor);
        $access = app(MapAccessService::class);

        $this->assertFalse($access->allows($actor, TreeReadScope::organization($this->neighbor->id), 'trees.view'));
        $this->assertFalse($access->allows($actor, TreeReadScope::department([$this->departmentId()]), 'trees.view'));
        $this->assertFalse($access->allows($actor, TreeReadScope::national(), 'trees.view'));
    }

    #[DataProvider('writePermissions')]
    public function test_map_capabilities_never_accept_write_permissions(string $permission): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->assertFalse(app(MapAccessService::class)->allows($actor, TreeReadScope::national(), $permission));
    }

    public static function writePermissions(): array
    {
        return [['trees.update'], ['maintenances.verify'], ['organizations.manage'], ['*']];
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_stale_user_objects_do_not_retain_map_access_after_account_changes(array $changes): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $scope = app(MapAccessService::class)->resolve($actor);
        User::query()->whereKey($actor->id)->update($changes);

        $this->assertFalse(app(MapAccessService::class)->allows($actor, $scope, 'trees.view'));
        $this->assertFalse(app(MapAccessService::class)->canView($actor));
    }

    public static function ineligibleAccounts(): array
    {
        return [[['active' => false]], [['email_verified_at' => null]]];
    }

    public function test_membership_revocation_invalidates_an_already_resolved_scope(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->select($actor);
        $scope = app(MapAccessService::class)->resolve($actor);
        DB::table('organization_memberships')->where('user_id', $actor->id)
            ->update(['active' => false, 'left_at' => now()]);

        $this->assertFalse(app(MapAccessService::class)->allows($actor, $scope, 'trees.view'));
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    #[DataProvider('revokedGrants')]
    public function test_role_revocation_is_rechecked_after_scope_resolution(string $role, string $table): void
    {
        $actor = $this->actor($role);
        if ($role === 'OFFICIAL') {
            $this->select($actor);
        }
        $scope = app(MapAccessService::class)->resolve($actor);
        $query = DB::table($table);
        if ($role === 'OFFICIAL') {
            $query->where('membership_id', DB::table('organization_memberships')->where('user_id', $actor->id)->value('id'));
        } else {
            $query->where('user_id', $actor->id);
        }
        $query->update(['active' => false, 'revoked_at' => now()]);

        $this->assertFalse(app(MapAccessService::class)->allows($actor, $scope, 'trees.view'));
        $this->assertFalse(app(MapAccessService::class)->canView($actor));
        $this->expectException(AuthorizationException::class);
        app(MapTreeNavigationService::class)->open($actor, $this->tree->id);
    }

    public static function revokedGrants(): array
    {
        return [
            ['OFFICIAL', 'organization_role_assignments'],
            ['DEPARTMENT_ADMIN', 'department_role_assignments'],
            ['SUPER_ADMIN', 'national_role_assignments'],
        ];
    }

    public function test_all_departments_in_a_reused_scope_must_still_be_authorized(): void
    {
        $actor = $this->actor('DEPARTMENT_ADMIN');
        $foreignDepartmentId = $this->foreign->municipality->department_id;
        app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $foreignDepartmentId);
        $scope = app(MapAccessService::class)->resolve($actor);
        $this->assertCount(2, $scope->departmentIds);
        $this->assertCount(3, $this->treeIds($scope));
        DB::table('department_role_assignments')->where('user_id', $actor->id)
            ->where('department_id', $foreignDepartmentId)->update(['active' => false, 'revoked_at' => now()]);

        $this->assertFalse(app(MapAccessService::class)->allows($actor, $scope, 'trees.view'));
        $this->assertSame([$this->departmentId()], app(MapAccessService::class)->resolve($actor)->departmentIds);
    }

    #[DataProvider('basePermissions')]
    public function test_broad_grants_require_both_tree_and_organization_read_permission(string $role, string $permission): void
    {
        $actor = $this->actor($role);
        $roleId = Role::query()->where('name', $role)->value('id');
        $permissionId = Permission::query()->where('code', $permission)->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();

        $this->assertFalse(app(MapAccessService::class)->canView($actor));
    }

    public static function basePermissions(): array
    {
        return [
            ['SUPER_ADMIN', 'trees.view'], ['SUPER_ADMIN', 'organizations.view'],
            ['DEPARTMENT_ADMIN', 'trees.view'], ['DEPARTMENT_ADMIN', 'organizations.view'],
        ];
    }

    public function test_inspection_and_maintenance_permissions_remain_independent_of_map_access(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $roleId = Role::query()->where('name', 'SUPER_ADMIN')->value('id');
        $permissionIds = Permission::query()->whereIn('code', ['inspections.view', 'maintenances.view'])->pluck('id');
        DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->delete();
        $access = app(MapAccessService::class);
        $scope = $access->resolve($actor);

        $this->assertTrue($access->allows($actor, $scope, 'trees.view'));
        $this->assertFalse($access->allows($actor, $scope, 'inspections.view'));
        $this->assertFalse($access->allows($actor, $scope, 'maintenances.view'));
    }

    #[DataProvider('inactiveHierarchy')]
    public function test_map_queries_exclude_inactive_and_voided_territorial_owners(string $table, string $field): void
    {
        $id = match ($table) {
            'organizations' => $this->organization->id,
            'municipalities' => $this->organization->municipality_id,
            'departments' => $this->departmentId(),
            'countries' => $this->organization->municipality->department->country_id,
        };
        DB::table($table)->where('id', $id)->update([$field => $field === 'deleted_at' ? now() : false]);
        $scope = TreeReadScope::national();

        $this->assertNotContains($this->tree->id, $this->treeIds($scope));
        $this->assertNotContains($this->organization->id, app(MapScopeRepository::class)->organizations($scope)->pluck('id')->all());
    }

    public static function inactiveHierarchy(): array
    {
        return [
            ['organizations', 'active'], ['organizations', 'deleted_at'],
            ['municipalities', 'active'], ['departments', 'active'], ['countries', 'active'],
        ];
    }

    public function test_voided_trees_never_reenter_map_navigation_for_superadmin(): void
    {
        $this->seed(MaintenanceCatalogSeeder::class);
        $this->tree->forceFill([
            'deleted_at' => now(), 'deleted_by' => $this->author->id,
            'deletion_reason_id' => DeletionReason::query()->where('code', 'DEL_DUPLICATE')->sole()->id,
        ])->save();
        $actor = $this->actor('SUPER_ADMIN');
        $scope = app(MapAccessService::class)->resolve($actor);

        $this->assertNotContains($this->tree->id, $this->treeIds($scope));
        $this->expectException(ModelNotFoundException::class);
        app(MapTreeNavigationService::class)->open($actor, $this->tree->id);
    }

    #[DataProvider('foreignNavigationRoles')]
    public function test_foreign_tree_navigation_returns_not_found_without_switching_context(string $role): void
    {
        $actor = $this->actor($role);
        if ($role !== 'DEPARTMENT_ADMIN') {
            $this->select($actor);
        }
        try {
            app(MapTreeNavigationService::class)->open($actor, $this->foreignTree->id);
            $this->fail('El UUID ajeno no debe abrir una ficha.');
        } catch (ModelNotFoundException) {
            $this->assertSame($role !== 'DEPARTMENT_ADMIN', app(TenantContext::class)->isActive());
            if ($role !== 'DEPARTMENT_ADMIN') {
                $this->assertSame($this->organization->id, app(TenantContext::class)->organizationId());
            }
        }
    }

    public static function foreignNavigationRoles(): array
    {
        return [['OFFICIAL'], ['ORGANIZATION_ADMIN'], ['DEPARTMENT_ADMIN']];
    }

    public function test_invalid_uuid_is_not_sent_to_postgresql(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->expectException(ModelNotFoundException::class);
        app(MapTreeNavigationService::class)->open($actor, "' OR 1=1 --");
    }

    public function test_scope_repository_supports_internal_alias_without_join_duplication(): void
    {
        $query = Tree::query()->from('trees as mapped')->select('mapped.*')->withoutGlobalScopes()->whereNull('mapped.deleted_at');
        app(MapScopeRepository::class)->applyTo($query, TreeReadScope::organization($this->organization->id), 'mapped');

        $this->assertSame([$this->tree->id], $query->pluck('mapped.id')->all());
        $this->assertStringContainsString('exists', strtolower($query->toSql()));
    }

    public function test_sql_alias_cannot_be_injected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(MapScopeRepository::class)->applyTo(Tree::query(), TreeReadScope::national(), 'trees) OR true --');
    }

    public function test_department_scope_rejects_an_empty_set_instead_of_becoming_national(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TreeReadScope::department([]);
    }

    public function test_scope_requires_valid_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TreeReadScope::organization('all');
    }

    public function test_navigation_uses_post_csrf_and_only_persists_the_authorized_owner(): void
    {
        $this->enableRealCsrf();
        $actor = $this->actor('DEPARTMENT_ADMIN');
        $this->actingAs($actor);
        $token = $this->csrf();
        $uri = '/map/trees/'.$this->neighborTree->id.'/open';

        $this->get($uri)->assertStatus(405)->assertSessionMissing('active_organization_id');
        $this->postJson($uri)->assertStatus(419)->assertSessionMissing('active_organization_id');
        $this->post($uri, [
            '_token' => $token, 'organization_id' => $this->foreign->id, 'return_url' => 'https://untrusted.invalid',
        ])->assertRedirect(route('trees.show', $this->neighborTree->id))
            ->assertSessionHas('active_organization_id', $this->neighbor->id);
        $this->assertFalse(app(TenantContext::class)->isActive());
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    public function test_foreign_navigation_http_preserves_selection_and_does_not_expose_the_tree(): void
    {
        $this->enableRealCsrf();
        $actor = $this->actor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $token = $this->csrf();
        $this->postJson('/map/trees/'.$this->foreignTree->id.'/open', ['_token' => $token])
            ->assertNotFound()->assertDontSee($this->foreignTree->code)
            ->assertSessionHas('active_organization_id', $this->organization->id);
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_can_view_does_not_hide_infrastructure_failures(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->mock(\App\Services\PermissionService::class, function ($mock): void {
            $mock->shouldReceive('allowsNational')->once()->andThrow(new RuntimeException('Fallo simulado de infraestructura.'));
        });
        $this->expectException(RuntimeException::class);
        app(MapAccessService::class)->canView($actor);
    }

    private function actor(string $role): User
    {
        $this->ensureAuthorizationCatalog();
        $actor = User::factory()->create();
        $assignments = app(RoleAssignmentRepository::class);
        if ($role === 'SUPER_ADMIN') {
            $assignments->grantNational((int) $actor->id);
        } elseif ($role === 'DEPARTMENT_ADMIN') {
            $assignments->grantDepartment((int) $actor->id, $this->departmentId());
        } else {
            $assignments->grantOrganization($this->member($actor, $this->organization), $role);
        }

        return $actor;
    }

    private function select(User $actor): void
    {
        app(TenantContextService::class)->activate($actor, $this->organization->id);
    }

    private function departmentId(): string
    {
        return $this->organization->municipality->department_id;
    }

    private function treeFor(Organization $organization): Tree
    {
        $tree = new Tree();
        $tree->forceFill([
            'organization_id' => $organization->id, 'code' => 'MAP-'.strtoupper(Str::random(12)),
            'created_by' => $this->author->id, 'updated_by' => $this->author->id,
        ])->save();

        return $tree;
    }

    private function treeIds(TreeReadScope $scope): array
    {
        $query = Tree::query();
        app(MapScopeRepository::class)->applyTo($query, $scope);

        return $query->pluck('trees.id')->all();
    }

    private function enableRealCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function csrf(): string
    {
        $response = $this->get('/login')->assertOk();
        if ($cookie = $response->getCookie(config('session.cookie'))) {
            $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        }
        preg_match('/name="_token"\s+value="([^"]+)"/', (string) $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}

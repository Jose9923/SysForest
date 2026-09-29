<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\ReportSelectionDTO;
use App\DTOs\TreeReadScope;
use App\Models\Department;
use App\Models\Municipality;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Repositories\OrganizationRepository;
use App\Services\ReportAccessService;
use App\Services\ReportFilterService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PublicPortalTestCase;

class ReportScopeTest extends PublicPortalTestCase
{
    #[DataProvider('roles')]
    public function test_four_roles_receive_only_their_real_read_scope(string $role, string $kind, int $total): void
    {
        $neighbor = $this->anotherOrganization($this->organization);
        $this->point(-73.5, 4, organization: $neighbor);
        $department = Department::create(['country_id' => $this->organization->municipality->department->country_id,
            'code' => 'RPT-'.Str::upper(Str::random(6)), 'name' => 'Departamento ajeno de reportes', 'active' => true]);
        $municipality = Municipality::create(['department_id' => $department->id, 'code' => 'RPT-'.Str::upper(Str::random(6)),
            'name' => 'Municipio ajeno de reportes', 'active' => true]);
        $foreign = app(OrganizationRepository::class)->create(array_replace($this->organizationInput($this->organization), ['municipality_id' => $municipality->id]));
        $this->point(-72, 5, organization: $foreign);
        $actor = $this->maintenanceActor($role);
        app(TenantContext::class)->clear();
        if ($kind === 'organization') {
            $this->activateInspectionTenant($actor);
        }
        $selection = app(ReportFilterService::class)->forUser($actor, [], 'inventory', true);

        $this->assertSame($kind, $selection->scope->kind);
        $this->assertFalse($selection->isPublic());
        $this->assertTrue(Gate::forUser($actor)->allows('reports.view'));
        $this->assertSame($total, app(ReportRepository::class)->aggregate($selection, 'inventory')['totalTrees']);
        if ($kind !== 'organization') {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }
    }

    public static function roles(): array
    {
        return [['OFFICIAL', 'organization', 1], ['ORGANIZATION_ADMIN', 'organization', 1], ['DEPARTMENT_ADMIN', 'department', 2], ['SUPER_ADMIN', 'national', 3]];
    }

    public function test_revoked_department_assignment_does_not_authorize_an_older_selection(): void
    {
        $actor = $this->maintenanceActor('DEPARTMENT_ADMIN');
        app(TenantContext::class)->clear();
        $selection = app(ReportFilterService::class)->forUser($actor, [], 'states');
        DB::table('department_role_assignments')->where('user_id', $actor->id)->update(['active' => false, 'revoked_at' => now()]);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($actor, $selection, 'states', true);
    }

    public function test_another_organization_filter_is_not_an_authority(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $this->expectException(HttpException::class);
        app(ReportFilterService::class)->forUser($this->admin, ['organization_id' => $other->id]);
    }

    public function test_structural_national_scope_does_not_grant_national_permission(): void
    {
        $current = app(ReportFilterService::class)->forUser($this->admin, [], 'states');
        $forged = new ReportSelectionDTO(TreeReadScope::national(), $current->filters, $current->values);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $forged, 'states', true);
    }

    public function test_existing_selection_is_rejected_after_membership_revocation(): void
    {
        $selection = app(ReportFilterService::class)->forUser($this->admin, [], 'inventory', true);
        DB::table('organization_memberships')->where('user_id', $this->admin->id)->update(['active' => false, 'left_at' => now()]);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, 'inventory', true);
    }

    public function test_existing_selection_is_rejected_after_account_deactivation(): void
    {
        $selection = app(ReportFilterService::class)->forUser($this->admin, [], 'states');
        User::query()->whereKey($this->admin->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, 'states');
    }

    #[DataProvider('requiredPermissions')]
    public function test_each_report_permission_is_revalidated(string $permission, string $report, bool $export): void
    {
        $selection = app(ReportFilterService::class)->forUser($this->admin, [], $report, $export);
        $this->removePermission('ORGANIZATION_ADMIN', $permission);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, $report, $export);
    }

    public static function requiredPermissions(): array
    {
        return [['reports.view', 'states', false], ['reports.export', 'states', true],
            ['trees.view', 'states', false], ['inspections.view', 'diameter_classes', false],
            ['maintenances.view', 'maintenances', false], ['evidences.view', 'inventory', true]];
    }

    public function test_view_permission_does_not_require_export_permission(): void
    {
        $this->removePermission('ORGANIZATION_ADMIN', 'reports.export');
        $selection = app(ReportFilterService::class)->forUser($this->admin, [], 'states');
        $this->assertSame('organization', $selection->scope->kind);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, 'states', true);
    }

    public function test_changing_selected_organization_does_not_retarget_a_resolved_report(): void
    {
        $selection = app(ReportFilterService::class)->forUser($this->admin, [], 'states');
        $other = $this->anotherOrganization($this->organization);
        $this->member($this->admin, $other);
        app(TenantContextService::class)->activate($this->admin, $other->id);
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, 'states');
    }

    public function test_filter_permissions_apply_even_to_a_tree_only_report(): void
    {
        $selection = app(ReportFilterService::class)->forUser($this->admin, ['date_from' => '2026-01-01'], 'states');
        $this->removePermission('ORGANIZATION_ADMIN', 'inspections.view');
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, 'states');
    }

    public function test_navigation_controls_are_removed_but_radius_and_false_remain(): void
    {
        $selection = app(ReportFilterService::class)->forUser($this->admin, $this->bbox([
            'report' => 'states', 'format' => 'csv', 'page' => 20, 'render_mode' => 'heat', 'term' => 'ignorado',
            'radius_latitude' => 4, 'radius_longitude' => -73.5, 'radius_meters' => 500,
            'is_protected' => false, 'sort' => 'code', 'direction' => 'desc',
        ]), 'states');

        $this->assertNull($selection->filters->bbox);
        $this->assertSame(500.0, $selection->filters->radius['meters']);
        $this->assertFalse($selection->values['is_protected']);
        $this->assertSame('desc', $selection->values['direction']);
        foreach (['report', 'format', 'page', 'render_mode', 'term', 'zoom', 'west', 'south', 'east', 'north'] as $key) {
            $this->assertArrayNotHasKey($key, $selection->values);
        }
    }

    public function test_public_selection_is_anonymous_and_preserves_public_codes(): void
    {
        $this->publishTree();
        app(TenantContext::class)->clear();
        $selection = app(ReportFilterService::class)->forPublic(['organization_id' => $this->organization->public_code], 'states');

        $this->assertTrue($selection->isPublic());
        $this->assertSame($this->organization->public_code, $selection->values['organization_id']);
        $this->assertSame($this->organization->id, $selection->filters->trees->get('organization_id'));
        $this->assertFalse(app(TenantContext::class)->isActive());
        $this->assertSame(1, app(ReportRepository::class)->aggregate($selection, 'states')['totalTrees']);
    }

    public function test_public_scope_cannot_be_used_as_a_private_authorization_shortcut(): void
    {
        $selection = app(ReportFilterService::class)->forPublic([], 'states');
        $this->expectException(AuthorizationException::class);
        app(ReportAccessService::class)->authorize($this->admin, $selection, 'states', true);
    }

    #[DataProvider('internalFilters')]
    public function test_public_input_rejects_internal_scope_and_deleted_record_flags(string $field, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        app(ReportFilterService::class)->forPublic([$field => $value]);
    }

    public static function internalFilters(): array
    {
        return [['scope', 'national'], ['trashed', 'only'], ['include_voided_trees', true], ['tree_id', 'arbitrary']];
    }

    #[DataProvider('privateUnknownFilters')]
    public function test_private_reports_reject_unknown_or_internal_flags(string $field): void
    {
        $this->expectException(ValidationException::class);
        app(ReportFilterService::class)->forUser($this->admin, [$field => '1'], 'states');
    }

    public static function privateUnknownFilters(): array
    {
        return [['trashed'], ['include_deleted'], ['include_voided_trees'], ['tree_id'], ['arbitrary_column']];
    }

    public function test_zones_is_explicitly_unavailable_instead_of_using_an_unrelated_field(): void
    {
        $this->expectException(ValidationException::class);
        app(ReportFilterService::class)->forPublic([], 'zones');
    }

    public function test_permission_seeder_is_idempotent_for_both_report_permissions(): void
    {
        $before = Permission::query()->whereIn('code', ['reports.view', 'reports.export'])->orderBy('code')->pluck('id')->all();
        $this->seed(AuthorizationSeeder::class);
        $this->assertCount(2, $before);
        $this->assertSame($before, Permission::query()->whereIn('code', ['reports.view', 'reports.export'])->orderBy('code')->pluck('id')->all());
        foreach (Role::query()->whereIn('name', ['SUPER_ADMIN', 'DEPARTMENT_ADMIN', 'ORGANIZATION_ADMIN', 'OFFICIAL'])->get() as $role) {
            $this->assertSame(2, $role->permissions()->whereIn('code', ['reports.view', 'reports.export'])->count());
        }
    }

    private function removePermission(string $role, string $permission): void
    {
        Role::query()->where('name', $role)->sole()->permissions()->detach(Permission::query()->where('code', $permission)->sole()->id);
    }
}

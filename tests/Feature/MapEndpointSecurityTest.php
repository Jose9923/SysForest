<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Department;
use App\Models\Family;
use App\Models\Inventory;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Species;
use App\Models\SpeciesCommonName;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MapTestCase;

class MapEndpointSecurityTest extends MapTestCase
{
    private Organization $neighbor;
    private Organization $foreign;
    private Tree $ownPoint;
    private Tree $neighborPoint;
    private Tree $foreignPoint;
    private Inventory $neighborInventory;
    private Inventory $foreignInventory;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->ownPoint = $this->point(-73.6, 4.1, [
            'code' => 'ENDPOINT-OWN', 'location_description' => 'PRIVATE_LOCATION_NOTE',
            'coordinate_raw' => '4.100000, -73.600000', 'coordinate_format' => 'decimal',
            'datum' => 'WGS84', 'gps_brand' => 'PRIVATE_GPS_BRAND', 'gps_model' => 'PRIVATE_GPS_MODEL',
            'accuracy_m' => '7.125', 'captured_at' => '2026-09-20 12:34:56+00',
        ]);
        $this->neighborPoint = $this->point(-73.5, 4.2, ['code' => 'ENDPOINT-NEIGHBOR'], $this->neighbor);
        $this->foreignPoint = $this->point(-73.4, 4.3, ['code' => 'ENDPOINT-FOREIGN'], $this->foreign);
        $this->neighborInventory = $this->inventoryFor($this->neighbor, 'ENDPOINT-NEIGHBOR');
        $this->foreignInventory = $this->inventoryFor($this->foreign, 'ENDPOINT-FOREIGN');
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_rejects_anonymous_requests_even_with_generic_accept(string $endpoint): void
    {
        foreach (['application/json', '*/*'] as $accept) {
            $response = $this->get($this->url($endpoint), ['Accept' => $accept]);
            $response->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
            $this->assertSame(['message'], array_keys($response->json()));
            $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
        }
    }

    public static function endpoints(): array
    {
        return [
            ['trees'], ['clusters'], ['heat'], ['extent'], ['search'],
            ['options/organizations'], ['options/inventories'],
        ];
    }

    #[DataProvider('roleScopes')]
    public function test_all_layers_and_lookups_share_the_real_role_scope(string $role, int $count): void
    {
        $actor = $this->maintenanceActor($role);
        $this->authenticate($actor, $role);
        $allowedPoints = [$this->ownPoint->id];
        $allowedOrganizations = [$this->organization->id];
        if ($count >= 2) {
            $allowedPoints[] = $this->neighborPoint->id;
            $allowedOrganizations[] = $this->neighbor->id;
        }
        if ($count === 3) {
            $allowedPoints[] = $this->foreignPoint->id;
            $allowedOrganizations[] = $this->foreign->id;
        }

        foreach (['trees', 'clusters'] as $endpoint) {
            $response = $this->getJson($this->url($endpoint))->assertOk()
                ->assertJsonPath('meta.total', $count)->assertJsonPath('meta.in_view', $count);
            $this->assertEqualsCanonicalizing($allowedPoints, $response->json('features.*.id'));
            $this->assertPrivate($response);
        }
        $heat = $this->getJson($this->url('heat'))->assertOk()->assertJsonPath('meta.total', $count);
        $this->assertPrivate($heat);
        $extent = $this->getJson($this->url('extent'))->assertOk()->assertJsonPath('total', $count);
        $this->assertPrivate($extent);
        $search = $this->getJson($this->url('search'))->assertOk();
        $this->assertEqualsCanonicalizing($allowedPoints, $search->json('results.*.id'));
        $this->assertPrivate($search);

        $organizations = $this->getJson($this->url('options/organizations'))->assertOk();
        foreach ($allowedOrganizations as $id) {
            $this->assertContains($id, $organizations->json('results.*.id'));
        }
        foreach (array_diff([$this->organization->id, $this->neighbor->id, $this->foreign->id], $allowedOrganizations) as $id) {
            $this->assertNotContains($id, $organizations->json('results.*.id'));
        }
        $this->assertPrivate($organizations);
        $inventories = $this->getJson($this->url('options/inventories'))->assertOk();
        $this->assertContains($this->inventory->id, $inventories->json('results.*.id'));
        if ($count < 2) {
            $this->assertNotContains($this->neighborInventory->id, $inventories->json('results.*.id'));
        }
        if ($count < 3) {
            $this->assertNotContains($this->foreignInventory->id, $inventories->json('results.*.id'));
        }
        $this->assertPrivate($inventories);
        $this->assertFalse(app(TenantContext::class)->isActive());
        if (in_array($role, ['SUPER_ADMIN', 'DEPARTMENT_ADMIN'], true)) {
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
            $this->assertNull(session('active_organization_id'));
        }
    }

    public static function roleScopes(): array
    {
        return [['OFFICIAL', 1], ['ORGANIZATION_ADMIN', 1], ['DEPARTMENT_ADMIN', 2], ['SUPER_ADMIN', 3]];
    }

    #[DataProvider('restrictedRoles')]
    public function test_foreign_organization_filter_is_rejected_on_every_endpoint(string $role): void
    {
        $actor = $this->maintenanceActor($role);
        $this->authenticate($actor, $role);
        $organization = $role === 'DEPARTMENT_ADMIN' ? $this->foreign : $this->neighbor;
        foreach (self::endpoints() as [$endpoint]) {
            $response = $this->getJson($this->url($endpoint, ['organization_id' => $organization->id]));
            $response->assertNotFound()->assertDontSee($organization->name);
            $this->assertSame(['message'], array_keys($response->json()));
        }
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public static function restrictedRoles(): array
    {
        return [['OFFICIAL'], ['ORGANIZATION_ADMIN'], ['DEPARTMENT_ADMIN']];
    }

    public function test_department_cannot_enumerate_foreign_department_municipality_or_inventory_references(): void
    {
        $actor = $this->maintenanceActor('DEPARTMENT_ADMIN');
        $this->actingAs($actor);
        foreach ([
            'department_id' => $this->foreign->municipality->department_id,
            'municipality_id' => $this->foreign->municipality_id,
            'inventory_id' => $this->foreignInventory->id,
        ] as $field => $id) {
            foreach (['trees', 'extent', 'options/organizations', 'options/inventories'] as $endpoint) {
                $this->getJson($this->url($endpoint, [$field => $id]))->assertNotFound();
            }
        }
    }

    #[DataProvider('revokedRoles')]
    public function test_revoked_grants_invalidate_all_endpoints_on_the_next_request(string $role, string $table): void
    {
        $actor = $this->maintenanceActor($role);
        $this->authenticate($actor, $role);
        $this->getJson($this->url('trees'))->assertOk();
        $query = DB::table($table);
        if ($role === 'OFFICIAL') {
            $query->where('membership_id', DB::table('organization_memberships')->where('user_id', $actor->id)->value('id'));
        } else {
            $query->where('user_id', $actor->id);
        }
        $query->update(['active' => false, 'revoked_at' => now()]);

        foreach (self::endpoints() as [$endpoint]) {
            $this->getJson($this->url($endpoint))->assertForbidden();
        }
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public static function revokedRoles(): array
    {
        return [
            ['OFFICIAL', 'organization_role_assignments'],
            ['DEPARTMENT_ADMIN', 'department_role_assignments'],
            ['SUPER_ADMIN', 'national_role_assignments'],
        ];
    }

    public function test_revoked_membership_cannot_keep_the_previously_selected_map(): void
    {
        $actor = $this->maintenanceActor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $this->getJson($this->url('trees'))->assertOk();
        DB::table('organization_memberships')->where('user_id', $actor->id)
            ->update(['active' => false, 'left_at' => now()]);

        foreach (self::endpoints() as [$endpoint]) {
            $this->getJson($this->url($endpoint))->assertForbidden()->assertSessionMissing('active_organization_id');
        }
    }

    #[DataProvider('readRoles')]
    public function test_missing_tree_read_permission_blocks_every_endpoint(string $role): void
    {
        $actor = $this->maintenanceActor($role);
        $this->authenticate($actor, $role);
        $this->removePermissions($role, ['trees.view']);

        foreach (self::endpoints() as [$endpoint]) {
            $this->getJson($this->url($endpoint))->assertForbidden();
        }
    }

    public static function readRoles(): array
    {
        return [['OFFICIAL'], ['DEPARTMENT_ADMIN'], ['SUPER_ADMIN']];
    }

    public function test_inspection_and_maintenance_filters_and_options_need_their_own_permissions(): void
    {
        $this->capture(['inspection_date' => '2026-09-20'], $this->admin, $this->ownPoint);
        $actor = $this->maintenanceActor('OFFICIAL');
        $this->removePermissions('OFFICIAL', ['inspections.view', 'maintenances.view']);
        $this->actingTenant($actor, $this->organization);

        $response = $this->getJson($this->url('trees'))->assertOk();
        $this->assertCount(1, $response->json('features'));
        $this->assertNull($response->json('features.0.properties.latest_inspection'));
        foreach ([
            ['date_from' => '2026-01-01'],
            ['physical_condition_ids' => [Condition::query()->where('assessment_type', 'physical_mechanical')->firstOrFail()->id]],
            ['maintenance_status' => 'pending'],
            ['has_pending_maintenance' => 1],
        ] as $filter) {
            foreach (['trees', 'clusters', 'heat', 'extent', 'search'] as $endpoint) {
                $this->getJson($this->url($endpoint, $filter))->assertForbidden();
            }
        }
        foreach (['maturity_states', 'physical_conditions', 'phytosanitary_conditions', 'maintenance_types'] as $kind) {
            $this->getJson($this->url('options/'.$kind))->assertForbidden();
        }
    }

    public function test_geojson_has_an_explicit_minimal_projection_even_when_private_details_and_files_exist(): void
    {
        $this->capture([
            'inspection_date' => '2026-09-20', 'general_observations' => 'PRIVATE_INSPECTION_NOTE',
            'fauna_observations' => 'PRIVATE_FAUNA_NOTE',
        ], $this->admin, $this->ownPoint);
        $this->tree = $this->ownPoint;
        $evidence = $this->attachEvidence(['caption' => 'PRIVATE_EVIDENCE_CAPTION']);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->getJson($this->url('trees'))->assertOk()->assertJsonCount(1, 'features');
        $feature = $response->json('features.0');

        $this->assertEqualsCanonicalizing(['type', 'id', 'geometry', 'properties'], array_keys($feature));
        $this->assertEqualsCanonicalizing([
            'kind', 'id', 'code', 'common_name', 'scientific_name', 'status', 'status_label',
            'placement', 'latest_inspection', 'open_url',
        ], array_keys($feature['properties']));
        $this->assertSame(['date' => '2026-09-20'], $feature['properties']['latest_inspection']);
        $this->assertSame('/map/trees/'.$this->ownPoint->id.'/open?scope=organization', $feature['properties']['open_url']);
        $body = json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ([
            'PRIVATE_LOCATION_NOTE', 'PRIVATE_GPS_BRAND', 'PRIVATE_GPS_MODEL',
            'PRIVATE_INSPECTION_NOTE', 'PRIVATE_FAUNA_NOTE', 'PRIVATE_EVIDENCE_CAPTION',
            '4.100000, -73.600000', $evidence->path, $evidence->id, $this->admin->email,
            '"organization_id"', '"created_by"', '"updated_by"', '"coordinate_raw"', '"gps_model"',
            '"accuracy_m"', '"captured_at"', '"evidences"', '"disk"', '"path"', '"metadata"',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $body);
        }
        $this->assertPrivate($response);
    }

    public function test_explicit_organization_map_opens_its_tree_for_an_actor_with_a_department_role_elsewhere(): void
    {
        $actor = $this->maintenanceActor('OFFICIAL');
        app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $this->foreign->municipality->department_id);
        $this->actingTenant($actor, $this->organization);
        $response = $this->getJson($this->url('trees', ['scope' => 'organization']))->assertOk()->assertJsonCount(1, 'features');
        $openUrl = $response->json('features.0.properties.open_url');

        $this->assertSame('/map/trees/'.$this->ownPoint->id.'/open?scope=organization', $openUrl);
        $this->post($openUrl)->assertRedirect(route('trees.show', $this->ownPoint->id))
            ->assertSessionHas('active_organization_id', $this->organization->id);
        $this->assertFalse(app(TenantContext::class)->isActive());

        // La misma elección viaja con una búsqueda; un rol más amplio no sustituye el ámbito elegido.
        $search = $this->getJson($this->url('search', ['scope' => 'organization']))->assertOk();
        $this->assertSame($openUrl, $search->json('results.0.open_url'));
        $this->postJson('/map/trees/'.$this->ownPoint->id.'/open?scope=national')->assertForbidden();
        $this->postJson('/map/trees/'.$this->ownPoint->id.'/open', ['scope' => ['national']])
            ->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_xss_shaped_names_are_returned_only_as_json_text_values(): void
    {
        $scientific = '<img src=x onerror=alert("map")>';
        $common = '<svg onload=alert("common")></svg>';
        $species = Species::create([
            'code' => 'SPEC_MAP_XSS', 'family_id' => Family::query()->firstOrFail()->id,
            'scientific_name' => $scientific, 'active' => true, 'sort_order' => 0,
        ]);
        SpeciesCommonName::create([
            'code' => 'COMMON_MAP_XSS', 'species_id' => $species->id, 'name' => $common,
            'is_primary' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->ownPoint->forceFill(['species_id' => $species->id])->save();
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->getJson($this->url('trees'))->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($scientific, $response->json('features.0.properties.scientific_name'));
        $this->assertSame($common, $response->json('features.0.properties.common_name'));
        $this->assertArrayNotHasKey('html', $response->json('features.0.properties'));
        $this->assertArrayNotHasKey('popup_html', $response->json('features.0.properties'));
    }

    public function test_family_options_use_the_real_scientific_name_column_and_label(): void
    {
        $family = Family::query()->firstOrFail();
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->getJson($this->url('options/families', ['term' => $family->scientific_name]))->assertOk();

        $result = collect($response->json('results'))->firstWhere('id', $family->id);
        $this->assertNotNull($result);
        $this->assertSame($family->scientific_name, $result['text']);
    }

    #[DataProvider('invalidSpatialInput')]
    public function test_malformed_bbox_and_injection_payloads_are_validation_errors_without_internal_details(array $input, string $field): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->getJson($this->url('trees', $input))->assertUnprocessable()->assertJsonValidationErrors($field);
        foreach (['SQLSTATE', '/var/www/', 'PDOException', '"trace"', '"exception"', 'vendor/laravel'] as $internal) {
            $response->assertDontSee($internal, false);
        }
    }

    public static function invalidSpatialInput(): array
    {
        return [
            [['west' => '-74); SELECT pg_sleep(20); --'], 'west'],
            [['west' => ['-74']], 'west'],
            [['north' => 'Infinity'], 'north'],
            [['zoom' => '15 UNION SELECT 1'], 'zoom'],
            [['organization_id' => "' OR 1=1 --"], 'organization_id'],
            [['include_deleted' => '1'], 'include_deleted'],
        ];
    }

    public function test_empty_organization_and_inventory_extent_stay_in_their_real_municipality(): void
    {
        $emptyOrganization = $this->anotherOrganization($this->organization);
        $emptyInventory = $this->inventoryFor($emptyOrganization, 'ENDPOINT-EMPTY');
        DB::update('UPDATE municipalities SET geometry = ST_Multi(ST_GeomFromText(?, 4326)) WHERE id = ?', [
            'POLYGON((-73.9 3.9,-73.1 3.9,-73.1 4.9,-73.9 4.9,-73.9 3.9))',
            $this->organization->municipality_id,
        ]);
        DB::update('UPDATE departments SET geometry = ST_Multi(ST_GeomFromText(?, 4326)) WHERE id = ?', [
            'POLYGON((-74 3,-73 3,-73 5,-74 5,-74 3))',
            $this->organization->municipality->department_id,
        ]);
        DB::update('UPDATE departments SET geometry = ST_Multi(ST_GeomFromText(?, 4326)) WHERE id = ?', [
            'POLYGON((-70 8,-69 8,-69 9,-70 9,-70 8))',
            $this->foreign->municipality->department_id,
        ]);
        $this->actingAs($this->maintenanceActor('SUPER_ADMIN'));

        foreach ([
            ['organization_id' => $emptyOrganization->id],
            ['inventory_id' => $emptyInventory->id],
        ] as $filter) {
            $response = $this->getJson($this->url('extent', $filter))->assertOk()->assertJsonPath('total', 0);
            $this->assertSame([[3.9, -73.9], [4.9, -73.1]], $response->json('bounds'));
            $this->assertPrivate($response);
        }
        $this->assertNull(session('active_organization_id'));
    }

    public function test_rate_limit_returns_retry_after_without_leaking_response_internals(): void
    {
        config(['maps.rate_limit' => 1]);
        $this->actingTenant($this->admin, $this->organization);
        $this->getJson($this->url('trees'))->assertOk();
        $response = $this->getJson($this->url('extent'))->assertStatus(429)->assertHeader('Retry-After');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(['message'], array_keys($response->json()));
        $this->assertPrivate($response);
    }

    private function url(string $endpoint, array $extra = []): string
    {
        $input = $this->bbox(['q' => 'ENDPOINT-']);
        if ($endpoint === 'search') {
            $input['term'] = 'ENDPOINT-';
        }

        return '/api/v1/map/'.$endpoint.'?'.http_build_query(array_replace($input, $extra));
    }

    private function authenticate(User $actor, string $role): void
    {
        if (in_array($role, ['SUPER_ADMIN', 'DEPARTMENT_ADMIN'], true)) {
            $this->actingAs($actor);
        } else {
            $this->actingTenant($actor, $this->organization);
        }
    }

    private function removePermissions(string $role, array $codes): void
    {
        $roleId = Role::query()->where('name', $role)->value('id');
        $ids = Permission::query()->whereIn('code', $codes)->pluck('id');
        DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_id', $ids)->delete();
    }

    private function inventoryFor(Organization $organization, string $code): Inventory
    {
        return app(InventoryRepository::class)->create([
            'organization_id' => $organization->id, 'code' => $code, 'name' => $code,
            'status' => 'active', 'active' => true, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }

    private function assertPrivate($response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Repositories\CountryRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Services\OrganizationMembershipService;
use App\Services\PilotSeedService;
use App\Services\TenantContextService;
use Database\Seeders\PilotOrganizationSeeder;
use Database\Seeders\TerritorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class TerritorialFoundationTest extends PostgresTestCase
{
    public function test_country_can_be_created_with_an_independent_uuid(): void
    {
        $country = app(CountryRepository::class)->upsert('TEST', ['name' => 'País de prueba', 'active' => true]);
        $this->assertTrue(Str::isUuid($country->id));
        $this->assertNotSame($country->code, $country->id);
    }

    public function test_colombia_seeder_uses_confirmed_codes(): void
    {
        $this->seed(TerritorySeeder::class);
        $this->assertDatabaseHas('countries', ['code' => '170', 'name' => 'Colombia', 'iso_alpha_2' => 'CO', 'iso_alpha_3' => 'COL']);
    }

    public function test_meta_belongs_to_colombia(): void
    {
        $pilot = $this->pilot();
        $this->assertSame('50', $pilot->municipality->department->code);
        $this->assertSame('Meta', $pilot->municipality->department->name);
        $this->assertSame('Colombia', $pilot->municipality->department->country->name);
    }

    public function test_villavicencio_belongs_to_meta(): void
    {
        $pilot = $this->pilot();
        $this->assertSame('50001', $pilot->municipality->code);
        $this->assertSame('Villavicencio', $pilot->municipality->name);
        $this->assertSame($pilot->municipality->department_id, $pilot->municipality->department->id);
    }

    public function test_pilot_seed_and_all_new_entity_ids_are_reproducible(): void
    {
        $this->seed(PilotOrganizationSeeder::class);
        $pilot = $this->pilot();
        $ids = [$pilot->id, $pilot->municipality->id, $pilot->municipality->department->id, $pilot->municipality->department->country->id];
        $again = app(PilotSeedService::class)->seedOrganization()->load('municipality.department.country');
        $this->assertSame($ids, [$again->id, $again->municipality->id, $again->municipality->department->id, $again->municipality->department->country->id]);
        foreach ($ids as $id) {
            $this->assertTrue(Str::isUuid($id));
        }
        $this->assertSame('Institucion Educativa INEM Luis Lopez de Mesa', $pilot->name);
        $this->assertSame('school', $pilot->type->value);
        $this->assertSame('COL-MET-VVC-INEM', $pilot->tree_code_prefix);
        $this->assertSame(1, Organization::where('code', 'INEM-VVC')->count());
        $this->assertNull($pilot->municipality->geometry);
        $this->assertNull($pilot->municipality->department->geometry);
    }

    public function test_organization_cannot_exist_without_a_municipality(): void
    {
        $attributes = $this->organizationInput($this->pilot());
        unset($attributes['municipality_id']);
        $this->expectException(QueryException::class);
        Organization::create($attributes);
    }

    public function test_nonexistent_municipality_is_rejected_by_foreign_key(): void
    {
        $attributes = $this->organizationInput($this->pilot());
        $attributes['municipality_id'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        Organization::create($attributes);
    }

    public function test_organization_codes_are_unique_including_inactive_records(): void
    {
        $pilot = $this->pilot();
        $attributes = $this->organizationInput($pilot);
        $attributes['code'] = $pilot->code;
        $this->expectException(QueryException::class);
        Organization::create($attributes);
    }

    public function test_department_codes_are_unique_within_country(): void
    {
        $department = $this->pilot()->municipality->department;
        $this->expectException(QueryException::class);
        Department::create(['country_id' => $department->country_id, 'code' => $department->code, 'name' => 'Duplicado']);
    }

    public function test_municipality_codes_are_unique_within_department(): void
    {
        $municipality = $this->pilot()->municipality;
        $this->expectException(QueryException::class);
        Municipality::create(['department_id' => $municipality->department_id, 'code' => $municipality->code, 'name' => 'Duplicado']);
    }

    public function test_unknown_organization_type_is_rejected_by_database(): void
    {
        $attributes = $this->organizationInput($this->pilot());
        $attributes['type'] = 'invented';
        $this->expectException(QueryException::class);
        \Illuminate\Support\Facades\DB::table('organizations')->insert($attributes + ['id' => (string) Str::uuid()]);
    }

    public function test_one_user_has_multiple_organizations_without_duplicate_account(): void
    {
        $first = $this->pilot();
        $second = $this->anotherOrganization($first);
        $user = User::factory()->create();
        $a = $this->member($user, $first);
        $b = $this->member($user, $second);
        $this->assertTrue(Str::isUuid($a->id));
        $this->assertTrue(Str::isUuid($b->id));
        $this->assertSame(2, $user->organizations()->count());
        $this->assertSame(2, $user->memberships()->count());
        $this->assertSame(1, User::where('email', $user->email)->count());
    }

    public function test_membership_deactivation_does_not_disable_user_or_other_membership(): void
    {
        $first = $this->pilot();
        $second = $this->anotherOrganization($first);
        $user = User::factory()->create();
        $a = $this->member($user, $first);
        $b = $this->member($user, $second);
        $this->allowManagement($user);
        app(TenantContextService::class)->activate($user, $first->id);
        app(OrganizationMembershipService::class)->setActive($user, $a->id, false);
        $this->assertFalse($a->refresh()->active);
        $this->assertNotNull($a->left_at);
        $this->assertTrue($b->refresh()->active);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame($second->id, app(TenantContextService::class)->activate($user, $second->id)->id);
    }

    public function test_membership_can_be_reactivated_by_an_authorized_member(): void
    {
        $organization = $this->pilot();
        $manager = User::factory()->create();
        $user = User::factory()->create();
        $this->member($manager, $organization);
        $membership = $this->member($user, $organization);
        $this->allowManagement($manager);
        app(TenantContextService::class)->activate($manager, $organization->id);
        $service = app(OrganizationMembershipService::class);
        $service->setActive($manager, $membership->id, false);
        $service->setActive($manager, $membership->id, true);
        $this->assertTrue($membership->refresh()->active);
        $this->assertNull($membership->left_at);
        $this->assertNotNull($membership->joined_at);
    }

    public function test_user_organization_pair_cannot_be_duplicated(): void
    {
        $organization = $this->pilot();
        $user = User::factory()->create();
        $this->member($user, $organization);
        $this->expectException(QueryException::class);
        OrganizationMembership::create(['user_id' => $user->id, 'organization_id' => $organization->id, 'active' => true]);
    }

    public function test_active_membership_cannot_have_a_departure_date(): void
    {
        $organization = $this->pilot();
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        app(OrganizationMembershipRepository::class)->upsert((int) $user->id, $organization->id, ['active' => true, 'left_at' => now()]);
    }
}

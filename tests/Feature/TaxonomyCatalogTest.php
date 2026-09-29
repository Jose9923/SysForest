<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaxonomyCatalog;
use App\Models\Family;
use App\Models\Species;
use App\Models\SpeciesCommonName;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\TaxonomyRepository;
use App\Services\TaxonomyService;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;

class TaxonomyCatalogTest extends PostgresTestCase
{
    private User $admin;
    private TaxonomyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->allowNationalManagement($this->admin);
        $this->service = app(TaxonomyService::class);
    }

    public function test_source_taxonomy_is_idempotent_and_keeps_administrator_edits(): void
    {
        $this->seed(TaxonomySeeder::class);
        $family = Family::query()->where('code', 'FAMILY_CHRYSOBALANACEAE')->sole();
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $this->assertSame('Licania tomentosa (Benth.) Fritsch.', $species->scientific_name);
        $this->assertSame($family->id, $species->family_id);
        $this->assertSame('Oití', $species->commonNames->sole()->name);
        $this->service->update($this->admin, 'families', $family->id, ['lock_version' => $family->fresh()->lock_version, 'scientific_name' => 'FAMILIA EDITADA', 'active' => false, 'sort_order' => 432]);
        $this->seed(TaxonomySeeder::class);
        $this->assertDatabaseCount('families', 8);
        $this->assertDatabaseCount('species', 10);
        $this->assertDatabaseCount('species_common_names', 10);
        $this->assertSame('FAMILIA EDITADA', $family->fresh()->scientific_name);
        $this->assertFalse($family->fresh()->active);
        $this->assertSame(432, $family->fresh()->sort_order);
    }

    public function test_admin_creates_edits_reorders_and_reactivates_without_a_tenant(): void
    {
        $family = $this->family();
        $this->service->update($this->admin, 'families', $family->id, ['lock_version' => $family->fresh()->lock_version, 'description' => 'Revisada', 'active' => false, 'sort_order' => 40]);
        $this->assertFalse($family->fresh()->active);
        $this->service->update($this->admin, 'families', $family->id, ['lock_version' => $family->fresh()->lock_version, 'active' => true]);
        $this->assertTrue($family->fresh()->active);
        $this->assertSame('Revisada', $family->fresh()->description);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $this->admin->id]);
        foreach (['catalog.created', 'catalog.updated', 'catalog.deactivated', 'catalog.reordered', 'catalog.activated'] as $event) {
            $this->assertDatabaseHas('security_events', ['event' => $event, 'actor_user_id' => $this->admin->id, 'organization_id' => null]);
        }
    }

    public function test_only_national_admin_can_mutate_taxonomy(): void
    {
        $organization = $this->pilot();
        foreach (['OFFICIAL', 'ORGANIZATION_ADMIN', 'DEPARTMENT_ADMIN'] as $role) {
            $actor = User::factory()->create();
            if ($role === 'DEPARTMENT_ADMIN') {
                app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $organization->municipality->department_id);
            } else {
                $membership = $this->member($actor, $organization);
                app(RoleAssignmentRepository::class)->grantOrganization($membership, $role);
            }
            try {
                $this->service->create($actor, 'families', ['code' => 'DENIED_'.$role, 'scientific_name' => 'No permitida']);
                $this->fail('Un rol de consulta pudo administrar taxonomía.');
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('families', ['code' => 'DENIED_'.$role]);
            }
        }
    }

    public function test_code_is_immutable_and_case_insensitive_scientific_names_are_unique(): void
    {
        $family = $this->family();
        foreach ([
            fn () => $this->service->update($this->admin, 'families', $family->id, ['lock_version' => $family->fresh()->lock_version, 'code' => 'FAMILY_OTHER']),
            fn () => $this->service->create($this->admin, 'families', ['code' => 'FAMILY_OTHER', 'scientific_name' => 'familia de prueba']),
            fn () => $this->service->create($this->admin, 'families', ['code' => 'FAMILY_TEST', 'scientific_name' => 'Otra familia']),
        ] as $operation) {
            try {
                $operation();
                $this->fail('No se respetó la unicidad o estabilidad taxonómica.');
            } catch (ValidationException) {
                $this->assertSame('FAMILY_TEST', $family->fresh()->code);
            }
        }
    }

    public function test_inactive_parent_cannot_receive_a_new_species_or_common_name(): void
    {
        $family = $this->family();
        $species = $this->species($family);
        $this->service->update($this->admin, 'families', $family->id, ['lock_version' => $family->fresh()->lock_version, 'active' => false]);
        foreach ([
            fn () => $this->service->create($this->admin, 'species', ['code' => 'SPECIES_DENIED', 'scientific_name' => 'Specimen secundum', 'family_id' => $family->id]),
            fn () => $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_DENIED', 'name' => 'Árbol', 'species_id' => $species->id]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Se creó una referencia a taxonomía inactiva.');
            } catch (ValidationException) {
                $this->assertSame($family->id, $species->fresh()->family->id);
            }
        }
        $this->service->update($this->admin, 'species', $species->id, ['lock_version' => $species->fresh()->lock_version, 'description' => 'Referencia histórica conservada']);
        $this->assertSame('Referencia histórica conservada', $species->fresh()->description);
        $this->assertCount(0, app(TaxonomyRepository::class)->options(TaxonomyCatalog::Species));
    }

    public function test_species_supports_multiple_common_names_but_only_one_primary(): void
    {
        $species = $this->species($this->family());
        $first = $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_ONE', 'name' => 'Nombre uno', 'species_id' => $species->id, 'is_primary' => true]);
        $second = $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_TWO', 'name' => 'Nombre dos', 'species_id' => $species->id]);
        $this->assertCount(2, $species->fresh()->commonNames);
        try {
            $this->service->update($this->admin, 'common-names', $second->id, ['lock_version' => $second->fresh()->lock_version, 'is_primary' => true]);
            $this->fail('Se permitieron dos nombres principales.');
        } catch (ValidationException) {
            $this->assertFalse($second->fresh()->is_primary);
        }
        $this->service->update($this->admin, 'common-names', $first->id, ['lock_version' => $first->fresh()->lock_version, 'is_primary' => false]);
        $this->service->update($this->admin, 'common-names', $second->id, ['lock_version' => $second->fresh()->lock_version, 'is_primary' => true]);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_database_enforces_primary_name_and_restricts_referenced_family_deletion(): void
    {
        $family = $this->family();
        $species = $this->species($family);
        $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_FIRST', 'name' => 'Principal', 'species_id' => $species->id, 'is_primary' => true]);
        foreach ([
            fn () => SpeciesCommonName::query()->create(['code' => 'COMMON_CONFLICT', 'name' => 'Segundo', 'species_id' => $species->id, 'is_primary' => true]),
            fn () => $family->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('La restricción de base no protegió la referencia.');
            } catch (QueryException) {
                $this->assertDatabaseHas('families', ['id' => $family->id]);
            }
        }
    }

    public function test_common_name_can_repeat_for_different_species_but_not_within_one_species(): void
    {
        $family = $this->family();
        $a = $this->species($family);
        $b = $this->service->create($this->admin, 'species', ['code' => 'SPECIES_SECOND', 'scientific_name' => 'Specimen secundum', 'family_id' => $family->id]);
        $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_A', 'name' => 'Palma', 'species_id' => $a->id]);
        $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_B', 'name' => 'Palma', 'species_id' => $b->id]);
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin, 'common-names', ['code' => 'COMMON_DUP', 'name' => 'palma', 'species_id' => $a->id]);
    }

    public function test_search_and_order_are_deterministic_and_normal_options_hide_inactive_values(): void
    {
        $a = $this->family();
        $b = $this->service->create($this->admin, 'families', ['code' => 'FAMILY_BEFORE', 'scientific_name' => 'Otra familia', 'sort_order' => 1]);
        $this->service->update($this->admin, 'families', $a->id, ['lock_version' => $a->fresh()->lock_version, 'sort_order' => 9]);
        $results = $this->service->index($this->admin, 'families', ['q' => 'familia']);
        $this->assertSame([$b->id, $a->id], $results['records']->getCollection()->modelKeys());
        $this->service->update($this->admin, 'families', $a->id, ['lock_version' => $a->fresh()->lock_version, 'active' => false]);
        $this->assertSame([$b->id], app(TaxonomyRepository::class)->options(TaxonomyCatalog::Families)->modelKeys());
    }

    public function test_http_create_edit_and_xss_escape_use_the_authorized_catalog(): void
    {
        $this->actingAs($this->admin)->get('/admin/catalogs/taxonomy/families/create')->assertOk();
        $this->post('/admin/catalogs/taxonomy/families', ['code' => 'FAMILY_HTTP', 'scientific_name' => '<script>alert(1)</script>'])->assertRedirect();
        $family = Family::query()->where('code', 'FAMILY_HTTP')->sole();
        $this->get('/admin/catalogs/taxonomy/families')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->get('/admin/catalogs/taxonomy/families/'.$family->id.'/edit')->assertOk();
        $this->put('/admin/catalogs/taxonomy/families/'.$family->id, ['lock_version' => $family->fresh()->lock_version, 'active' => false, 'sort_order' => 12])->assertRedirect();
        $this->assertFalse($family->fresh()->active);
        $this->get('/admin/catalogs/taxonomy/users')->assertNotFound();
        $this->delete('/admin/catalogs/taxonomy/families/'.$family->id)->assertStatus(405);
    }

    public function test_http_official_can_consult_but_cannot_access_forms_or_mutate(): void
    {
        $family = $this->family();
        $actor = User::factory()->create();
        $this->member($actor, $this->pilot());
        $this->actingAs($actor)->get('/admin/catalogs/taxonomy/families')->assertOk()->assertDontSee('+ Crear valor');
        $this->get('/admin/catalogs/taxonomy/families/create')->assertForbidden();
        $this->putJson('/admin/catalogs/taxonomy/families/'.$family->id, ['lock_version' => $family->fresh()->lock_version, 'active' => false])->assertForbidden();
        $this->assertTrue($family->fresh()->active);
    }

    public function test_active_filter_normalizes_validated_integer_and_boolean_inputs(): void
    {
        $active = $this->family();
        $inactive = $this->service->create($this->admin, 'families', [
            'code' => 'FAMILY_INACTIVE', 'scientific_name' => 'Familia inactiva', 'active' => false,
        ]);
        foreach ([1, true, '1'] as $value) {
            $result = $this->service->index($this->admin, 'families', ['active' => $value]);
            $this->assertSame('1', $result['filters']['active']);
            $this->assertSame([$active->id], $result['records']->getCollection()->modelKeys());
        }
        foreach ([0, '0'] as $value) {
            $result = $this->service->index($this->admin, 'families', ['active' => $value]);
            $this->assertSame('0', $result['filters']['active']);
            $this->assertSame([$inactive->id], $result['records']->getCollection()->modelKeys());
        }
    }

    private function family(): Family
    {
        return $this->service->create($this->admin, 'families', ['code' => 'FAMILY_TEST', 'scientific_name' => 'Familia de prueba']);
    }

    private function species(Family $family): Species
    {
        return $this->service->create($this->admin, 'species', ['code' => 'SPECIES_TEST', 'scientific_name' => 'Specimen primum', 'family_id' => $family->id]);
    }
}

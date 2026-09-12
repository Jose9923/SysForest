<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\ConditionCategory;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\ConditionCatalogService;
use Database\Seeders\ConditionCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;

class ConditionCatalogTest extends PostgresTestCase
{
    private User $admin;
    private ConditionCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->allowNationalManagement($this->admin);
        $this->service = app(ConditionCatalogService::class);
    }

    public function test_seed_contains_exactly_65_conditions_and_ten_categories_without_phenology(): void
    {
        $this->seed(ConditionCatalogSeeder::class);
        $this->assertDatabaseCount('condition_categories', 10);
        $this->assertDatabaseCount('conditions', 65);
        $this->assertSame(23, Condition::query()->where('assessment_type', 'physical_mechanical')->count());
        $this->assertSame(42, Condition::query()->where('assessment_type', 'phytosanitary')->count());
        $this->assertSame(4, ConditionCategory::query()->where('assessment_type', 'physical_mechanical')->count());
        $this->assertSame(6, ConditionCategory::query()->where('assessment_type', 'phytosanitary')->count());
        $this->assertFalse(Condition::query()->where('description', 'like', '%Hoja1!CE6%')->exists());
        $this->assertFalse(Condition::query()->where('description', 'like', '%Hoja1!CF6%')->exists());
    }

    public function test_reseeding_keeps_codes_ids_names_activity_and_order(): void
    {
        $this->seed(ConditionCatalogSeeder::class);
        $condition = Condition::query()->where('code', 'PHY_ROOT_GOOD_ANCHORAGE')->sole();
        $this->service->update($this->admin, 'items', $condition->id, ['name' => 'Anclaje revisado', 'active' => false, 'sort_order' => 777]);
        $this->seed(ConditionCatalogSeeder::class);
        $this->assertDatabaseCount('conditions', 65);
        $this->assertDatabaseHas('conditions', ['id' => $condition->id, 'code' => 'PHY_ROOT_GOOD_ANCHORAGE', 'name' => 'Anclaje revisado', 'active' => false, 'sort_order' => 777]);
    }

    public function test_same_condition_label_in_different_organs_keeps_distinct_identities(): void
    {
        $this->seed(ConditionCatalogSeeder::class);
        $healthy = Condition::query()->where('name', 'Sano')->with('category')->get();
        $this->assertCount(3, $healthy);
        $this->assertCount(3, $healthy->pluck('id')->unique());
        $this->assertEqualsCanonicalizing(['PHT_STEM', 'PHT_FOLIAGE', 'PHT_GENERAL'], $healthy->pluck('category.code')->all());
    }

    public function test_category_and_item_creation_have_audit_and_support_state_and_order(): void
    {
        $category = $this->category('PHY_TEST', 'physical_mechanical');
        $item = $this->item($category);
        $this->service->update($this->admin, 'items', $item->id, ['active' => false, 'sort_order' => 24, 'description' => 'Seguimiento']);
        $this->service->update($this->admin, 'items', $item->id, ['active' => true]);
        $this->assertTrue($item->fresh()->active);
        $this->assertSame(24, $item->fresh()->sort_order);
        $this->assertSame($category->id, $item->fresh()->category->id);
        foreach (['catalog.created', 'catalog.updated', 'catalog.deactivated', 'catalog.activated', 'catalog.reordered'] as $event) {
            $this->assertDatabaseHas('security_events', ['event' => $event, 'actor_user_id' => $this->admin->id, 'organization_id' => null]);
        }
    }

    public function test_all_non_national_roles_are_denied_mutations(): void
    {
        $organization = $this->pilot();
        foreach (['OFFICIAL', 'ORGANIZATION_ADMIN', 'DEPARTMENT_ADMIN'] as $role) {
            $actor = User::factory()->create();
            if ($role === 'DEPARTMENT_ADMIN') {
                app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $organization->municipality->department_id);
            } else {
                app(RoleAssignmentRepository::class)->grantOrganization($this->member($actor, $organization), $role);
            }
            try {
                $this->service->create($actor, 'categories', ['code' => 'DENIED_'.$role, 'name' => 'Prohibida', 'assessment_type' => 'physical_mechanical']);
                $this->fail('Un rol de consulta modificó el catálogo.');
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('condition_categories', ['code' => 'DENIED_'.$role]);
            }
        }
    }

    public function test_existing_condition_cannot_change_code_category_or_assessment_type(): void
    {
        $category = $this->category('PHY_TEST', 'physical_mechanical');
        $other = $this->category('PHY_OTHER', 'physical_mechanical', 'Otra categoría');
        $item = $this->item($category);
        foreach ([['code' => 'RENAMED_CODE'], ['assessment_type' => 'phytosanitary'], ['condition_category_id' => $other->id]] as $change) {
            try {
                $this->service->update($this->admin, 'items', $item->id, $change);
                $this->fail('Se cambió la identidad semántica de una condición existente.');
            } catch (ValidationException) {
                $this->assertSame($category->id, $item->fresh()->condition_category_id);
                $this->assertSame('physical_mechanical', $item->fresh()->assessment_type);
            }
        }
    }

    public function test_service_rejects_mismatched_or_inactive_category(): void
    {
        $category = $this->category('PHT_TEST', 'phytosanitary');
        try {
            $this->service->create($this->admin, 'items', ['code' => 'PHY_MISMATCH', 'name' => 'Otra', 'assessment_type' => 'physical_mechanical', 'condition_category_id' => $category->id]);
            $this->fail('Se aceptó un ámbito distinto al de la categoría.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('conditions', 0);
        }
        $this->service->update($this->admin, 'categories', $category->id, ['active' => false]);
        $this->expectException(ValidationException::class);
        $this->item($category);
    }

    public function test_database_rejects_cross_assessment_relationship_and_negative_order(): void
    {
        $category = $this->category('PHT_TEST', 'phytosanitary');
        foreach ([
            ['code' => 'PHY_WRONG', 'name' => 'Ámbito incorrecto', 'assessment_type' => 'physical_mechanical', 'condition_category_id' => $category->id],
            ['code' => 'PHT_WRONG', 'name' => 'Orden incorrecto', 'assessment_type' => 'phytosanitary', 'condition_category_id' => $category->id, 'sort_order' => -1],
        ] as $values) {
            try {
                DB::transaction(fn () => Condition::query()->create($values));
                $this->fail('La base no rechazó una relación o rango inválido.');
            } catch (QueryException) {
                $this->assertDatabaseMissing('conditions', ['code' => $values['code']]);
            }
        }
    }

    public function test_duplicate_code_is_rejected_and_inactive_items_keep_their_category(): void
    {
        $category = $this->category('PHY_TEST', 'physical_mechanical');
        $item = $this->item($category);
        try {
            $this->item($category);
            $this->fail('Se permitió un código duplicado.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('conditions', 1);
        }
        $this->service->update($this->admin, 'items', $item->id, ['active' => false]);
        $this->assertSame($category->id, $item->fresh()->category->id);
    }

    public function test_search_filters_scope_and_orders_results(): void
    {
        $a = $this->category('PHY_TEST', 'physical_mechanical');
        $b = $this->category('PHT_TEST', 'phytosanitary');
        $this->item($a);
        $second = $this->service->create($this->admin, 'items', ['code' => 'PHT_OTHER', 'name' => 'Condición de prueba', 'assessment_type' => 'phytosanitary', 'condition_category_id' => $b->id, 'sort_order' => 5]);
        $result = $this->service->index($this->admin, 'items', ['assessment_type' => 'phytosanitary', 'q' => 'prueba']);
        $this->assertSame([$second->id], $result['records']->getCollection()->modelKeys());
    }

    public function test_http_admin_forms_and_mutations_and_official_read_only_access(): void
    {
        $category = $this->category('PHY_TEST', 'physical_mechanical');
        $this->actingAs($this->admin)->get('/admin/catalogs/conditions/items/create')->assertOk()->assertSee($category->name);
        $this->post('/admin/catalogs/conditions/items', ['code' => 'PHY_HTTP', 'name' => 'Anclaje especial', 'condition_category_id' => $category->id, 'assessment_type' => 'physical_mechanical'])->assertRedirect();
        $item = Condition::query()->where('code', 'PHY_HTTP')->sole();
        $this->get('/admin/catalogs/conditions/items/'.$item->id.'/edit')->assertOk();
        $this->put('/admin/catalogs/conditions/items/'.$item->id, ['sort_order' => 12])->assertRedirect();
        $official = User::factory()->create();
        $this->member($official, $this->pilot());
        $this->actingAs($official)->get('/admin/catalogs/conditions/items')->assertOk()->assertSee('Anclaje especial')->assertDontSee('+ Crear valor');
        $this->get('/admin/catalogs/conditions/items/create')->assertForbidden();
        $this->putJson('/admin/catalogs/conditions/items/'.$item->id, ['active' => false])->assertForbidden();
    }

    public function test_active_filter_normalizes_validated_integer_and_boolean_inputs(): void
    {
        $active = $this->item($this->category('PHY_TEST', 'physical_mechanical'));
        $inactive = $this->item($this->category('PHT_TEST', 'phytosanitary'));
        $this->service->update($this->admin, 'items', $inactive->id, ['active' => false]);
        foreach ([1, true, '1'] as $value) {
            $result = $this->service->index($this->admin, 'items', ['active' => $value]);
            $this->assertSame('1', $result['filters']['active']);
            $this->assertSame([$active->id], $result['records']->getCollection()->modelKeys());
        }
        foreach ([0, '0'] as $value) {
            $result = $this->service->index($this->admin, 'items', ['active' => $value]);
            $this->assertSame('0', $result['filters']['active']);
            $this->assertSame([$inactive->id], $result['records']->getCollection()->modelKeys());
        }
    }

    private function category(string $code, string $type, string $name = 'Categoría de prueba'): ConditionCategory
    {
        return $this->service->create($this->admin, 'categories', ['code' => $code, 'name' => $name, 'assessment_type' => $type]);
    }

    private function item(ConditionCategory $category): Condition
    {
        return $this->service->create($this->admin, 'items', ['code' => $category->code.'_ITEM', 'name' => 'Condición de prueba',
            'assessment_type' => $category->assessment_type, 'condition_category_id' => $category->id]);
    }
}

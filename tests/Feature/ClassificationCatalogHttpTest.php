<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClassificationRuleVersion;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Repositories\ClassificationRuleRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\ClassificationCatalogService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class ClassificationCatalogHttpTest extends PostgresTestCase
{
    private ClassificationRuleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AuthorizationSeeder::class, ForestryCatalogSeeder::class, ClassificationRuleSeeder::class]);
        $this->version = app(ClassificationRuleRepository::class)->findByCode('EXCEL_V1');
    }

    #[DataProvider('roles')]
    public function test_four_roles_can_read_rules_without_selecting_a_tenant(string $role): void
    {
        $this->actingAs($this->actor($role));
        $this->get(route('classification.index'))->assertOk()->assertSee('EXCEL_V1');
        Model::preventLazyLoading();
        try {
            $this->get(route('classification.show', $this->version->id))->assertOk()
                ->assertSee('Fustal joven')->assertSee('Fustal sobremaduro');
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public static function roles(): array
    {
        return [['SUPER_ADMIN'], ['DEPARTMENT_ADMIN'], ['ORGANIZATION_ADMIN'], ['OFFICIAL']];
    }

    #[DataProvider('readOnlyRoles')]
    public function test_other_roles_cannot_edit_or_update_rules_through_http(string $role): void
    {
        $this->actingAs($this->actor($role));
        $this->get(route('classification.edit', $this->version->id))->assertForbidden();
        $this->put(route('classification.update', $this->version->id), $this->metadata())->assertForbidden();
        $this->assertTrue($this->version->fresh()->active);
    }

    public static function readOnlyRoles(): array
    {
        return [['DEPARTMENT_ADMIN'], ['ORGANIZATION_ADMIN'], ['OFFICIAL']];
    }

    public function test_super_admin_can_edit_availability_and_order_with_national_audit(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->actingAs($actor);
        $this->get(route('classification.edit', $this->version->id))->assertOk();
        $this->put(route('classification.update', $this->version->id), $this->metadata())
            ->assertRedirect(route('classification.show', $this->version->id));
        $version = $this->version->fresh();
        $this->assertFalse($version->active);
        $this->assertSame(75, $version->sort_order);
        $this->assertSame('5.000000', $version->diameter_step);
        foreach (['catalog.updated', 'catalog.deactivated', 'catalog.reordered'] as $event) {
            $entry = SecurityEvent::query()->where('event', $event)->where('actor_user_id', $actor->id)->firstOrFail();
            $this->assertNull($entry->organization_id);
            $this->assertSame('EXCEL_V1', $entry->metadata['rule_version']);
        }
        $this->put(route('classification.update', $version->id), array_replace($this->metadata(), ['active' => true]))->assertRedirect();
        $this->assertTrue($version->fresh()->active);
        $this->assertDatabaseHas('security_events', ['event' => 'catalog.activated', 'actor_user_id' => $actor->id]);
    }

    public function test_http_cannot_rewrite_the_rule_parameters_even_as_super_admin(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN'));
        $this->putJson(route('classification.update', $this->version->id), array_replace($this->metadata(), [
            'diameter_step' => '7', 'code' => 'ALTERED_VERSION', 'bands' => [['min_class' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['diameter_step', 'code', 'bands']);
        $this->assertSame('5.000000', $this->version->fresh()->diameter_step);
    }

    public function test_rule_preview_is_rendered_by_blade_with_exact_boundaries(): void
    {
        $this->actingAs($this->actor('OFFICIAL'));
        $this->get(route('classification.show', ['version' => $this->version->id, 'kind' => 'diameter', 'value' => '5']))
            ->assertOk()->assertSee('Clase 1');
        $this->get(route('classification.show', ['version' => $this->version->id, 'kind' => 'diameter', 'value' => '5.000001']))
            ->assertOk()->assertSee('Clase 2');
        $this->get(route('classification.show', ['version' => $this->version->id, 'kind' => 'height', 'value' => '10.000001']))
            ->assertOk()->assertSee('Clase 2');
    }

    public function test_rule_preview_rejects_excess_precision_without_rounding(): void
    {
        $this->actingAs($this->actor('OFFICIAL'));
        $this->getJson(route('classification.show', ['version' => $this->version->id, 'kind' => 'diameter', 'value' => '5.0000001']))
            ->assertUnprocessable()->assertJsonValidationErrors('value');
    }

    public function test_direct_service_write_rechecks_permissions(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $actor->forceFill(['active' => false])->save();
        $this->expectException(AuthorizationException::class);
        app(ClassificationCatalogService::class)->update($actor, $this->version->id, $this->metadata());
    }

    public function test_anonymous_catalog_access_requires_login(): void
    {
        $this->get(route('classification.index'))->assertRedirect(route('login'));
    }

    public function test_service_active_filter_accepts_equivalent_strings_integers_and_booleans(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        app(ClassificationRuleRepository::class)->updateMetadata($this->version, ['name' => 'TYPE_FILTER_ACTIVE']);

        $inactive = new ClassificationRuleVersion();
        $inactive->forceFill([
            'code' => 'TYPE_FILTER_INACTIVE', 'name' => 'TYPE_FILTER_INACTIVE',
            'algorithm' => $this->version->algorithm,
            'diameter_step' => $this->version->diameter_step, 'height_step' => $this->version->height_step,
            'diameter_unit' => $this->version->diameter_unit, 'height_unit' => $this->version->height_unit,
            'active' => false, 'sort_order' => 0,
        ])->save();
        foreach ($this->version->maturityBands as $band) {
            $inactive->maturityBands()->create($band->only(['maturity_state_id', 'min_class', 'max_class']));
        }
        $inactive->forceFill(['sealed_at' => now()])->save();
        $service = app(ClassificationCatalogService::class);

        foreach ([['1', $this->version->id], [1, $this->version->id], [true, $this->version->id],
            ['0', $inactive->id], [0, $inactive->id], [false, $inactive->id]] as [$filter, $expectedId]) {
            $page = $service->index($actor, ['q' => 'TYPE_FILTER', 'active' => $filter])['versions'];

            $this->assertSame([$expectedId], $page->getCollection()->pluck('id')->all(), 'Filtro de tipo '.get_debug_type($filter));
        }
    }

    private function metadata(): array
    {
        return ['lock_version' => $this->version->fresh()->lock_version, 'name' => 'Clasificación de referencia', 'description' => 'Nota de administración', 'active' => false, 'sort_order' => 75];
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $assignments = app(RoleAssignmentRepository::class);
        if ($role === 'SUPER_ADMIN') {
            $assignments->grantNational((int) $actor->id);
        } elseif ($role === 'DEPARTMENT_ADMIN') {
            $assignments->grantDepartment((int) $actor->id, $this->pilot()->municipality->department_id);
        } else {
            $assignments->grantOrganization($this->member($actor, $this->pilot()), $role);
        }

        return $actor;
    }
}

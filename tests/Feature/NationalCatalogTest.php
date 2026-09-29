<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalogs\CatalogRegistry;
use App\Models\Placement;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\CatalogService;
use App\Services\PermissionService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EvidenceTypeSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PostgresTestCase;

class NationalCatalogTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AuthorizationSeeder::class, ForestryCatalogSeeder::class, EvidenceTypeSeeder::class, MaintenanceCatalogSeeder::class]);

        // Se mantiene la protección real; solo se retira la excepción automática para PHPUnit.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_general_seeding_is_idempotent_and_preserves_ids_and_administrative_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->catalogIdentities();
        $actor = $this->actor('SUPER_ADMIN');
        $placement = Placement::query()->where('code', 'PLC_PARK')->sole();
        app(CatalogService::class)->update($actor, 'placements', $placement->id, $this->input(['lock_version' => $placement->fresh()->lock_version,
            'code' => $placement->code, 'name' => 'Parque revisado', 'description' => 'Nota administrativa',
            'active' => false, 'sort_order' => 321,
        ]));

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $this->catalogIdentities());
        $current = $placement->fresh();
        $this->assertSame('Parque revisado', $current->name);
        $this->assertSame('Nota administrativa', $current->description);
        $this->assertFalse($current->active);
        $this->assertSame(321, $current->sort_order);
    }

    #[DataProvider('initialCatalogs')]
    public function test_seeded_catalog_contains_the_exact_backed_labels(string $key, array $labels): void
    {
        $model = app(CatalogRegistry::class)->find($key)->modelClass;
        $this->assertSame($labels, $model::query()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(count($labels), $model::query()->where('active', true)->count());
    }

    public static function initialCatalogs(): array
    {
        return [
            'emplazamientos iniciales' => ['placements', ['Andén', 'Parque', 'Separador vial', 'Zona verde', 'Institución', 'Vía pública']],
            'establecimiento' => ['establishment-origins', ['Espontánea', 'Plantada']],
            'biogeografía' => ['biogeographic-origins', ['Especie nativa', 'Especie exótica', 'Especie naturalizada', 'Especie invasora']],
            'hábitos' => ['growth-habits', ['Árbol', 'Palma', 'Arbusto', 'Caña o pasto', 'Vástago', 'Bejuco', 'Liana', 'Epífita no parásita', 'Epífita parásita', 'Matapalo', 'Herbácea']],
            'madurez del Excel' => ['maturity-states', ['Fustal joven', 'Fustal medio', 'Fustal maduro', 'Fustal sobremaduro']],
            'evidencia' => ['evidence-types', ['Individuo completo', 'Sistema radicular', 'Fuste', 'Ramas', 'Copa', 'Follaje', 'Flores/frutos', 'Afectación fitosanitaria', 'Fauna', 'Mantenimiento antes', 'Mantenimiento después', 'Otro']],
            'mantenimiento' => ['maintenance-types', ['Poda', 'Tala', 'Tratamiento fitosanitario', 'Aplicación de herbicida', 'Fertilización', 'Riego', 'Control de plagas', 'Manejo de raíces', 'Traslado', 'Inspección técnica', 'Otro']],
            'anulación de errores' => ['deletion-reasons', ['Registro duplicado', 'Árbol inexistente', 'Error de ubicación', 'Error de identificación', 'Registro de prueba', 'Creación accidental', 'Otro']],
            'fauna' => ['fauna-categories', ['Aves', 'Mamíferos', 'Reptiles', 'Insectos', 'Quirópteros', 'Otros']],
        ];
    }

    public function test_national_catalog_tables_have_no_organization_column(): void
    {
        foreach (app(CatalogRegistry::class)->all() as $catalog) {
            $model = new $catalog->modelClass();
            $this->assertNotContains('organization_id', Schema::getColumnListing($model->getTable()), $catalog->key);
        }
    }

    #[DataProvider('allRoles')]
    public function test_each_role_can_read_national_values_without_selecting_a_tenant(string $role): void
    {
        $actor = $this->actor($role);
        $this->actingAs($actor);
        $response = $this->get(route('catalogs.index', 'placements'))->assertOk()->assertSee('Andén');
        $response->assertSessionMissing('active_organization_id');
        $this->get(route('catalogs.dashboard'))->assertOk();

        $this->assertTrue(app(PermissionService::class)->allowsCatalogView($actor));
        $this->assertSame(6, app(CatalogService::class)->options($actor, 'placements')->count());
        $this->assertSame($role === 'SUPER_ADMIN', app(PermissionService::class)->allowsNational($actor, 'catalogs.manage'));
    }

    public static function allRoles(): array
    {
        return [['SUPER_ADMIN'], ['DEPARTMENT_ADMIN'], ['ORGANIZATION_ADMIN'], ['OFFICIAL']];
    }

    #[DataProvider('readOnlyRoles')]
    public function test_read_only_roles_cannot_create_or_update_over_http(string $role): void
    {
        $actor = $this->actor($role);
        $placement = Placement::query()->where('code', 'PLC_PARK')->sole();
        $this->actingAs($actor);
        $token = $this->csrf();

        $this->get(route('catalogs.create', 'placements'))->assertForbidden();
        $this->get(route('catalogs.edit', ['placements', $placement->id]))->assertForbidden();
        $this->postJson(route('catalogs.store', 'placements'), $this->input(['_token' => $token]))->assertForbidden();
        $this->putJson(route('catalogs.update', ['placements', $placement->id]), $this->input(['lock_version' => $placement->fresh()->lock_version,
            'code' => $placement->code, 'name' => 'Cambio no autorizado', '_token' => $token,
        ]))->assertForbidden();

        $this->assertDatabaseMissing('placements', ['code' => 'CAT_TEST']);
        $this->assertSame('Parque', $placement->fresh()->name);
    }

    #[DataProvider('readOnlyRoles')]
    public function test_read_only_roles_cannot_bypass_http_authorization_through_services(string $role): void
    {
        $actor = $this->actor($role);
        $placement = Placement::query()->where('code', 'PLC_PARK')->sole();
        $service = app(CatalogService::class);

        try {
            $service->create($actor, 'placements', $this->input());
            $this->fail('El servicio permitió crear un catálogo sin autorización nacional.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('placements', ['code' => 'CAT_TEST']);
        }

        try {
            $service->update($actor, 'placements', $placement->id, $this->input(['lock_version' => $placement->fresh()->lock_version, 'code' => $placement->code]));
            $this->fail('El servicio permitió editar un catálogo sin autorización nacional.');
        } catch (AuthorizationException) {
            $this->assertSame('Parque', $placement->fresh()->name);
        }
    }

    public static function readOnlyRoles(): array
    {
        return [['DEPARTMENT_ADMIN'], ['ORGANIZATION_ADMIN'], ['OFFICIAL']];
    }

    public function test_superadmin_can_create_edit_deactivate_and_reactivate_over_http(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN'));
        $this->get(route('catalogs.create', 'placements'))->assertOk();
        $token = $this->csrf();
        $this->post(route('catalogs.store', 'placements'), $this->input(['_token' => $token]))
            ->assertRedirect(route('catalogs.index', 'placements'))->assertSessionHasNoErrors();
        $entry = Placement::query()->where('code', 'CAT_TEST')->sole();
        $this->get(route('catalogs.edit', ['placements', $entry->id]))->assertOk()->assertSee('CAT_TEST');

        $this->put(route('catalogs.update', ['placements', $entry->id]), $this->input(['lock_version' => $entry->fresh()->lock_version,
            'name' => 'Valor corregido', 'active' => false, 'sort_order' => 77, '_token' => $token,
        ]))->assertRedirect(route('catalogs.index', 'placements'))->assertSessionHasNoErrors();
        $this->assertSame('Valor corregido', $entry->fresh()->name);
        $this->assertFalse($entry->fresh()->active);
        $this->assertSame(77, $entry->fresh()->sort_order);

        $this->put(route('catalogs.update', ['placements', $entry->id]), $this->input(['lock_version' => $entry->fresh()->lock_version, '_token' => $token]))
            ->assertRedirect(route('catalogs.index', 'placements'))->assertSessionHasNoErrors();
        $this->assertTrue($entry->fresh()->active);
    }

    public function test_mutations_require_real_csrf_even_for_superadmin(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN'));
        $entry = Placement::query()->where('code', 'PLC_PARK')->sole();
        $this->csrf();
        $this->post(route('catalogs.store', 'placements'), $this->input())->assertStatus(419);
        $this->put(route('catalogs.update', ['placements', $entry->id]), $this->input(['lock_version' => $entry->fresh()->lock_version,
            'code' => $entry->code, '_token' => 'incorrecto',
        ]))->assertStatus(419);

        $this->assertDatabaseMissing('placements', ['code' => 'CAT_TEST']);
        $this->assertSame('Parque', $entry->fresh()->name);
    }

    public function test_anonymous_users_and_accounts_without_roles_cannot_read_catalogs(): void
    {
        $this->get(route('catalogs.index', 'placements'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('catalogs.index', 'placements'))->assertForbidden();
    }

    public function test_read_access_is_revoked_when_the_only_organization_membership_is_disabled(): void
    {
        $actor = $this->actor('OFFICIAL');
        $this->assertTrue(app(PermissionService::class)->allowsCatalogView($actor));
        $membership = $actor->memberships()->firstOrFail();
        $membership->update(['active' => false, 'left_at' => now()]);

        $this->assertFalse(app(PermissionService::class)->allowsCatalogView($actor));
        $this->expectException(AuthorizationException::class);
        app(CatalogService::class)->options($actor, 'placements');
    }

    public function test_read_access_is_revoked_when_the_department_scope_is_disabled(): void
    {
        $actor = $this->actor('DEPARTMENT_ADMIN');
        $this->assertTrue(app(PermissionService::class)->allowsCatalogView($actor));
        $this->pilot()->municipality->department->update(['active' => false]);

        $this->assertFalse(app(PermissionService::class)->allowsCatalogView($actor));
    }

    public function test_a_stale_actor_does_not_retain_catalog_permissions_after_account_deactivation(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->assertTrue(app(PermissionService::class)->allowsNational($actor, 'catalogs.manage'));
        User::query()->whereKey($actor->id)->update(['active' => false]);

        $this->assertFalse(app(PermissionService::class)->allowsCatalogView($actor));
        $this->expectException(AuthorizationException::class);
        app(CatalogService::class)->create($actor, 'placements', $this->input());
    }

    public function test_inactive_values_are_excluded_from_options_but_preserve_historical_resolution(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $service = app(CatalogService::class);
        $entry = $service->create($actor, 'placements', $this->input());
        $service->update($actor, 'placements', $entry->id, $this->input(['lock_version' => $entry->fresh()->lock_version, 'active' => false]));

        $this->assertFalse($service->options($actor, 'placements')->contains('id', $entry->id));
        $historical = $service->historical($actor, 'placements', $entry->id);
        $this->assertSame($entry->id, $historical->id);
        $this->assertFalse($historical->active);
        $service->update($actor, 'placements', $entry->id, $this->input(['lock_version' => $entry->fresh()->lock_version]));
        $this->assertTrue($service->options($actor, 'placements')->contains('id', $entry->id));
    }

    public function test_order_changes_are_reflected_in_options(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $service = app(CatalogService::class);
        $first = $service->create($actor, 'placements', $this->input(['code' => 'ORDER_FIRST', 'sort_order' => 80]));
        $second = $service->create($actor, 'placements', $this->input(['code' => 'ORDER_SECOND', 'sort_order' => 90]));
        $service->update($actor, 'placements', $second->id, $this->input(['lock_version' => $second->fresh()->lock_version, 'code' => 'ORDER_SECOND', 'sort_order' => 1]));
        $ordered = $service->options($actor, 'placements')->whereIn('id', [$first->id, $second->id])->pluck('id')->all();

        $this->assertSame([$second->id, $first->id], $ordered);
    }

    public function test_service_rejects_duplicate_codes(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->create($actor, 'placements', $this->input(['code' => 'PLC_PARK']));
    }

    public function test_database_enforces_code_uniqueness(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        Placement::query()->create($this->input(['code' => 'PLC_PARK']));
    }

    public function test_service_rejects_changing_an_existing_code(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $entry = Placement::query()->where('code', 'PLC_PARK')->sole();
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->update($actor, 'placements', $entry->id, $this->input(['lock_version' => $entry->fresh()->lock_version, 'code' => 'OTHER_CODE']));
    }

    public function test_database_protects_codes_when_the_service_is_bypassed(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('placements')->where('code', 'PLC_PARK')->update(['code' => 'OTHER_CODE']);
    }

    public function test_database_rejects_negative_sort_order(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        Placement::query()->create($this->input(['sort_order' => -1]));
    }

    public function test_database_rejects_blank_labels(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        Placement::query()->create($this->input(['name' => '   ']));
    }

    public function test_organization_ownership_cannot_be_injected_into_the_service(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->create($actor, 'placements', $this->input(['organization_id' => $this->pilot()->id]));
    }

    public function test_organization_ownership_cannot_be_injected_over_http(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN'));
        $this->postJson(route('catalogs.store', 'placements'), $this->input([
            '_token' => $this->csrf(), 'organization_id' => $this->pilot()->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->assertDatabaseMissing('placements', ['code' => 'CAT_TEST']);
    }

    public function test_a_resource_from_another_catalog_is_not_resolved(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $entry = Placement::query()->where('code', 'PLC_PARK')->sole();
        $this->expectException(ModelNotFoundException::class);
        app(CatalogService::class)->historical($actor, 'evidence-types', $entry->id);
    }

    public function test_malformed_resource_ids_return_not_found_in_reusable_services(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->expectException(ModelNotFoundException::class);
        app(CatalogService::class)->historical($actor, 'placements', 'not-a-uuid');
    }

    public function test_a_code_with_a_final_newline_is_rejected_before_sql(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->create($actor, 'placements', $this->input(['code' => "CAT_TEST\n"]));
    }

    #[DataProvider('literalSearches')]
    public function test_search_escapes_sql_pattern_characters(string $query, string $name): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $service = app(CatalogService::class);
        $literal = $service->create($actor, 'placements', $this->input(['code' => 'ESCAPELITERAL', 'name' => $name]));
        $plain = $service->create($actor, 'placements', $this->input(['code' => 'ESCAPEPLAIN', 'name' => 'Valor control']));
        $page = $service->index($actor, 'placements', ['q' => $query])['entries'];

        $this->assertTrue($page->getCollection()->contains('id', $literal->id));
        $this->assertFalse($page->getCollection()->contains('id', $plain->id));
    }

    public static function literalSearches(): array
    {
        return [['%', 'Tasa 20%'], ['_', 'Área_A'], ['\\', 'Sector\\Norte']];
    }

    public function test_http_filters_handle_literal_search_and_active_values(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $service = app(CatalogService::class);
        $service->create($actor, 'placements', $this->input(['code' => 'FILTER_ACTIVE', 'name' => 'Visible 20%']));
        $service->create($actor, 'placements', $this->input(['code' => 'FILTER_INACTIVE', 'name' => 'Oculto 20%', 'active' => false]));
        $this->actingAs($actor);
        $this->get(route('catalogs.index', ['catalog' => 'placements', 'q' => '%', 'active' => '1']))
            ->assertOk()->assertSee('FILTER_ACTIVE')->assertDontSee('FILTER_INACTIVE');
        $this->get(route('catalogs.index', ['catalog' => 'placements', 'q' => '%', 'active' => '0']))
            ->assertOk()->assertSee('FILTER_INACTIVE')->assertDontSee('FILTER_ACTIVE');
    }

    public function test_unknown_catalogs_and_malformed_http_ids_are_not_routes_to_arbitrary_tables(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN'));
        $this->get('/admin/catalogs/users')->assertNotFound();
        $this->get('/admin/catalogs/placements/not-a-uuid/edit')->assertNotFound();
    }

    public function test_catalog_labels_are_escaped_in_blade(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $label = '<script>alert("catálogo")</script>';
        app(CatalogService::class)->create($actor, 'placements', $this->input(['name' => $label]));
        $this->actingAs($actor)->get(route('catalogs.index', 'placements'))->assertOk()
            ->assertSee($label)->assertDontSee($label, false);
    }

    public function test_audit_events_remain_global_even_with_an_active_tenant(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $organization = $this->pilot();
        app(TenantContextService::class)->activate($actor, $organization->id);
        try {
            $service = app(CatalogService::class);
            $entry = $service->create($actor, 'placements', $this->input());
            $service->update($actor, 'placements', $entry->id, $this->input(['lock_version' => $entry->fresh()->lock_version, 'active' => false, 'sort_order' => 42]));
            $service->update($actor, 'placements', $entry->id, $this->input(['lock_version' => $entry->fresh()->lock_version, 'active' => true, 'sort_order' => 42]));
            $events = SecurityEvent::query()->where('metadata->resource_id', $entry->id)->get();

            foreach (['catalog.created', 'catalog.updated', 'catalog.deactivated', 'catalog.activated', 'catalog.reordered'] as $event) {
                $this->assertTrue($events->contains('event', $event), $event);
            }
            foreach ($events as $event) {
                $this->assertNull($event->organization_id);
                $this->assertSame((int) $actor->id, (int) $event->actor_user_id);
                $this->assertEquals([
                    'catalog_key' => 'placements', 'resource_id' => $entry->id, 'resource_code' => 'CAT_TEST',
                ], $event->metadata);
            }
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_catalog_creation_rolls_back_if_audit_persistence_fails(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $this->partialMock(SecurityEventRepository::class)->shouldReceive('record')->once()
            ->andThrow(new RuntimeException('Auditoría de prueba indisponible'));

        try {
            app(CatalogService::class)->create($actor, 'placements', $this->input());
            $this->fail('La transacción debía fallar junto con la auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Auditoría de prueba indisponible', $exception->getMessage());
            $this->assertDatabaseMissing('placements', ['code' => 'CAT_TEST']);
        }
    }

    public function test_service_active_filter_accepts_equivalent_strings_integers_and_booleans(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $service = app(CatalogService::class);
        $active = $service->create($actor, 'placements', $this->input(['code' => 'TYPE_FILTER_ACTIVE']));
        $inactive = $service->create($actor, 'placements', $this->input(['code' => 'TYPE_FILTER_INACTIVE', 'active' => false]));

        foreach ([['1', $active->id], [1, $active->id], [true, $active->id],
            ['0', $inactive->id], [0, $inactive->id], [false, $inactive->id]] as [$filter, $expectedId]) {
            $page = $service->index($actor, 'placements', ['q' => 'TYPE_FILTER', 'active' => $filter])['entries'];

            $this->assertSame([$expectedId], $page->getCollection()->pluck('id')->all(), 'Filtro de tipo '.get_debug_type($filter));
        }
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
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

    private function input(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CAT_TEST', 'name' => 'Valor nacional de prueba', 'description' => null,
            'active' => true, 'sort_order' => 100,
        ], $overrides);
    }

    private function catalogIdentities(): array
    {
        $identities = [];
        foreach (app(CatalogRegistry::class)->all() as $catalog) {
            $identities[$catalog->key] = $catalog->modelClass::query()->orderBy('code')->pluck('id', 'code')->all();
        }

        return $identities;
    }

    private function csrf(): string
    {
        $response = $this->get('/login')->assertOk();
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null) {
            $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        }
        preg_match('/name="_token"\s+value="([^"]+)"/', (string) $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches, 'El formulario debe entregar un token CSRF real.');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}

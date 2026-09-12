<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Services\OrganizationService;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PostgresTestCase;

class OrganizationHttpTest extends PostgresTestCase
{
    public function test_guests_cannot_list_or_write_organizations(): void
    {
        $this->get('/organizations')->assertRedirect(route('login'));
        $this->postJson('/organizations', [])->assertUnauthorized();
    }

    public function test_pilot_views_render_eager_loaded_territory_without_lazy_queries(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $pilot);
        $this->allowNationalManagement($actor);
        $this->actingTenant($actor, $pilot);
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();

        try {
            $this->get('/organizations')->assertOk()->assertSee($pilot->name)->assertSee('Crear organización');
            $this->get('/organizations/'.$pilot->id)->assertOk()->assertSee('Villavicencio, Meta, Colombia.');
            $this->get('/organizations/create')->assertOk()->assertSee('municipality_id')->assertSee('Villavicencio');
            $this->get('/organizations/'.$pilot->id.'/edit')->assertOk()->assertSee($pilot->code);
            $this->get('/territories')->assertOk()->assertSee('Colombia')->assertSee('Meta')->assertSee('Villavicencio');
        } finally {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
        }
    }

    public function test_national_creation_does_not_fabricate_an_organization_membership(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        $input = $this->organizationInput($pilot);
        $response = $this->actingAs($actor)->post('/organizations', $input)->assertRedirect();
        $created = Organization::where('code', $input['code'])->firstOrFail();
        $response->assertRedirect(route('organizations.show', $created->id))
            ->assertSessionHas('active_organization_id', $created->id);

        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        $this->assertSame(0, $created->memberships()->count());
        $this->assertSame($pilot->municipality_id, $created->municipality_id);
        $this->get('/organizations/'.$created->id)->assertOk();
    }

    public function test_inactive_creation_returns_to_listing_without_replacing_active_context(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $pilot);
        $this->allowNationalManagement($actor);
        $input = $this->organizationInput($pilot);
        $input['active'] = false;

        $this->actingTenant($actor, $pilot)->post('/organizations', $input)
            ->assertRedirect(route('organizations.index'))->assertSessionHas('active_organization_id', $pilot->id);
        $this->assertDatabaseHas('organizations', ['code' => $input['code'], 'active' => false]);
        $this->get('/organizations')->assertOk()->assertSee($input['code']);
    }

    public function test_failure_after_inserting_organization_rolls_back_the_transaction(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        $input = $this->organizationInput($pilot);
        $this->partialMock(OrganizationRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')->once()->andReturnUsing(function (array $attributes): never {
                Organization::query()->create($attributes);
                throw new RuntimeException('Fallo simulado después de persistir la organización.');
            });
        });

        try {
            app(OrganizationService::class)->create($actor, $input);
            $this->fail('La operación debía fallar.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('organizations', ['code' => $input['code']]);
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        }
    }

    public function test_editing_organization_preserves_identity_and_municipal_requirement(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $pilot);
        $this->allowManagement($actor);
        $input = $this->organizationInput($pilot);
        $input['name'] = 'Nombre actualizado';
        $this->actingTenant($actor, $pilot)->put('/organizations/'.$pilot->id, $input)->assertRedirect(route('organizations.index'));
        $this->assertDatabaseHas('organizations', ['id' => $pilot->id, 'name' => 'Nombre actualizado', 'municipality_id' => $pilot->municipality_id]);
    }

    #[DataProvider('invalidInputs')]
    public function test_organization_form_rejects_invalid_fields(string $field, mixed $value): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        $input = $this->organizationInput($pilot);
        $input[$field] = $value;
        $this->actingAs($actor)->postJson('/organizations', $input)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidInputs(): array
    {
        return [
            'nombre vacío' => ['name', ''],
            'tipo inválido' => ['type', 'unknown'],
            'correo inválido' => ['email', 'sin-arroba'],
            'municipio obligatorio' => ['municipality_id', null],
            'municipio inexistente' => ['municipality_id', '01950000-0000-7000-8000-000000000009'],
            'UUID inválido' => ['municipality_id', '50001'],
            'código inválido' => ['code', 'CODIGO CON ESPACIOS'],
            'prefijo inválido' => ['tree_code_prefix', 'COL/<script>'],
            'estado inválido' => ['active', 'maybe'],
            'settings protegidos' => ['settings', ['is_admin' => true]],
            'identidad protegida' => ['id', '01950000-0000-7000-8000-000000000010'],
        ];
    }

    public function test_inactive_territorial_parent_is_rejected(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        $pilot->municipality->department->update(['active' => false]);
        $this->actingAs($actor)->postJson('/organizations', $this->organizationInput($pilot))
            ->assertUnprocessable()->assertJsonValidationErrors('municipality_id');
    }

    public function test_duplicate_codes_and_prefixes_are_validation_errors(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        $input = $this->organizationInput($pilot);
        $input['code'] = $pilot->code;
        $input['tree_code_prefix'] = $pilot->tree_code_prefix;
        $this->actingAs($actor)->postJson('/organizations', $input)
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'tree_code_prefix']);
    }

    public function test_invalid_resource_uuid_is_a_404_not_a_database_error(): void
    {
        $pilot = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $pilot);
        $this->actingTenant($actor, $pilot)->get('/organizations/not-a-uuid')->assertNotFound();
    }
}

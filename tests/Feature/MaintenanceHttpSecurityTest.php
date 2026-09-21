<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Maintenance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\MaintenanceService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MaintenanceTestCase;

class MaintenanceHttpSecurityTest extends MaintenanceTestCase
{
    #[DataProvider('foreignActions')]
    public function test_foreign_organization_cannot_access_or_modify_an_order(string $action): void
    {
        $order = $this->order();
        $other = $this->anotherOrganization();
        $outsider = User::factory()->create();
        $this->member($outsider, $other);
        $this->allowManagement($outsider);
        $this->actingTenant($outsider, $other);
        $response = match ($action) {
            'create' => $this->getJson(route('trees.maintenances.create', $this->tree->id)),
            'store' => $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput()),
            'show' => $this->getJson(route('maintenances.show', $order->id)),
            'edit' => $this->getJson(route('maintenances.edit', $order->id)),
            'update' => $this->putJson(route('maintenances.update', $order->id), ['lock_version' => 1, 'notes' => 'Ataque']),
            'status' => $this->patchJson(route('maintenances.status', $order->id), ['lock_version' => 1, 'status' => 'cancelled', 'reason' => 'Ataque']),
            'void' => $this->deleteJson(route('maintenances.void', $order->id), ['lock_version' => 1, 'deletion_notes' => 'Ataque']),
            'restore' => $this->postJson(route('maintenances.restore', $order->id), ['lock_version' => 1]),
        };
        $response->assertNotFound();
        foreach (['trace', 'exception', 'file', 'line'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json());
        }
        $response->assertDontSee($order->code)->assertDontSee('/var/www/', false)->assertDontSee('SQLSTATE', false);
        $this->assertSame(1, $order->fresh()->lock_version);
        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertDatabaseCount('maintenances', 1);
    }

    public static function foreignActions(): array
    {
        return [['create'], ['store'], ['show'], ['edit'], ['update'], ['status'], ['void'], ['restore']];
    }

    #[DataProvider('roles')]
    public function test_roles_have_only_the_authorized_operations(string $role, bool $write, bool $admin): void
    {
        $order = $this->order();
        $actor = $this->maintenanceActor($role);
        $this->actingTenant($actor, $this->organization);
        $this->get(route('maintenances.index'))->assertOk();
        $this->get(route('maintenances.show', $order->id))->assertOk();
        $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput())->assertStatus($write ? 302 : 403);
        $this->putJson(route('maintenances.update', $order->id), $this->maintenanceInput(['lock_version' => 1, 'notes' => 'Cambio autorizado']))->assertStatus($write ? 302 : 403);
        $version = $write ? 2 : 1;
        $this->patchJson(route('maintenances.status', $order->id), ['lock_version' => $version, 'status' => 'cancelled', 'reason' => 'No se realizará.'])->assertStatus($write ? 302 : 403);
        $version = $write ? 3 : 1;
        $this->deleteJson(route('maintenances.void', $order->id), ['lock_version' => $version, 'deletion_notes' => 'Registro de prueba duplicado.'])->assertStatus($admin ? 302 : 403);
        if ($admin) {
            $this->postJson(route('maintenances.restore', $order->id), ['lock_version' => $version + 1])->assertRedirect();
        }
        $this->assertFalse(Maintenance::withTrashed()->findOrFail($order->id)->trashed());
        $this->assertSame($this->admin->id, $order->fresh()->created_by);
    }

    public static function roles(): array
    {
        return [['SUPER_ADMIN', true, true], ['ORGANIZATION_ADMIN', true, true], ['OFFICIAL', true, false], ['DEPARTMENT_ADMIN', false, false]];
    }

    #[DataProvider('roles')]
    public function test_only_administrators_verify_an_executed_order(string $role, bool $write, bool $admin): void
    {
        $order = $this->executedOrder();
        $actor = $this->maintenanceActor($role);
        $this->actingTenant($actor, $this->organization);
        $this->patchJson(route('maintenances.status', $order->id), [
            'lock_version' => $order->lock_version, 'status' => 'verified', 'reason' => 'Resultado revisado.',
        ])->assertStatus($admin ? 302 : 403);
        $this->assertSame($admin ? 'verified' : 'executed', $order->fresh()->status->value);
    }

    public function test_stale_update_returns_409_and_preserves_first_change(): void
    {
        $order = $this->order();
        $this->actingTenant($this->admin, $this->organization);
        $payload = $this->maintenanceInput(['lock_version' => 1, 'notes' => 'Primera edición confirmada']);
        $this->putJson(route('maintenances.update', $order->id), $payload)->assertRedirect();
        $response = $this->putJson(route('maintenances.update', $order->id), array_replace($payload, ['notes' => 'Edición obsoleta']))->assertStatus(409);
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertSame('Primera edición confirmada', $order->fresh()->notes);
        $this->assertSame(2, $order->fresh()->lock_version);
        $this->assertDatabaseCount('maintenance_revisions', 2);
    }

    public function test_membership_revocation_blocks_a_previously_authenticated_actor(): void
    {
        $order = $this->order();
        $actor = $this->maintenanceActor('OFFICIAL');
        $this->actingTenant($actor, $this->organization);
        $this->get(route('maintenances.show', $order->id))->assertOk();
        DB::table('organization_memberships')->where('organization_id', $this->organization->id)->where('user_id', $actor->id)->update(['active' => false, 'left_at' => now()]);
        $this->getJson(route('maintenances.show', $order->id))->assertForbidden();
        $this->putJson(route('maintenances.update', $order->id), $this->maintenanceInput(['lock_version' => 1]))->assertForbidden();
        $this->assertSame(1, $order->fresh()->lock_version);
        $this->assertFalse(app(TenantContext::class)->isActive());
    }

    public function test_tree_view_permission_is_also_required_to_access_and_create_orders(): void
    {
        $order = $this->order();
        $actor = $this->maintenanceActor('OFFICIAL');
        $role = Role::query()->where('name', 'OFFICIAL')->sole();
        $role->permissions()->detach(Permission::query()->where('code', 'trees.view')->value('id'));
        $this->actingTenant($actor, $this->organization);
        $this->getJson(route('maintenances.index'))->assertForbidden();
        $this->getJson(route('maintenances.show', $order->id))->assertForbidden();
        $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput())->assertForbidden();
        $this->assertDatabaseCount('maintenances', 1);
    }

    public function test_superadmin_requires_tenant_and_does_not_receive_a_fake_membership(): void
    {
        $actor = $this->maintenanceActor('SUPER_ADMIN');
        $this->actingAs($actor);
        $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput())->assertForbidden();
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        $this->assertDatabaseCount('maintenances', 0);
    }

    public function test_anonymous_client_cannot_view_or_create_orders(): void
    {
        $order = $this->order();
        $this->get(route('maintenances.show', $order->id))->assertRedirect(route('login'));
        $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput())->assertUnauthorized();
        $this->assertDatabaseCount('maintenances', 1);
    }

    public function test_real_csrf_protects_maintenance_creation(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingTenant($this->admin, $this->organization);
        $form = $this->get(route('trees.maintenances.create', $this->tree->id))->assertOk();
        preg_match('/name="_token"\s+value="([^"]+)"/', $form->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput())->assertStatus(419);
        $this->postJson(route('trees.maintenances.store', $this->tree->id), $this->maintenanceInput([
            '_token' => html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'),
        ]))->assertRedirect();
        $this->assertDatabaseCount('maintenances', 1);
    }

    public function test_index_cannot_reveal_orders_of_a_voided_tree_to_an_official(): void
    {
        $order = $this->order();
        app(\App\Services\TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->fresh()->lock_version,
            'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
        $official = $this->maintenanceActor('OFFICIAL');
        $this->actingTenant($official, $this->organization);
        $this->get(route('maintenances.index', ['include_voided_trees' => 1]))
            ->assertOk()->assertDontSee($order->code)->assertDontSee($this->tree->code);
        $this->getJson(route('maintenances.show', $order->id))->assertForbidden();
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('maintenances.index'))->assertOk()->assertSee($order->code);
        $this->get(route('maintenances.show', $order->id))->assertOk();
    }

    public function test_filters_normalize_empty_values_and_validate_date_order(): void
    {
        $order = $this->order(['scheduled_date' => '2026-10-05']);
        $reader = app(\App\Services\MaintenanceReadService::class);
        $result = $reader->index($this->admin, ['date_from' => '2026-10-01', 'date_to' => '', 'assigned_to' => '   ']);
        $this->assertSame(1, $result['maintenances']->total());
        $this->assertNull($result['filters']['date_to']);
        $this->assertNull($result['filters']['assigned_to']);
        $this->actingTenant($this->admin, $this->organization);
        $this->getJson(route('maintenances.index', ['date_from' => '2026-10-15', 'date_to' => '2026-10-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->assertSame(1, $order->fresh()->lock_version);
    }

    public function test_regular_gallery_hides_voided_order_until_admin_restores_it(): void
    {
        $order = $this->order(['notes' => 'ORDEN-ANULADA-UNICA-PRUEBA']);
        app(MaintenanceService::class)->void($this->admin, $order->id, ['lock_version' => 1, 'deletion_notes' => 'Creación accidental']);
        $official = $this->maintenanceActor('OFFICIAL');
        $this->actingTenant($official, $this->organization);
        $this->get(route('maintenances.index'))->assertOk()->assertDontSee($order->code);
        $this->getJson(route('maintenances.show', $order->id))->assertNotFound();
        $this->getJson(route('maintenances.index', ['trashed' => 'only']))->assertForbidden();
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('maintenances.index', ['trashed' => 'only']))->assertOk()->assertSee($order->code);
        $this->get(route('maintenances.show', $order->id))->assertOk();
    }
}

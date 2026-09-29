<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tree;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use Tests\PostgresTestCase;

class TreeCaptureCalendarTest extends PostgresTestCase
{
    public function test_calendar_create_converts_colombian_time_across_utc_day_boundary(): void
    {
        $input = $this->captureInput();
        $this->post(route('trees.store'), $input + ['captured_at_local' => '2026-09-28T23:30'])
            ->assertRedirect();
        $tree = Tree::query()->sole();
        $this->assertSame('2026-09-29T04:30:00.000000+00:00', $tree->captured_at->utc()->format('Y-m-d\TH:i:s.uP'));
        $this->get(route('trees.show', $tree->id))->assertOk()
            ->assertSee('28/09/2026 23:30:00.000000 (hora de Colombia)');
    }

    public function test_location_calendar_preserves_hidden_precision_and_allows_change_or_clear(): void
    {
        $input = $this->captureInput();
        $this->post(route('trees.store'), $input + ['captured_at' => '2026-09-28T07:30:15.123456Z'])->assertRedirect();
        $tree = Tree::query()->sole();
        $this->get(route('trees.show', $tree->id))->assertOk()
            ->assertSee('value="2026-09-28T02:30:15.123"', false);
        $this->patch(route('trees.location', $tree->id), [
            'lock_version' => 1, 'captured_at_local' => '2026-09-28T02:30:15.123',
            'gps_brand' => 'Equipo confirmado', 'location_change_type' => 'correction', 'location_reason' => 'Confirmación de equipo',
        ])->assertRedirect();
        $this->assertSame('2026-09-28T07:30:15.123456+00:00', $tree->fresh()->captured_at->utc()->format('Y-m-d\TH:i:s.uP'));
        $this->patch(route('trees.location', $tree->id), [
            'lock_version' => 2, 'captured_at_local' => '2026-09-29T00:15:25.5',
            'location_change_type' => 'correction', 'location_reason' => 'Hora corregida con el equipo',
        ])->assertRedirect();
        $this->assertSame('2026-09-29T05:15:25.500000+00:00', $tree->fresh()->captured_at->utc()->format('Y-m-d\TH:i:s.uP'));
        $this->patch(route('trees.location', $tree->id), [
            'lock_version' => 3, 'captured_at_local' => '',
            'location_change_type' => 'correction', 'location_reason' => 'La fecha no pudo confirmarse',
        ])->assertRedirect();
        $this->assertNull($tree->fresh()->captured_at);
    }

    public function test_calendar_rejects_invalid_or_ambiguous_dates_without_creating_a_tree(): void
    {
        $input = $this->captureInput();
        foreach (['2026-02-30T10:00', '2026-09-28T25:00', '2026-09-28T24:00', '2026-09-28T23:59:60', 'tomorrow', '2026-09-28', '2026-09-28T10:00Z'] as $invalid) {
            $this->post(route('trees.store'), $input + ['captured_at_local' => $invalid])
                ->assertSessionHasErrors('captured_at_local');
            $this->assertDatabaseCount('trees', 0);
        }
        $this->post(route('trees.store'), $input + [
            'captured_at_local' => '2026-09-28T10:00', 'captured_at' => '2026-09-28T15:00:00Z',
        ])->assertSessionHasErrors('captured_at_local');
        $this->assertDatabaseCount('trees', 0);
    }

    public function test_calendar_error_preserves_selection_and_is_associated_with_its_input(): void
    {
        $input = $this->captureInput();
        $response = $this->from(route('trees.create'))->post(route('trees.store'), $input + [
            'captured_at_local' => '2026-09-28T10:00', 'captured_at' => '2026-09-28T15:00:00Z',
        ])->assertRedirect(route('trees.create'))->assertSessionHasErrors('captured_at_local');
        // El siguiente GET conserva la cookie de sesión emitida por el POST, igual que el navegador.
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        $this->get(route('trees.create'))->assertOk()->assertSee('value="2026-09-28T10:00"', false)
            ->assertSee('aria-invalid="true"', false)->assertSee('id="create-time-error"', false);
    }

    public function test_calendar_unknown_capture_remains_null(): void
    {
        $input = $this->captureInput();
        $this->post(route('trees.store'), $input + ['captured_at_local' => ''])->assertRedirect();
        $this->assertNull(Tree::query()->sole()->captured_at);
    }

    private function captureInput(): array
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->allowManagement($actor);
        app(TenantContextService::class)->activate($actor, $organization->id);
        $inventory = app(InventoryService::class)->create($actor, [
            'code' => 'CALENDAR', 'name' => 'Prueba de calendario', 'status' => 'active', 'active' => true,
        ]);
        $this->actingTenant($actor, $organization);

        return ['inventory_ids' => [$inventory->id], 'current_status' => 'active'];
    }
}

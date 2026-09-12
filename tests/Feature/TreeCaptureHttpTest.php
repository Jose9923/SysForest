<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tree;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use Tests\PostgresTestCase;

class TreeCaptureHttpTest extends PostgresTestCase
{
    public function test_preview_preserves_raw_text_and_does_not_persist_a_tree(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $raw = '  4.154, -73.633  ';
        $this->actingTenant($actor, $organization)->postJson('/coordinates/preview', ['coordinate_raw' => $raw])
            ->assertOk()->assertJsonPath('raw', $raw)->assertJsonPath('latitude', 4.154)
            ->assertJsonPath('longitude', -73.633)->assertJsonPath('format', 'decimal');
        $this->assertDatabaseCount('trees', 0);
        $this->assertDatabaseCount('tree_location_histories', 0);
    }

    public function test_http_create_preserves_original_gps_text_and_unknown_metadata(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $this->allowManagement($actor);
        app(TenantContextService::class)->activate($actor, $organization->id);
        $inventory = app(InventoryService::class)->create($actor, ['code' => 'CAPTURE', 'name' => 'Prueba de captura', 'status' => 'active', 'active' => true]);
        $raw = '  4°15\'19.2" N, 73°34\'19.1" W  ';
        $this->actingTenant($actor, $organization)->post('/trees', ['inventory_ids' => [$inventory->id], 'current_status' => 'active', 'coordinate_raw' => $raw])->assertRedirect();
        $tree = Tree::query()->sole();
        $this->assertSame($raw, $tree->coordinate_raw);
        $this->assertSame('dms', $tree->coordinate_format);
        $this->assertNull($tree->datum);
        $this->assertNull($tree->accuracy_m);
        $this->assertNull($tree->gps_brand);
        $this->assertNull($tree->gps_model);
        $this->assertNull($tree->captured_at);
    }

    public function test_invalid_coordinate_preview_returns_spanish_validation_without_writing(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->member($actor, $organization);
        $response = $this->actingTenant($actor, $organization)->postJson('/coordinates/preview', ['coordinate_raw' => '4, -73; SELECT 1']);
        $response->assertUnprocessable()->assertJsonValidationErrors('coordinate_raw');
        $this->assertStringContainsString('Usa latitud', $response->json('errors.coordinate_raw.0'));
        $this->assertDatabaseCount('trees', 0);
    }

    public function test_department_read_does_not_authorize_capture_preview(): void
    {
        $organization = $this->pilot();
        $actor = User::factory()->create();
        $this->ensureAuthorizationCatalog();
        app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $organization->municipality->department_id);
        $this->actingTenant($actor, $organization)->postJson('/coordinates/preview', ['coordinate_raw' => '4, -73'])->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\EvidenceRevision;
use App\Models\Maintenance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\EvidenceRepository;
use App\Services\EvidenceReadService;
use App\Services\EvidenceService;
use App\Services\MaintenanceService;
use App\Services\TreeLifecycleService;
use App\Validation\EvidenceInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MaintenanceTestCase;

class MaintenanceEvidenceTest extends MaintenanceTestCase
{
    public function test_before_and_after_images_belong_only_to_the_maintenance_with_original_bytes_and_audit(): void
    {
        $order = $this->order();
        $before = $this->photo($order);
        $after = $this->photo($order, 'EVD_MAINTENANCE_AFTER');
        $this->assertEqualsCanonicalizing([$before->id, $after->id], $order->evidences()->pluck('id')->all());
        $this->assertSame(0, $this->tree->evidences()->count());
        foreach ([$before, $after] as $evidence) {
            $this->assertNull($evidence->tree_id);
            $this->assertNull($evidence->tree_inspection_id);
            $this->assertSame($order->id, $evidence->maintenance_id);
            $this->assertSame($this->tree->id, $evidence->maintenance->tree->id);
            $this->assertSame($order->id, $evidence->ownerRecord()->id);
            $this->assertSame($this->admin->id, $evidence->uploaded_by);
            $this->assertSame($evidence->checksum_sha256, hash('sha256', Storage::disk('evidences')->get($evidence->path)));
            $revision = EvidenceRevision::query()->where('evidence_id', $evidence->id)->sole();
            $this->assertSame($order->id, $revision->new_values['maintenance_id']);
            $this->assertNull($revision->new_values['tree_id']);
        }
        $reader = app(EvidenceReadService::class);
        $gallery = $reader->gallery($this->admin, $order);
        $this->assertSame(2, $gallery['total']);
        $this->assertSame(route('maintenances.show', $order->id), $gallery['ownerUrl']);
        $this->assertSame(route('maintenances.evidences.index', $order->id), $gallery['indexUrl']);
        $this->assertSame(route('maintenances.evidences.create', $order->id), $gallery['uploadUrl']);
        $this->assertStringContainsString($order->code, $gallery['ownerLabel']);
        $this->assertSame(route('maintenances.show', $order->id), $reader->show($this->admin, $before->id)['ownerUrl']);
    }

    #[DataProvider('invalidOwnerCombinations')]
    public function test_application_and_database_require_exactly_one_of_three_owners(bool $tree, bool $inspection, bool $maintenance): void
    {
        $visit = $this->capture();
        $order = $this->order();
        $ids = [$tree ? $this->tree->id : null, $inspection ? $visit->id : null, $maintenance ? $order->id : null];
        try {
            EvidenceInput::owner(...$ids);
            $this->fail('La aplicación debe rechazar una selección ambigua de propietario.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('owner', $exception->errors());
        }
        $original = $this->photo($order);
        $attributes = array_replace($original->getRawOriginal(), [
            'id' => (string) Str::uuid(), 'path' => 'test/'.Str::uuid().'.jpg',
            'tree_id' => $ids[0], 'tree_inspection_id' => $ids[1], 'maintenance_id' => $ids[2],
        ]);
        $this->sqlFailure(fn () => DB::table('evidences')->insert($attributes), '23514');
        $this->assertDatabaseCount('evidences', 1);
    }

    public static function invalidOwnerCombinations(): array
    {
        return [[false, false, false], [true, true, false], [true, false, true], [false, true, true], [true, true, true]];
    }

    public function test_composite_foreign_key_rejects_a_maintenance_of_another_organization(): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $other = $this->anotherOrganization($this->organization);
        $attributes = array_replace($evidence->getRawOriginal(), [
            'id' => (string) Str::uuid(), 'path' => 'test/'.Str::uuid().'.jpg', 'organization_id' => $other->id,
        ]);
        $this->sqlFailure(fn () => DB::table('evidences')->insert($attributes), '23503');
    }

    public function test_maintenance_owner_is_immutable_in_input_repository_and_database(): void
    {
        $order = $this->order();
        $other = $this->order();
        $evidence = $this->photo($order);
        try {
            app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => 1, 'maintenance_id' => $other->id]);
            $this->fail('El navegador no puede mover una fotografía entre órdenes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('maintenance_id', $exception->errors());
        }
        $changed = app(EvidenceRepository::class)->update($evidence, ['caption' => 'Descripción corregida', 'maintenance_id' => $other->id]);
        $this->assertSame($order->id, $changed->maintenance_id);
        $this->sqlFailure(fn () => DB::table('evidences')->where('id', $evidence->id)->update(['maintenance_id' => $other->id]), '23514');
        $this->sqlFailure(fn () => DB::table('evidences')->where('id', $evidence->id)->update(['maintenance_id' => null, 'tree_id' => $this->tree->id]), '23514');
        $this->sqlFailure(fn () => DB::table('evidences')->where('id', $evidence->id)->delete(), '23514');
        $this->assertSame($order->id, $evidence->fresh()->maintenance_id);
    }

    public function test_rollback_refuses_to_discard_existing_maintenance_photographs(): void
    {
        $evidence = $this->photo($this->order());
        $migration = require database_path('migrations/2026_09_21_000016_add_maintenance_owner_to_evidences.php');
        $this->sqlFailure(fn () => $migration->down(), '23514');
        $this->assertSame($evidence->maintenance_id, $evidence->fresh()->maintenance_id);
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    public function test_repository_filters_batches_and_summary_keep_all_three_owner_kinds_separate(): void
    {
        $order = $this->order();
        $before = $this->photo($order);
        $after = $this->photo($order, 'EVD_MAINTENANCE_AFTER');
        $treeEvidence = $this->attachEvidence();
        $visit = $this->capture();
        $inspectionEvidence = $this->attachEvidence(inspectionId: $visit->id);
        $repository = app(EvidenceRepository::class);
        $this->assertCount(2, $repository->forOwner($this->organization->id, null, null, [], $order->id));
        $this->assertSame([$after->id], $repository->forOwner($this->organization->id, null, null, ['evidence_type_id' => $after->evidence_type_id], $order->id)->modelKeys());
        $summary = $repository->summaryForOwner($this->organization->id, null, null, 1, $order->id);
        $this->assertSame(2, $summary->total());
        $this->assertCount(1, $summary->items());
        $batch = $repository->forOwners($this->organization->id, [$this->tree->id], [$visit->id], [], [$order->id]);
        $this->assertEqualsCanonicalizing([$before->id, $after->id, $treeEvidence->id, $inspectionEvidence->id], $batch->modelKeys());
        $other = $this->anotherOrganization($this->organization);
        $this->assertCount(0, $repository->forOwners($other->id, [], [], [], [$order->id]));
    }

    #[DataProvider('roles')]
    public function test_private_maintenance_gallery_and_upload_obey_role_permissions(string $role, bool $mayWrite): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $actor = $this->maintenanceActor($role);
        $this->actingTenant($actor, $this->organization);
        $this->get(route('maintenances.evidences.index', $order->id))->assertOk()->assertSee($order->code);
        $this->get(route('evidences.view', $evidence->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson(route('maintenances.evidences.store', $order->id), $this->evidenceInput([
            'evidence_type_id' => $this->evidenceType('EVD_MAINTENANCE_AFTER')->id,
        ]))->assertStatus($mayWrite ? 201 : 403);
        $this->assertDatabaseCount('evidences', $mayWrite ? 2 : 1);
    }

    public static function roles(): array
    {
        return [['SUPER_ADMIN', true], ['DEPARTMENT_ADMIN', false], ['ORGANIZATION_ADMIN', true], ['OFFICIAL', true]];
    }

    #[DataProvider('foreignActions')]
    public function test_cross_tenant_owner_and_evidence_routes_reveal_nothing(string $action): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $other = $this->anotherOrganization($this->organization);
        $actor = User::factory()->create();
        $this->member($actor, $other);
        $this->actingTenant($actor, $other);
        $response = match ($action) {
            'gallery' => $this->getJson(route('maintenances.evidences.index', $order->id)),
            'form' => $this->getJson(route('maintenances.evidences.create', $order->id)),
            'upload' => $this->postJson(route('maintenances.evidences.store', $order->id), $this->evidenceInput()),
            'show' => $this->getJson(route('evidences.show', $evidence->id)),
            'view' => $this->getJson(route('evidences.view', $evidence->id)),
            'download' => $this->getJson(route('evidences.download', $evidence->id)),
            'update' => $this->putJson(route('evidences.update', $evidence->id), ['lock_version' => 1, 'caption' => 'Ajeno']),
            'void' => $this->deleteJson(route('evidences.void', $evidence->id), ['lock_version' => 1]),
            'restore' => $this->postJson(route('evidences.restore', $evidence->id), ['lock_version' => 1]),
        };
        $response->assertNotFound();
        foreach ([$evidence->path, $evidence->original_name, $order->code] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame(1, $evidence->fresh()->lock_version);
        $this->assertDatabaseCount('evidences', 1);
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    public static function foreignActions(): array
    {
        return array_map(fn ($action) => [$action], ['gallery', 'form', 'upload', 'show', 'view', 'download', 'update', 'void', 'restore']);
    }

    public function test_evidence_permission_cannot_bypass_revoked_maintenance_view_permission(): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $actor = $this->maintenanceActor('OFFICIAL');
        $role = Role::query()->where('name', 'OFFICIAL')->sole();
        $role->permissions()->detach(Permission::query()->where('code', 'maintenances.view')->sole()->id);
        $this->assertTrue($role->permissions()->where('code', 'evidences.view')->exists());
        $this->actingTenant($actor, $this->organization);
        $this->getJson(route('evidences.view', $evidence->id))->assertForbidden();
        $this->getJson(route('maintenances.evidences.index', $order->id))->assertForbidden();
        $this->postJson(route('maintenances.evidences.store', $order->id), $this->evidenceInput())->assertForbidden();
    }

    public function test_voided_maintenance_preserves_private_history_and_blocks_upload_and_edit_until_restored(): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $voided = app(MaintenanceService::class)->void($this->admin, $order->id, ['lock_version' => 1, 'deletion_notes' => 'Orden duplicada']);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        $this->get(route('evidences.view', $evidence->id))->assertOk();
        $this->postJson(route('maintenances.evidences.store', $order->id), $this->evidenceInput())->assertForbidden();
        $this->putJson(route('evidences.update', $evidence->id), ['lock_version' => 1, 'caption' => 'Bloqueada'])->assertForbidden();
        $this->actingTenant($this->maintenanceActor('OFFICIAL'), $this->organization);
        $this->getJson(route('evidences.view', $evidence->id))->assertForbidden();
        $this->activateInspectionTenant();
        app(MaintenanceService::class)->restore($this->admin, $order->id, ['lock_version' => $voided->lock_version]);
        $updated = app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => 1, 'caption' => 'Orden restaurada']);
        $this->assertSame('Orden restaurada', $updated->caption);
        $this->assertSame($evidence->checksum_sha256, $updated->checksum_sha256);
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    public function test_evidence_void_and_restore_preserve_original_file_and_maintenance(): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $service = app(EvidenceService::class);
        $voided = $service->void($this->admin, $evidence->id, ['lock_version' => 1, 'deletion_notes' => 'Selección equivocada']);
        $this->assertTrue($voided->trashed());
        $this->assertSame(0, app(EvidenceReadService::class)->gallery($this->admin, $order)['total']);
        $restored = $service->restore($this->admin, $evidence->id, ['lock_version' => 2]);
        $this->assertFalse($restored->trashed());
        $this->assertSame($order->id, $restored->maintenance_id);
        $this->assertSame($evidence->path, $restored->path);
        $this->assertSame($evidence->uploaded_by, $restored->uploaded_by);
        $this->assertSame(3, $restored->lock_version);
        $this->assertDatabaseCount('evidence_revisions', 3);
    }

    public function test_executed_and_verified_orders_still_accept_evidence(): void
    {
        $order = $this->executedOrder();
        $first = $this->photo($order, 'EVD_MAINTENANCE_AFTER');
        $order = $this->advance($order, 'verified');
        $second = $this->photo($order, 'EVD_MAINTENANCE_AFTER');
        $this->assertSame($order->id, $first->maintenance_id);
        $this->assertSame($order->id, $second->maintenance_id);
        $this->assertCount(2, $order->evidences);
    }

    public function test_voided_root_tree_blocks_maintenance_uploads_and_metadata_changes(): void
    {
        $order = $this->order();
        $evidence = $this->photo($order);
        $reason = \App\Models\DeletionReason::query()->where('active', true)->firstOrFail();
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->lock_version, 'deletion_reason_id' => $reason->id, 'deletion_notes' => 'Prueba técnica',
        ]);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('evidences.show', $evidence->id))->assertOk();
        $this->postJson(route('maintenances.evidences.store', $order->id), $this->evidenceInput())->assertForbidden();
        $this->putJson(route('evidences.update', $evidence->id), ['lock_version' => 1, 'caption' => 'Bloqueada'])->assertForbidden();
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    private function photo(Maintenance $order, string $type = 'EVD_MAINTENANCE_BEFORE'): Evidence
    {
        return app(EvidenceService::class)->upload($this->admin, null, null, $this->evidenceInput([
            'evidence_type_id' => $this->evidenceType($type)->id,
        ]), $order->id)->sole();
    }

    private function sqlFailure(callable $operation, string $state): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL debe impedir esta operación.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Maintenance;
use App\Models\MaintenanceType;
use App\Models\User;
use App\Services\MaintenanceAuditService;
use App\Services\MaintenanceService;
use App\Services\TreeLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\MaintenanceTestCase;

class MaintenanceDomainTest extends MaintenanceTestCase
{
    public function test_manual_order_has_institutional_identity_and_initial_history(): void
    {
        $first = $this->order(['assigned_to' => $this->admin->id]);
        $second = $this->order();
        $this->assertSame('pending', $first->status->value);
        $this->assertSame('normal', $first->priority->value);
        $this->assertSame('MNT-000001', $first->code);
        $this->assertSame('MNT-000002', $second->code);
        $this->assertSame($this->organization->id, $first->organization_id);
        $this->assertSame($this->tree->id, $first->tree_id);
        $this->assertSame($this->admin->id, $first->created_by);
        $this->assertSame(1, $first->lock_version);
        $this->assertDatabaseCount('maintenance_revisions', 2);
        $this->assertDatabaseCount('maintenance_status_histories', 2);
        $this->assertSame(2, $this->tree->maintenances()->count());
    }

    public function test_inspection_does_not_create_an_order_automatically(): void
    {
        $this->capture();
        $this->assertDatabaseCount('maintenances', 0);
        $this->assertDatabaseCount('maintenance_status_histories', 0);
    }

    public function test_full_workflow_records_every_transition_without_changing_the_tree(): void
    {
        $treeBefore = DB::table('trees')->where('id', $this->tree->id)->first();
        $order = $this->order(['scheduled_date' => null]);
        $order = $this->advance($order, 'scheduled', ['scheduled_date' => now()->addDay()->toDateString()]);
        $order = $this->advance($order, 'assigned', ['assigned_to' => $this->admin->id]);
        $order = $this->advance($order, 'in_progress');
        $order = $this->advance($order, 'executed', ['performed_date' => now()->toDateString(), 'result' => 'Trabajo completado.']);
        $order = $this->advance($order, 'verified', ['reason' => 'Revisión administrativa terminada.']);
        $this->assertSame('verified', $order->status->value);
        $this->assertSame(6, $order->lock_version);
        $this->assertSame('Trabajo completado.', $order->result);
        $this->assertDatabaseCount('maintenance_revisions', 6);
        $this->assertDatabaseCount('maintenance_status_histories', 6);
        $this->assertEquals($treeBefore, DB::table('trees')->where('id', $this->tree->id)->first());
    }

    #[DataProvider('invalidPendingTransitions')]
    public function test_pending_rejects_invalid_state_changes(string $status): void
    {
        $order = $this->order();
        try {
            $this->advance($order, $status);
            $this->fail('La transición inválida fue aceptada.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertSame(1, $order->fresh()->lock_version);
            $this->assertDatabaseCount('maintenance_status_histories', 1);
        }
    }

    public static function invalidPendingTransitions(): array
    {
        return [['pending'], ['in_progress'], ['executed'], ['verified'], ['unknown']];
    }

    public function test_scheduling_requires_a_date_and_assignment_requires_a_responsible_member(): void
    {
        $order = $this->order(['scheduled_date' => null]);
        foreach (['scheduled', 'assigned'] as $status) {
            try {
                $this->advance($order, $status);
                $this->fail('Se aceptó una transición incompleta.');
            } catch (ValidationException) {
                $this->assertSame(1, $order->fresh()->lock_version);
            }
        }
    }

    public function test_rescheduling_releases_previous_assignment_and_keeps_history(): void
    {
        $order = $this->advance($this->order(['assigned_to' => $this->admin->id]), 'assigned');
        $order = $this->advance($order, 'scheduled', ['scheduled_date' => now()->addDays(2)->toDateString()]);
        $this->assertSame('scheduled', $order->status->value);
        $this->assertNull($order->assigned_to);
        $this->assertDatabaseCount('maintenance_status_histories', 3);
    }

    #[DataProvider('invalidExecutions')]
    public function test_execution_requires_valid_date_and_result(string $kind): void
    {
        $order = $this->advance($this->order(['assigned_to' => $this->admin->id]), 'assigned');
        $order = $this->advance($order, 'in_progress');
        $values = ['performed_date' => now()->toDateString(), 'result' => 'Resultado real'];
        $values = match ($kind) {
            'future' => array_replace($values, ['performed_date' => now()->addDay()->toDateString()]),
            'invalid_date' => array_replace($values, ['performed_date' => '2026-02-30']),
            'missing_date' => array_replace($values, ['performed_date' => null]),
            'empty_result' => array_replace($values, ['result' => '']),
            'blank_result' => array_replace($values, ['result' => '   ']),
        };
        $this->expectException(ValidationException::class);
        $this->advance($order, 'executed', $values);
    }

    public static function invalidExecutions(): array
    {
        return [['future'], ['invalid_date'], ['missing_date'], ['empty_result'], ['blank_result']];
    }

    public function test_cancel_is_operational_and_is_not_soft_delete(): void
    {
        $order = $this->advance($this->order(), 'cancelled', ['reason' => 'Intervención que ya no se requiere.']);
        $this->assertSame('cancelled', $order->status->value);
        $this->assertFalse($order->trashed());
        $this->assertSame(2, $order->lock_version);
        $this->assertDatabaseCount('maintenance_status_histories', 2);
        $this->expectException(AuthorizationException::class);
        $this->advance($order, 'assigned', ['assigned_to' => $this->admin->id]);
    }

    public function test_cancellation_requires_a_non_blank_reason(): void
    {
        $this->expectException(ValidationException::class);
        $this->advance($this->order(), 'cancelled', ['reason' => '   ']);
    }

    public function test_execution_cannot_be_cancelled_or_silently_edited(): void
    {
        $order = $this->executedOrder();
        try {
            $this->advance($order, 'cancelled', ['reason' => 'Intento de ocultar ejecución']);
            $this->fail('No se debe borrar el hecho de una ejecución.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertSame('executed', $order->fresh()->status->value);
        }
        $this->expectException(AuthorizationException::class);
        app(MaintenanceService::class)->update($this->admin, $order->id, ['lock_version' => $order->lock_version, 'notes' => 'Edición posterior']);
    }

    public function test_officials_collaborate_preserving_the_original_creator(): void
    {
        $author = $this->maintenanceActor('OFFICIAL');
        $colleague = $this->maintenanceActor('OFFICIAL');
        $this->activateInspectionTenant($author);
        $order = $this->order(actor: $author);
        $this->activateInspectionTenant($colleague);
        $updated = app(MaintenanceService::class)->update($colleague, $order->id, ['lock_version' => 1, 'notes' => 'Corrección de un colega']);
        $this->assertSame($author->id, $updated->created_by);
        $this->assertSame($colleague->id, $updated->updated_by);
        $this->assertSame('Corrección de un colega', $updated->notes);
        $this->assertDatabaseCount('maintenance_revisions', 2);
    }

    #[DataProvider('invalidAssignees')]
    public function test_assignment_rejects_users_without_current_institutional_eligibility(string $kind): void
    {
        $user = User::factory()->create();
        $other = $this->anotherOrganization();
        $membership = $this->member($user, $kind === 'foreign' ? $other : $this->organization);
        match ($kind) {
            'inactive_user' => $user->forceFill(['active' => false])->save(),
            'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
            'inactive_member' => $membership->forceFill(['active' => false])->save(),
            'left' => $membership->forceFill(['active' => false, 'left_at' => now()])->save(),
            'no_role' => DB::table('organization_role_assignments')->where('membership_id', $membership->id)->delete(),
            default => null,
        };
        $this->expectException(ValidationException::class);
        $this->order(['assigned_to' => $user->id]);
    }

    public static function invalidAssignees(): array
    {
        return [['foreign'], ['inactive_user'], ['unverified'], ['inactive_member'], ['left'], ['no_role']];
    }

    public function test_revoked_assignee_remains_historical_but_cannot_start_work(): void
    {
        $user = $this->maintenanceActor('OFFICIAL');
        $order = $this->advance($this->order(['assigned_to' => $user->id]), 'assigned');
        $user->forceFill(['active' => false])->save();
        $updated = app(MaintenanceService::class)->update($this->admin, $order->id, ['lock_version' => $order->lock_version, 'notes' => 'Responsable pendiente de reasignar']);
        $this->assertSame($user->id, $updated->assigned_to);
        $this->expectException(ValidationException::class);
        $this->advance($updated, 'in_progress');
    }

    public function test_inactive_type_can_be_preserved_but_not_selected_for_another_order(): void
    {
        $order = $this->order();
        MaintenanceType::query()->whereKey($order->maintenance_type_id)->update(['active' => false]);
        $updated = app(MaintenanceService::class)->update($this->admin, $order->id, ['lock_version' => 1, 'notes' => 'Corrección histórica']);
        $this->assertSame($order->maintenance_type_id, $updated->maintenance_type_id);
        $this->expectException(ValidationException::class);
        $this->order();
    }

    public function test_void_and_restore_preserve_identity_state_and_authorship(): void
    {
        $order = $this->executedOrder();
        $voided = app(MaintenanceService::class)->void($this->admin, $order->id, [
            'lock_version' => $order->lock_version, 'deletion_notes' => 'Orden duplicada por error de registro.',
        ]);
        $this->assertTrue($voided->trashed());
        $this->assertSame($this->admin->id, $voided->deleted_by);
        $this->assertSame('executed', $voided->status->value);
        $this->assertSame(0, Maintenance::query()->count());
        $restored = app(MaintenanceService::class)->restore($this->admin, $order->id, ['lock_version' => $voided->lock_version]);
        $this->assertFalse($restored->trashed());
        $this->assertSame($order->id, $restored->id);
        $this->assertSame($order->code, $restored->code);
        $this->assertSame($order->created_by, $restored->created_by);
        $this->assertSame($order->result, $restored->result);
        $this->assertNull($restored->deleted_by);
        $this->assertDatabaseCount('maintenance_status_histories', 4);
        $this->assertDatabaseCount('maintenance_revisions', 6);
    }

    #[DataProvider('forgedFields')]
    public function test_creation_rejects_server_owned_attributes(string $field, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        $this->order([$field => $value]);
    }

    public static function forgedFields(): array
    {
        return [['id', '11111111-1111-4111-8111-111111111111'], ['organization_id', '11111111-1111-4111-8111-111111111111'],
            ['tree_id', '11111111-1111-4111-8111-111111111111'], ['code', 'MANUAL-1'], ['created_by', 999],
            ['updated_by', 999], ['status', 'verified'], ['lock_version', 99], ['performed_date', '2026-01-01'],
            ['result', 'Ejecución falsa'], ['deleted_by', 999], ['deleted_at', '2026-01-01']];
    }

    #[DataProvider('invalidVersions')]
    public function test_update_rejects_ambiguous_or_invalid_versions(mixed $version): void
    {
        $order = $this->order();
        $this->expectException(ValidationException::class);
        app(MaintenanceService::class)->update($this->admin, $order->id, ['lock_version' => $version, 'notes' => 'Intento inválido']);
    }

    public static function invalidVersions(): array
    {
        return [[null], [true], [false], [1.0], ['01'], ['1e0'], [' 1'], [0], [-1], [2147483647], [[]]];
    }

    public function test_audit_failure_rolls_back_order_and_code_reservation(): void
    {
        $audit = $this->mock(MaintenanceAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Fallo controlado de auditoría'));
        try {
            $this->order();
            $this->fail('Debió propagarse el fallo de auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo controlado de auditoría', $exception->getMessage());
        }
        $this->assertDatabaseCount('maintenances', 0);
        $this->assertDatabaseCount('maintenance_revisions', 0);
        $this->assertDatabaseCount('maintenance_status_histories', 0);
        $this->assertDatabaseMissing('organization_sequences', ['organization_id' => $this->organization->id, 'name' => 'maintenance']);
    }

    public function test_annulled_tree_rejects_new_orders_and_changes_to_existing_orders(): void
    {
        $order = $this->order();
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->fresh()->lock_version,
            'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
        foreach (['create', 'update'] as $action) {
            try {
                $action === 'create' ? $this->order() : app(MaintenanceService::class)->update($this->admin, $order->id, ['lock_version' => 1, 'notes' => 'Inválido']);
                $this->fail('El árbol anulado no permite operaciones nuevas.');
            } catch (AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertSame(1, $order->fresh()->lock_version);
            }
        }
    }
}

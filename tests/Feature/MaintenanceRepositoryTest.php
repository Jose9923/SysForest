<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\MaintenanceRepository;
use App\Services\MaintenanceService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MaintenanceTestCase;

class MaintenanceRepositoryTest extends MaintenanceTestCase
{
    public function test_database_rejects_tree_from_another_tenant(): void
    {
        $order = $this->order();
        $other = $this->anotherOrganization();
        $attributes = $order->getRawOriginal();
        $attributes['id'] = (string) Str::uuid();
        $attributes['organization_id'] = $other->id;
        $this->rejects(fn () => DB::table('maintenances')->insert($attributes));
        $this->assertDatabaseCount('maintenances', 1);
    }

    public function test_database_rejects_assignee_who_is_not_a_member_of_the_owner_tenant(): void
    {
        $order = $this->order();
        $user = User::factory()->create();
        $this->member($user, $this->anotherOrganization());
        $this->rejects(fn () => DB::table('maintenances')->where('id', $order->id)->update(['assigned_to' => $user->id]));
        $this->assertNull($order->fresh()->assigned_to);
    }

    public function test_database_revalidates_existing_assignee_when_pending_order_becomes_assigned(): void
    {
        $assignee = $this->maintenanceActor('OFFICIAL');
        $order = $this->order(['assigned_to' => $assignee->id]);
        $this->assertSame('pending', $order->status->value);
        $assignee->forceFill(['active' => false])->save();

        $this->rejects(fn () => DB::table('maintenances')->where('id', $order->id)->update(['status' => 'assigned']), '23514');

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame((int) $assignee->id, $order->fresh()->assigned_to);
        $this->assertDatabaseCount('maintenance_status_histories', 1);
    }

    #[DataProvider('invalidStates')]
    public function test_database_constraints_protect_state_and_lifecycle(string $field, mixed $value): void
    {
        $order = $this->order();
        $this->rejects(fn () => DB::table('maintenances')->where('id', $order->id)->update([$field => $value]));
        $this->assertSame(1, $order->fresh()->lock_version);
    }

    public static function invalidStates(): array
    {
        return [['status', 'unknown'], ['priority', 'extreme'], ['lock_version', 0],
            ['status', 'assigned'], ['status', 'in_progress'], ['status', 'executed'], ['status', 'verified'],
            ['deleted_at', '2026-09-01 12:00:00+00']];
    }

    #[DataProvider('immutableFields')]
    public function test_database_preserves_immutable_identity(string $field): void
    {
        $order = $this->order();
        $newValue = match ($field) {
            'id', 'organization_id', 'tree_id' => (string) Str::uuid(),
            'code' => 'MNT-999999',
            'created_by' => User::factory()->create()->id,
            'created_at' => '2025-01-01 00:00:00+00',
        };
        $this->rejects(fn () => DB::table('maintenances')->where('id', $order->id)->update([$field => $newValue]));
        $this->assertSame($order->getRawOriginal($field), $order->fresh()->getRawOriginal($field));
    }

    public static function immutableFields(): array
    {
        return [['id'], ['organization_id'], ['tree_id'], ['code'], ['created_by'], ['created_at']];
    }

    public function test_codes_remain_unique_even_after_soft_delete(): void
    {
        $order = $this->order();
        app(MaintenanceService::class)->void($this->admin, $order->id, ['lock_version' => 1, 'deletion_notes' => 'Error de registro']);
        $attributes = $order->getRawOriginal();
        $attributes['id'] = (string) Str::uuid();
        $this->rejects(fn () => DB::table('maintenances')->insert($attributes));
        $this->assertSame('MNT-000002', $this->order()->code);
    }

    public function test_orders_cannot_be_hard_deleted_and_history_is_append_only(): void
    {
        $order = $this->order();
        $this->rejects(fn () => DB::table('maintenances')->where('id', $order->id)->delete());
        foreach (['maintenance_revisions', 'maintenance_status_histories'] as $table) {
            $this->rejects(fn () => DB::table($table)->where('maintenance_id', $order->id)->delete());
            $this->rejects(fn () => DB::table($table)->where('maintenance_id', $order->id)->update(['changed_at' => now()->subDay()]));
        }
        $this->assertDatabaseCount('maintenance_revisions', 1);
        $this->assertDatabaseCount('maintenance_status_histories', 1);
    }

    public function test_repository_filters_dates_status_priority_and_annulled_records(): void
    {
        $first = $this->order(['priority' => 'high', 'scheduled_date' => '2026-10-05']);
        $second = $this->order(['priority' => 'normal', 'scheduled_date' => '2026-10-20']);
        $third = $this->order(['priority' => 'high', 'scheduled_date' => '2026-10-10']);
        app(MaintenanceService::class)->void($this->admin, $third->id, ['lock_version' => 1, 'deletion_notes' => 'Duplicado de prueba']);
        $repository = app(MaintenanceRepository::class);
        $filtered = $repository->paginate($this->organization->id, ['priority' => 'high', 'status' => 'pending', 'date_from' => '2026-10-01', 'date_to' => '2026-10-15']);
        $this->assertSame(1, $filtered->total());
        $this->assertSame($first->id, $filtered->items()[0]->id);
        $this->assertSame(1, $repository->paginate($this->organization->id, ['trashed' => 'only'])->total());
        $this->assertSame(2, $repository->paginate($this->organization->id, [])->total());
        $this->assertSame(0, $repository->paginate($this->anotherOrganization()->id, [])->total());
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_timeline_is_bounded_and_scoped_to_its_tree(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $this->order();
        }
        $repository = app(MaintenanceRepository::class);
        $this->assertCount(10, $repository->timelineForTree($this->organization->id, $this->tree->id));
        $this->assertCount(0, $repository->timelineForTree($this->anotherOrganization()->id, $this->tree->id));
    }

    private function rejects(Closure $operation, ?string $expectedSqlState = null): void
    {
        try {
            // El savepoint evita que un rechazo esperado invalide la transacción global del fixture.
            DB::transaction($operation);
            $this->fail('PostgreSQL debía rechazar esta operación.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->getCode(), $expectedSqlState === null ? ['23503', '23505', '23514', 'P0001'] : [$expectedSqlState]);
        }
    }
}

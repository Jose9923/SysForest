<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ImportRowValidationService;
use App\Services\ImportRowWriteService;
use App\Services\TreeInspectionService;
use App\Services\TreeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\InspectionTestCase;

class ImportRowWriteTest extends InspectionTestCase
{
    public function test_explicit_codes_reserve_the_maximum_before_automatic_rows_and_preserve_audit(): void
    {
        $prefix = $this->organization->tree_code_prefix.'-';
        $rows = [$this->row(['external_number' => '007']), $this->row(['code' => $prefix.'000050', 'external_number' => '007']),
            $this->row(['code' => $prefix.'000005'])];
        $written = DB::transaction(function () use ($rows): array {
            $writer = app(ImportRowWriteService::class);
            $writer->reserveCodes($this->organization, $rows);
            return array_map(fn (array $row): array => $writer->create($this->admin, $this->inventory, $row), $rows);
        });
        $this->assertSame([$prefix.'000051', $prefix.'000050', $prefix.'000005'], array_map(fn (array $row): string => $row['tree']->code, $written));
        $this->assertSame(51, (int) DB::table('organization_sequences')->where('organization_id', $this->organization->id)->where('name', 'tree')->value('value'));
        foreach ($written as $row) {
            $this->assertSame($this->organization->id, $row['tree']->organization_id);
            $this->assertSame($this->admin->id, $row['tree']->created_by);
            $this->assertFalse($row['tree']->is_public);
            $this->assertSame($row['tree']->id, $row['inspection']->tree_id);
            $this->assertSame($this->inventory->id, $row['inspection']->inventoryTree->inventory_id);
            $this->assertFalse($row['inspection']->is_public);
            $this->assertSame('154.000000', $row['inspection']->cap_cm);
            $this->assertDatabaseHas('tree_status_histories', ['tree_id' => $row['tree']->id, 'from_status' => null, 'to_status' => 'active']);
            $this->assertDatabaseHas('inspection_revisions', ['inspection_id' => $row['inspection']->id]);
        }
        $this->assertDatabaseCount('tree_inspections', 3);
        $this->assertSame(2, DB::table('trees')->where('external_number', '007')->count());
    }

    public function test_a_late_failure_rolls_back_all_rows_histories_and_reserved_sequence(): void
    {
        $before = $this->counts();
        $sequence = DB::table('organization_sequences')->where('organization_id', $this->organization->id)->value('value');
        $rows = [$this->row(['code' => $this->organization->tree_code_prefix.'-000900']), $this->row()];
        $service = app(TreeInspectionService::class);
        $calls = 0;
        $this->mock(TreeInspectionService::class, function ($mock) use ($service, &$calls): void {
            $mock->shouldReceive('create')->twice()->andReturnUsing(function (...$arguments) use ($service, &$calls) {
                if (++$calls === 2) {
                    throw new RuntimeException('Fallo de inspección de prueba.');
                }
                return $service->create(...$arguments);
            });
        });
        try {
            DB::transaction(function () use ($rows): void {
                $writer = app(ImportRowWriteService::class);
                $writer->reserveCodes($this->organization, $rows);
                foreach ($rows as $row) {
                    $writer->create($this->admin, $this->inventory, $row);
                }
            });
            $this->fail('Debía revertirse el lote completo.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de inspección de prueba.', $exception->getMessage());
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame($sequence, DB::table('organization_sequences')->where('organization_id', $this->organization->id)->value('value'));
    }

    public function test_a_code_repeated_in_the_file_is_rejected_before_any_reservation(): void
    {
        $row = $this->row(['code' => $this->organization->tree_code_prefix.'-000500']);
        $before = $this->counts();
        try {
            DB::transaction(fn () => app(ImportRowWriteService::class)->reserveCodes($this->organization, [$row, $row]));
            $this->fail('Se aceptó un duplicado del archivo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_collision_created_after_preview_is_not_an_update_or_replacement(): void
    {
        $code = $this->organization->tree_code_prefix.'-000080';
        $row = $this->row(['code' => $code]);
        $existing = app(TreeService::class)->createImported(
            $this->admin,
            ['current_status' => 'active', 'inventory_ids' => [$this->inventory->id], 'external_number' => 'original'],
            $code
        );
        try {
            DB::transaction(fn () => app(ImportRowWriteService::class)->reserveCodes($this->organization, [$row]));
            $this->fail('La colisión debía detener la importación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertSame('original', $existing->fresh()->external_number);
        $this->assertDatabaseCount('tree_inspections', 0);
    }

    public function test_regular_tree_creation_still_forbids_client_supplied_code(): void
    {
        $this->expectException(ValidationException::class);
        app(TreeService::class)->create($this->admin, ['current_status' => 'active', 'inventory_ids' => [$this->inventory->id],
            'code' => $this->organization->tree_code_prefix.'-000100']);
    }

    public function test_rows_with_errors_cannot_be_persisted_by_direct_service_call(): void
    {
        $row = $this->row(['cap_cm' => '0']);
        $before = $this->counts();
        try {
            DB::transaction(fn () => app(ImportRowWriteService::class)->create($this->admin, $this->inventory, $row));
            $this->fail('Una fila inválida no debe guardarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cap_cm', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_imported_code_does_not_bypass_tree_authorization_or_tenant_scope(): void
    {
        $row = $this->row();
        $other = $this->anotherOrganization();
        $this->member($this->admin, $other);
        $this->activateInspectionTenant($this->admin, $other);
        $before = $this->counts();
        try {
            DB::transaction(fn () => app(ImportRowWriteService::class)->create($this->admin, $this->inventory, $row));
            $this->fail('El inventario ajeno debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_ids', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_requested_maximum_must_leave_room_for_every_automatic_code(): void
    {
        $rows = [$this->row(['code' => $this->organization->tree_code_prefix.'-'.(PHP_INT_MAX - 1)]), $this->row(), $this->row()];
        $before = $this->counts();
        try {
            DB::transaction(fn () => app(ImportRowWriteService::class)->reserveCodes($this->organization, $rows));
            $this->fail('La reserva excede la capacidad BIGINT.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_recalculation_at_creation_does_not_trust_preview_derived_values(): void
    {
        $row = $this->row();
        $row['calculations']['dap_m'] = '999';
        $row['inspection']['dap_m'] = '888';
        $written = DB::transaction(function () use ($row): array {
            $writer = app(ImportRowWriteService::class);
            $writer->reserveCodes($this->organization, [$row]);
            return $writer->create($this->admin, $this->inventory, $row);
        });
        $this->assertNotSame('999.000000000000', $written['inspection']->dap_m);
        $this->assertNotSame('888.000000000000', $written['inspection']->dap_m);
        $this->assertSame($row['calculations']['classification_rule_version_id'], $written['inspection']->classification_rule_version_id);
        $this->assertSame('154.000000', $written['inspection']->cap_cm);
    }

    private function row(array $extra = []): array
    {
        return app(ImportRowValidationService::class)->validate(
            $this->organization,
            $this->inventory,
            array_replace(['current_status' => 'active', 'inspection_date' => '2026-09-28', 'cap_cm' => '154'], $extra)
        );
    }

    private function counts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['trees', 'tree_inspections',
            'inventory_trees', 'tree_status_histories', 'tree_location_histories', 'inspection_revisions', 'security_events']);
    }
}

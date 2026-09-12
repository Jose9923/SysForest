<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Services\TreeInspectionReadService;
use App\Services\TreeInspectionService;
use App\Services\TreeLifecycleService;
use App\Services\TreeService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\InspectionTestCase;

class InspectionAuthorizationTest extends InspectionTestCase
{
    public function test_department_admin_reads_own_department_without_artificial_membership(): void
    {
        $inspection = $this->capture();
        $actor = $this->departmentActor();
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $reader = app(TreeInspectionReadService::class);
        $page = $reader->index($actor);
        $detail = $reader->show($actor, $inspection->id);
        $summary = $reader->treeSummary($actor, $this->tree);

        $this->assertSame([$inspection->id], $page['inspections']->getCollection()->modelKeys());
        $this->assertFalse($page['canCreateInspection']);
        $this->assertFalse($detail['canUpdate']);
        $this->assertSame($inspection->id, $summary['latestInspection']->id);
        $this->assertFalse($summary['canCreateInspection']);
        $this->assertSame('department_read', app(TenantContext::class)->accessMode());
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
        $this->actingTenant($actor, $this->organization)
            ->get(route('inspections.show', $inspection->id))->assertOk()
            ->assertViewHas('canUpdate', false);
    }

    public function test_department_admin_cannot_read_or_select_another_department(): void
    {
        $other = $this->organizationInAnotherDepartment();
        $this->member($this->admin, $other);
        $this->allowManagement($this->admin);
        app(TenantContextService::class)->activate($this->admin, $other->id);
        $foreignInventory = app(InventoryService::class)->create($this->admin, [
            'code' => 'INSPECTION-FOREIGN', 'name' => 'Campaña de otro departamento', 'status' => 'active', 'active' => true,
        ]);
        $foreignTree = app(TreeService::class)->create($this->admin, ['inventory_ids' => [$foreignInventory->id], 'current_status' => 'active']);
        $foreign = $this->capture(['general_observations' => 'Contenido reservado de otro departamento'], $this->admin, $foreignTree);
        $actor = $this->departmentActor();

        $this->actingTenant($actor, $this->organization)
            ->get(route('inspections.show', $foreign->id))->assertNotFound();
        $this->actingTenant($actor, $other)
            ->get(route('inspections.show', $foreign->id))->assertForbidden()
            ->assertDontSee('Contenido reservado de otro departamento');
        $this->assertFalse(app(TenantContext::class)->isActive());

        try {
            app(TenantContextService::class)->activate($actor, $other->id);
            $this->fail('Se autorizó un departamento ajeno.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }
    }

    #[DataProvider('writes')]
    public function test_superadmin_cannot_write_without_an_explicit_tenant(string $operation): void
    {
        $inspection = $this->capture();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        app(TenantContext::class)->clear();

        try {
            if ($operation === 'create') {
                app(TreeInspectionService::class)->create($actor, $this->tree->id, $this->inspectionInput());
            } else {
                app(TreeInspectionService::class)->update($actor, $inspection->id, ['lock_version' => 1, 'cap_cm' => '200']);
            }
            $this->fail('Una concesión nacional no sustituye la selección institucional.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(TenantContext::class)->isActive());
        }

        $this->actingAs($actor);
        $response = $operation === 'create'
            ? $this->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput())
            : $this->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, 'cap_cm' => '200']);
        $response->assertForbidden();
        $this->assertSame(1, $inspection->fresh()->lock_version);
        $this->assertDatabaseCount('tree_inspections', 1);
        $this->assertDatabaseCount('inspection_revisions', 1);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
    }

    #[DataProvider('annulledWrites')]
    public function test_annulled_tree_rejects_new_inspections_and_corrections(string $operation): void
    {
        $inspection = $this->capture();
        $this->annulTree();
        $this->actingTenant($this->admin, $this->organization);

        $response = match ($operation) {
            'create_form' => $this->get(route('trees.inspections.create', $this->tree->id)),
            'create' => $this->postJson(route('trees.inspections.store', $this->tree->id), $this->inspectionInput()),
            'edit' => $this->get(route('inspections.edit', $inspection->id)),
            'update' => $this->putJson(route('inspections.update', $inspection->id), ['lock_version' => 1, 'cap_cm' => '200']),
        };
        $response->assertStatus(in_array($operation, ['create_form', 'create'], true) ? 404 : 403);
        $this->assertSame(1, $inspection->fresh()->lock_version);
        $this->assertDatabaseCount('tree_inspections', 1);
        $this->assertDatabaseCount('inspection_revisions', 1);
    }

    public function test_authorized_administrator_reads_annulled_history_but_official_cannot(): void
    {
        $inspection = $this->capture(['general_observations' => 'Historia previa a la anulación']);
        $this->annulTree();
        $view = app(TreeInspectionReadService::class)->show($this->admin, $inspection->id);
        $this->assertTrue($view['tree']->trashed());
        $this->assertFalse($view['canUpdate']);
        $this->assertCount(1, $view['revisions']);
        $this->assertSame(0, app(TreeInspectionReadService::class)->index($this->admin)['inspections']->total());

        $this->actingTenant($this->admin, $this->organization)
            ->get(route('inspections.show', $inspection->id))->assertOk()
            ->assertSee('Historia previa a la anulación')->assertViewHas('canUpdate', false);
        $official = User::factory()->create();
        $this->member($official, $this->organization);
        $this->actingTenant($official, $this->organization)
            ->get(route('inspections.show', $inspection->id))->assertForbidden()
            ->assertDontSee('Historia previa a la anulación');
    }

    #[DataProvider('writes')]
    public function test_service_rejects_an_inventory_participation_for_another_tree_in_same_tenant(string $operation): void
    {
        $inspection = $operation === 'update' ? $this->capture() : null;
        $otherTree = app(TreeService::class)->create($this->admin, ['inventory_ids' => [$this->inventory->id], 'current_status' => 'active']);
        $participation = app(InventoryRepository::class)->attach(
            $this->organization->id,
            $this->inventory->id,
            $otherTree->id,
            (int) $this->admin->id
        );
        $beforeEvents = DB::table('security_events')->count();

        try {
            if ($operation === 'create') {
                $this->capture(['inventory_tree_id' => $participation->id]);
            } else {
                app(TreeInspectionService::class)->update($this->admin, $inspection->id, [
                    'lock_version' => 1, 'inventory_tree_id' => $participation->id, 'cap_cm' => '200',
                ]);
            }
            $this->fail('Se aceptó la participación de un individuo diferente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_tree_id', $exception->errors());
        }

        $this->assertDatabaseCount('tree_inspections', $inspection === null ? 0 : 1);
        $this->assertDatabaseCount('inspection_revisions', $inspection === null ? 0 : 1);
        $this->assertSame($beforeEvents, DB::table('security_events')->count());
        if ($inspection !== null) {
            $this->assertNull($inspection->fresh()->inventory_tree_id);
            $this->assertSame('154.000000', $inspection->fresh()->cap_cm);
            $this->assertSame(1, $inspection->fresh()->lock_version);
        }
    }

    public static function writes(): array
    {
        return [['create'], ['update']];
    }

    public static function annulledWrites(): array
    {
        return [['create_form'], ['create'], ['edit'], ['update']];
    }

    private function departmentActor(): User
    {
        $actor = User::factory()->create();
        app(RoleAssignmentRepository::class)->grantDepartment(
            (int) $actor->id,
            $this->organization->municipality->department_id
        );

        return $actor;
    }

    private function organizationInAnotherDepartment(): Organization
    {
        $department = Department::query()->create([
            'country_id' => $this->organization->municipality->department->country_id,
            'code' => 'INSPECT-ALT', 'name' => 'Departamento ajeno', 'active' => true,
        ]);
        $municipality = Municipality::query()->create([
            'department_id' => $department->id, 'code' => 'INSPECT-ALT',
            'name' => 'Municipio ajeno', 'active' => true,
        ]);

        return app(OrganizationRepository::class)->create([
            'municipality_id' => $municipality->id, 'type' => 'other', 'name' => 'Organización de otro departamento',
            'code' => 'INSPECTION-FOREIGN', 'tree_code_prefix' => 'INSPECTION-FOREIGN', 'active' => true,
        ]);
    }

    private function annulTree(): void
    {
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->fresh()->lock_version,
            'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
            'deletion_notes' => 'Anulación de fixture para verificar acceso a la historia.',
        ]);
    }
}

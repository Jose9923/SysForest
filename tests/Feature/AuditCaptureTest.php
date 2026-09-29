<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DeletionReason;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Repositories\SecurityEventRepository;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\OrganizationMembershipService;
use App\Services\OrganizationService;
use App\Services\RoleAssignmentService;
use App\Services\TreeInspectionService;
use App\Services\TreeLifecycleService;
use App\Services\TreeService;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\InspectionTestCase;

/** Comprueba escritura real, identidad de la revisión y rollback exclusivamente en sysforest_testing. */
class AuditCaptureTest extends InspectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_collaborative_tree_change_records_old_new_values_without_reassigning_author(): void
    {
        $editor = User::factory()->create();
        $this->member($editor, $this->organization);
        $this->activateInspectionTenant($editor);
        app(TreeService::class)->update($editor, $this->tree->id, ['external_number' => '000042', 'lock_version' => 1]);
        $event = $this->event('tree.updated', $this->tree->id);
        $this->assertSame($editor->id, $event->actor_user_id);
        $this->assertSame($this->organization->id, $event->organization_id);
        $this->assertSame('tree', $event->resource_type);
        $this->assertNull($event->before_values['external_number']);
        $this->assertSame('000042', $event->after_values['external_number']);
        $this->assertSame(1, $event->before_values['lock_version']);
        $this->assertSame(2, $event->after_values['lock_version']);
        $this->assertSame($this->admin->id, $event->before_values['created_by']);
        $this->assertSame($this->admin->id, $event->after_values['created_by']);
        $this->assertSame($editor->id, $event->after_values['updated_by']);
        $this->assertTrue(Str::isUuid($event->context['request_id']));
        $this->assertSame(['request_id'], array_keys($event->context));
    }

    public function test_void_and_restore_keep_both_states_and_original_author(): void
    {
        $reason = DeletionReason::query()->where('active', true)->firstOrFail();
        app(TreeLifecycleService::class)->void(
            $this->admin,
            $this->tree->id,
            ['lock_version' => 1, 'deletion_reason_id' => $reason->id, 'deletion_notes' => 'Duplicado confirmado.']
        );
        $void = $this->event('tree.voided', $this->tree->id);
        $this->assertNull($void->before_values['deleted_at']);
        $this->assertNotNull($void->after_values['deleted_at']);
        $this->assertSame($reason->id, $void->after_values['deletion_reason_id']);
        app(TreeLifecycleService::class)->restore($this->admin, $this->tree->id, ['lock_version' => 2]);
        $restore = $this->event('tree.restored', $this->tree->id);
        $this->assertNotNull($restore->before_values['deleted_at']);
        $this->assertNull($restore->after_values['deleted_at']);
        $this->assertSame($this->admin->id, $restore->after_values['created_by']);
    }

    public function test_status_and_location_events_link_the_exact_append_only_history(): void
    {
        app(TreeLifecycleService::class)->status(
            $this->admin,
            $this->tree->id,
            ['lock_version' => 1, 'current_status' => 'dead', 'reason' => 'Visita de verificación.']
        );
        $event = SecurityEvent::query()->where('event', 'tree.status_changed')->where('resource_id', $this->tree->id)
            ->where('after_values->current_status', 'dead')->sole();
        $this->assertSame('active', $event->before_values['current_status']);
        $this->assertDatabaseHas('tree_status_histories', ['id' => $event->metadata['revision_id'],
            'tree_id' => $this->tree->id, 'from_status' => 'active', 'to_status' => 'dead']);
        app(TreeLifecycleService::class)->location($this->admin, $this->tree->id, [
            'lock_version' => 2, 'coordinate_raw' => '4.154, -73.633',
            'location_change_type' => 'correction', 'location_reason' => 'Primera coordenada verificada.',
        ]);
        $event = $this->event('tree.location_changed', $this->tree->id);
        $this->assertNull($event->before_values['latitude']);
        $this->assertEquals(4.154, $event->after_values['latitude']);
        $this->assertEquals(-73.633, $event->after_values['longitude']);
        $this->assertDatabaseHas('tree_location_histories', ['id' => $event->metadata['revision_id'], 'tree_id' => $this->tree->id]);
    }

    public function test_inventory_and_inclusion_capture_metadata_without_mutating_participation(): void
    {
        app(InventoryService::class)->update($this->admin, $this->inventory->id, [
            'code' => $this->inventory->code, 'name' => 'Campaña corregida', 'status' => 'active', 'active' => true, 'lock_version' => 1,
        ]);
        $event = $this->event('inventory.updated', $this->inventory->id);
        $this->assertSame($this->inventory->name, $event->before_values['name']);
        $this->assertSame('Campaña corregida', $event->after_values['name']);
        $inclusion = SecurityEvent::query()->where('event', 'inventory.tree_included')->sole();
        $this->assertSame('inventory_tree', $inclusion->resource_type);
        $this->assertSame([], $inclusion->before_values);
        $this->assertSame($this->admin->id, $inclusion->after_values['included_by']);
        $this->assertSame($this->inventory->id, $inclusion->after_values['inventory_id']);
        app(TreeService::class)->include(
            $this->admin,
            $this->tree->id,
            ['lock_version' => 1, 'inventory_ids' => [$this->inventory->id]]
        );
        $this->assertSame(1, SecurityEvent::query()->where('event', 'inventory.tree_included')->count());
    }

    public function test_inspection_event_carries_measurements_and_exact_revision_identifier(): void
    {
        $inspection = $this->capture();
        app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'cap_cm' => '200']);
        $event = $this->event('inspection.updated', $inspection->id);
        $this->assertSame('tree_inspection', $event->resource_type);
        $this->assertSame('154.000000', $event->before_values['measurements']['cap_cm']);
        $this->assertSame('200.000000', $event->after_values['measurements']['cap_cm']);
        $this->assertDatabaseHas('inspection_revisions', ['id' => $event->metadata['revision_id'], 'inspection_id' => $inspection->id, 'version' => 2]);
        $this->assertNotSame($event->before_values['calculations']['dap_m'], $event->after_values['calculations']['dap_m']);
    }

    public function test_catalog_has_national_scope_and_captures_version_before_it_advances(): void
    {
        $this->allowNationalManagement($this->admin);
        $input = ['code' => 'AUDIT_TEST', 'name' => 'Nombre inicial', 'description' => 'Descripción', 'active' => true, 'sort_order' => 9];
        $catalog = app(CatalogService::class)->create($this->admin, 'placements', $input);
        app(CatalogService::class)->update(
            $this->admin,
            'placements',
            $catalog->id,
            array_replace($input, ['name' => 'Nombre corregido', 'lock_version' => 1])
        );
        $event = $this->event('catalog.updated', $catalog->id);
        $this->assertNull($event->organization_id);
        $this->assertSame('catalog', $event->resource_type);
        $this->assertSame('placements', $event->metadata['catalog_key']);
        $this->assertSame('Nombre inicial', $event->before_values['name']);
        $this->assertSame('Nombre corregido', $event->after_values['name']);
        $this->assertSame(1, $event->before_values['lock_version']);
        $this->assertSame(2, $event->after_values['lock_version']);
    }

    public function test_organization_creation_and_update_are_audited_without_contact_or_settings(): void
    {
        $this->allowNationalManagement($this->admin);
        $input = $this->organizationInput($this->organization);
        $organization = app(OrganizationService::class)->create($this->admin, $input);
        $created = $this->event('organization.created', $organization->id);
        $this->assertSame([], $created->before_values);
        $this->assertArrayNotHasKey('email', $created->after_values);
        $this->assertArrayNotHasKey('phone', $created->after_values);
        $this->assertArrayNotHasKey('settings', $created->after_values);
        app(OrganizationService::class)->administrativeUpdate(
            $this->admin,
            $organization->id,
            array_replace($input, ['name' => 'Institución corregida', 'lock_version' => 1])
        );
        $event = $this->event('organization.updated', $organization->id);
        $this->assertSame($organization->name, $event->before_values['name']);
        $this->assertSame('Institución corregida', $event->after_values['name']);
        $this->assertSame(1, $event->before_values['lock_version']);
        $this->assertSame(2, $event->after_values['lock_version']);
    }

    public function test_profile_and_direct_role_service_are_audited_without_email_or_password(): void
    {
        $name = $this->admin->name;
        app(UserService::class)->updateProfile($this->admin, ['name' => 'Nombre auditado']);
        $profile = $this->event('account.profile_updated', (string) $this->admin->id);
        $this->assertSame($name, $profile->before_values['name']);
        $this->assertSame('Nombre auditado', $profile->after_values['name']);
        foreach (['email', 'password', 'remember_token'] as $secret) {
            $this->assertArrayNotHasKey($secret, $profile->before_values);
            $this->assertArrayNotHasKey($secret, $profile->after_values);
        }
        $this->allowNationalManagement($this->admin);
        $target = User::factory()->create();
        $assignment = app(RoleAssignmentService::class)->assignNational($this->admin, $target);
        $event = $this->event('role.assigned', $assignment->id);
        $this->assertSame('national_role_assignment', $event->resource_type);
        $this->assertSame([], $event->before_values);
        $this->assertSame($target->id, $event->after_values['user_id']);
        $this->assertSame('SUPER_ADMIN', $event->metadata['role_name']);
        $this->assertNull($event->organization_id);
    }

    public function test_membership_role_change_keeps_old_and_new_role(): void
    {
        $target = User::factory()->create();
        $membership = app(OrganizationMembershipService::class)->add($this->admin, $target->id);
        app(OrganizationMembershipService::class)->edit($this->admin, $membership->id, ['role' => 'ORGANIZATION_ADMIN']);
        $event = $this->event('membership.role_changed', $membership->id);
        $this->assertSame('OFFICIAL', $event->before_values['role_name']);
        $this->assertSame('ORGANIZATION_ADMIN', $event->after_values['role_name']);
        $this->assertSame($this->organization->id, $event->organization_id);
        $this->assertSame($target->id, $event->after_values['user_id']);
    }

    public function test_audit_failure_rolls_back_tree_data_version_and_authorship(): void
    {
        $count = SecurityEvent::query()->count();
        $this->partialMock(SecurityEventRepository::class)->shouldReceive('recordChange')->once()->andThrow(new RuntimeException('Auditoría no disponible.'));
        try {
            app(TreeService::class)->update($this->admin, $this->tree->id, ['lock_version' => 1, 'external_number' => 'NO-GUARDAR']);
            $this->fail('La escritura debió revertirse al fallar su auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Auditoría no disponible.', $exception->getMessage());
        }
        $tree = $this->tree->fresh();
        $this->assertNull($tree->external_number);
        $this->assertSame(1, $tree->lock_version);
        $this->assertSame($this->admin->id, $tree->created_by);
        $this->assertSame($count, SecurityEvent::query()->count());
    }

    #[DataProvider('immutableTargets')]
    public function test_database_refuses_mutating_or_deleting_audit_and_participation(string $table, string $operation): void
    {
        $id = DB::table($table)->value('id');
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $query = DB::table($table)->where('id', $id);
        $operation === 'delete' ? $query->delete() : $query->update(['id' => $id]);
    }

    public static function immutableTargets(): array
    {
        return [['security_events', 'update'], ['security_events', 'delete'], ['inventory_trees', 'update'],
            ['inventory_trees', 'delete'], ['inventories', 'delete']];
    }

    public function test_deleting_an_event_actor_is_restricted_instead_of_erasing_the_reference(): void
    {
        $user = User::factory()->create();
        app(SecurityEventRepository::class)->record('audit.fixture', $user);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        DB::table('users')->where('id', $user->id)->delete();
    }

    public function test_deleting_an_event_organization_is_restricted_instead_of_erasing_the_reference(): void
    {
        $organization = $this->anotherOrganization();
        app(SecurityEventRepository::class)->record('audit.fixture', $this->admin, $organization->id);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        DB::table('organizations')->where('id', $organization->id)->delete();
    }

    public function test_administrative_session_revocation_names_the_real_actor_and_target(): void
    {
        $this->allowNationalManagement($this->admin);
        $target = User::factory()->create();
        $pair = app(\App\Services\AuthService::class)->login($target->email, 'password');
        app(UserService::class)->setActive($this->admin, $target->id, false);
        $event = SecurityEvent::query()->where('event', 'auth.session.revoked')
            ->where('metadata->session_id', $pair->session->id)->sole();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame($target->id, $event->metadata['target_user_id']);
        $this->assertSame('account_disabled', $event->metadata['reason']);
        $this->assertNotNull($pair->session->fresh()->revoked_at);
    }

    public function test_replacing_an_invitation_audits_revocation_without_tokens_or_email(): void
    {
        $service = app(\App\Services\InvitationService::class);
        $input = ['email' => 'invitado-auditoria@example.test', 'role' => 'OFFICIAL'];
        $previous = $service->create($this->admin, $input);
        $replacement = $service->create($this->admin, $input);
        $event = $this->event('invitation.revoked', $previous->id);
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame($this->organization->id, $event->organization_id);
        $this->assertNull($event->before_values['revoked_at']);
        $this->assertNotNull($event->after_values['revoked_at']);
        $this->assertSame('superseded', $event->metadata['reason']);
        $this->assertNull($replacement->revoked_at);
        $this->assertStringNotContainsString($input['email'], $event->toJson());
        $this->assertStringNotContainsString($previous->token_hash, $event->toJson());
        $this->assertArrayNotHasKey('token_hash', $event->after_values);
    }

    public function test_invitation_replacement_rolls_back_if_revocation_audit_fails(): void
    {
        $input = ['email' => 'invitado-rollback@example.test', 'role' => 'OFFICIAL'];
        $previous = app(\App\Services\InvitationService::class)->create($this->admin, $input);
        $this->partialMock(SecurityEventRepository::class)->shouldReceive('recordChange')->once()
            ->withArgs(fn ($event): bool => $event === 'invitation.revoked')
            ->andThrow(new RuntimeException('Fallo auditado de revocación.'));
        try {
            app(\App\Services\InvitationService::class)->create($this->admin, $input);
            $this->fail('La sustitución debía revertirse.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo auditado de revocación.', $exception->getMessage());
        }
        $this->assertNull($previous->fresh()->revoked_at);
        $this->assertSame(1, \App\Models\Invitation::query()->where('email', $input['email'])->count());
    }

    public function test_complete_status_and_location_reasons_survive_unified_audit_projection(): void
    {
        // El formulario admite 2000 caracteres; se usan caracteres multibyte para detectar recortes por bytes.
        $reason = str_repeat('áñ', 1000);
        app(TreeLifecycleService::class)->status(
            $this->admin,
            $this->tree->id,
            ['lock_version' => 1, 'current_status' => 'dead', 'reason' => $reason]
        );
        $status = SecurityEvent::query()->where('event', 'tree.status_changed')->where('resource_id', $this->tree->id)
            ->where('after_values->current_status', 'dead')->sole();
        $this->assertSame($reason, $status->metadata['reason']);
        $statusDetail = app(\App\Services\AuditReadService::class)->show($this->admin, 'events', $status->id, []);
        $this->assertSame($reason, $statusDetail['entry']['metadata']['Motivo']);

        app(TreeLifecycleService::class)->location($this->admin, $this->tree->id, [
            'lock_version' => 2, 'coordinate_raw' => '4.154, -73.633',
            'location_change_type' => 'relocation', 'location_reason' => $reason,
        ]);
        $location = $this->event('tree.location_changed', $this->tree->id);
        $this->assertSame($reason, $location->metadata['reason']);
        $this->assertSame('relocation', $location->metadata['change_type']);
        $detail = app(\App\Services\AuditReadService::class)->show($this->admin, 'events', $location->id, []);
        $this->assertSame($reason, $detail['entry']['metadata']['Motivo']);
        $this->assertSame('Traslado', $detail['entry']['metadata']['Tipo de cambio de ubicación']);
        $this->assertDatabaseHas('tree_location_histories', ['id' => $location->metadata['revision_id'],
            'reason' => $reason, 'change_type' => 'relocation']);
    }

    public function test_reason_has_its_own_bound_without_widening_other_metadata_values(): void
    {
        $reason = str_repeat('á', 5000);
        $event = app(SecurityEventRepository::class)->record('audit.fixture', $this->admin, $this->organization->id, [
            'reason' => $reason, 'resource_code' => str_repeat('Z', 151), 'change_type' => 'untrusted-type',
        ]);
        $this->assertSame($reason, $event->metadata['reason']);
        $this->assertSame(str_repeat('Z', 150), $event->metadata['resource_code']);
        $this->assertArrayNotHasKey('change_type', $event->metadata);
        $bounded = app(SecurityEventRepository::class)->record(
            'audit.fixture',
            $this->admin,
            $this->organization->id,
            ['reason' => $reason.'EXCESO']
        );
        $this->assertSame($reason, $bounded->metadata['reason']);
    }

    public function test_legacy_location_detail_preserves_change_type_and_complete_reason(): void
    {
        $id = (string) Str::uuid();
        $reason = str_repeat('Motivo histórico. ', 200);
        DB::table('tree_location_histories')->insert([
            'id' => $id, 'organization_id' => $this->organization->id, 'tree_id' => $this->tree->id,
            'old_coordinate_raw' => '4.153, -73.634', 'new_coordinate_raw' => '4.154, -73.633',
            'old_metadata' => '{}', 'new_metadata' => '{}', 'change_type' => 'transplant',
            'reason' => $reason, 'changed_by' => $this->admin->id, 'changed_at' => now(),
            'old_geometry' => DB::raw('ST_SetSRID(ST_MakePoint(-73.634, 4.153), 4326)'),
            'new_geometry' => DB::raw('ST_SetSRID(ST_MakePoint(-73.633, 4.154), 4326)'),
        ]);
        $detail = app(\App\Services\AuditReadService::class)->show($this->admin, 'locations', $id, []);
        $this->assertSame($reason, $detail['entry']['metadata']['Motivo']);
        $this->assertSame('Trasplante', $detail['entry']['metadata']['Tipo de cambio de ubicación']);
    }

    private function event(string $event, string $resourceId): SecurityEvent
    {
        return SecurityEvent::query()->where('event', $event)->where('resource_id', $resourceId)->sole();
    }
}

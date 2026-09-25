<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StaleRecordException;
use App\Forestry\InventoryInput;
use App\Models\PublicationRevision;
use App\Repositories\SecurityEventRepository;
use App\Services\EvidenceService;
use App\Services\PublicationAccessService;
use App\Services\PublicationService;
use App\Services\TreeLifecycleService;
use App\Tenancy\TenantContext;
use App\Validation\EvidenceInput;
use App\Validation\OrganizationRules;
use App\Validation\TreeInput;
use App\Validation\TreeInspectionInput;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\MaintenanceTestCase;

class PublicationTest extends MaintenanceTestCase
{
    public function test_all_five_domains_are_private_by_default_and_have_independent_publication_versions(): void
    {
        foreach ($this->subjects() as $record) {
            $this->assertFalse($record->is_public);
            $this->assertSame(1, $record->publication_version);
        }
        $this->assertNull($this->organization->fresh()->public_code);
        $this->assertDatabaseCount('publication_revisions', 0);
    }

    #[DataProvider('subjectKinds')]
    public function test_admin_can_publish_and_hide_each_kind_with_complete_transactional_history(string $kind): void
    {
        $record = $this->subject($kind);
        $originalLock = $record->lock_version;
        $published = $this->change($kind, $record, true);
        $this->assertTrue($published->is_public);
        $this->assertSame(2, $published->publication_version);
        if ($kind !== 'organization') {
            $this->assertSame($originalLock + 1, $published->lock_version);
        }
        $hidden = $this->change($kind, $published, false);
        $this->assertFalse($hidden->is_public);
        $this->assertSame(3, $hidden->publication_version);
        $history = PublicationRevision::query()->orderBy('version')->get();
        $this->assertCount(2, $history);
        $this->assertFalse($history[0]->old_values['is_public']);
        $this->assertTrue($history[0]->new_values['is_public']);
        $this->assertSame($this->organization->id, $history[0]->organization_id);
        $this->assertSame((int) $this->admin->id, $history[0]->changed_by);
        $this->assertDatabaseHas('security_events', ['event' => 'publication.changed',
            'organization_id' => $this->organization->id, 'actor_user_id' => $this->admin->id]);
    }

    public static function subjectKinds(): array
    {
        return array_map(fn ($kind) => [$kind], ['organization', 'inventory', 'tree', 'inspection', 'evidence']);
    }

    #[DataProvider('childKinds')]
    public function test_known_foreign_uuid_is_resolved_only_inside_current_organization(string $kind): void
    {
        $record = $this->subject($kind);
        $other = $this->anotherOrganization($this->organization);
        $this->member($this->admin, $other);
        $this->allowManagement($this->admin);
        $this->activateInspectionTenant($this->admin, $other);
        $this->expectException(ModelNotFoundException::class);
        $this->change($kind, $record, true);
    }

    public static function childKinds(): array
    {
        return array_map(fn ($kind) => [$kind], ['inventory', 'tree', 'inspection', 'evidence']);
    }

    #[DataProvider('subjectKinds')]
    public function test_stale_publication_does_not_overwrite_first_writer(string $kind): void
    {
        $record = $this->subject($kind);
        $this->change($kind, $record, true);
        try {
            $this->change($kind, $record, false);
            $this->fail('La versión antigua debía rechazarse.');
        } catch (StaleRecordException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertTrue($record->fresh()->is_public);
        $this->assertDatabaseCount('publication_revisions', 1);
    }

    #[DataProvider('forbiddenRoles')]
    public function test_official_and_department_roles_cannot_publish(string $role): void
    {
        $actor = $this->maintenanceActor($role);
        $this->activateInspectionTenant($actor);
        $this->assertFalse(app(PublicationAccessService::class)->canManage($actor));
        $this->expectException(AuthorizationException::class);
        app(PublicationService::class)->tree($actor, $this->tree->id, ['is_public' => true, 'publication_version' => 1]);
    }

    public static function forbiddenRoles(): array
    {
        return [['OFFICIAL'], ['DEPARTMENT_ADMIN']];
    }

    public function test_super_admin_requires_explicit_current_organization(): void
    {
        $actor = $this->maintenanceActor('SUPER_ADMIN');
        app(TenantContext::class)->clear();
        $this->assertFalse(app(PublicationAccessService::class)->canManage($actor));
        try {
            app(PublicationService::class)->organization($actor, ['is_public' => true, 'publication_version' => 1]);
            $this->fail('Faltaba seleccionar organización.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('publication_revisions', 0);
        }
        $this->activateInspectionTenant($actor);
        $record = app(PublicationService::class)->organization($actor, ['is_public' => true, 'publication_version' => 1]);
        $this->assertTrue($record->is_public);
    }

    public function test_membership_revocation_is_checked_after_context_was_activated(): void
    {
        DB::table('organization_memberships')->where('user_id', $this->admin->id)
            ->where('organization_id', $this->organization->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        $this->change('tree', $this->tree->fresh(), true);
    }

    public function test_account_deactivation_is_checked_after_context_was_activated(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        $this->change('tree', $this->tree->fresh(), true);
    }

    public function test_permission_revocation_is_not_cached(): void
    {
        $role = DB::table('roles')->where('name', 'ORGANIZATION_ADMIN')->value('id');
        $permission = DB::table('permissions')->where('code', 'publication.manage')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->delete();
        $this->expectException(AuthorizationException::class);
        $this->change('tree', $this->tree->fresh(), true);
    }

    public function test_public_identifiers_survive_hiding_and_administrative_code_changes(): void
    {
        $organization = $this->change('organization', $this->organization->fresh(), true);
        $reserved = $organization->public_code;
        $this->change('organization', $organization, false);
        DB::table('organizations')->where('id', $organization->id)->update(['code' => 'RENAMED-'.strtoupper(Str::random(8))]);
        $again = $this->change('organization', $organization->fresh(), true);
        $this->assertSame($reserved, $again->public_code);
        $evidence = $this->change('evidence', $this->attachEvidence(), true);
        $publicId = $evidence->public_id;
        $this->assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId);
        $hidden = $this->change('evidence', $evidence, false);
        $this->assertSame($publicId, $this->change('evidence', $hidden, true)->public_id);
    }

    public function test_private_caption_is_never_copied_and_public_caption_audit_is_not_truncated(): void
    {
        $evidence = $this->attachEvidence(['caption' => 'CONTACTO INTERNO confidencial']);
        $record = $this->change('evidence', $evidence, true);
        $this->assertNull($record->public_caption);
        $caption = str_repeat('Texto autorizado para consulta. ', 40);
        $updated = app(PublicationService::class)->evidence($this->admin, $record->id, [
            'is_public' => true, 'publication_version' => 2, 'public_caption' => $caption]);
        $revision = PublicationRevision::query()->where('evidence_id', $record->id)->where('version', 3)->sole();
        $this->assertSame($caption, $revision->new_values['public_caption']);
        $this->assertNull($revision->old_values['public_caption']);
        $this->assertSame('CONTACTO INTERNO confidencial', $updated->caption);
    }

    public function test_unchanged_submission_does_not_create_duplicate_revision(): void
    {
        $record = $this->change('tree', $this->tree->fresh(), true);
        $again = $this->change('tree', $record, true);
        $this->assertSame(2, $again->publication_version);
        $this->assertDatabaseCount('publication_revisions', 1);
    }

    public function test_audit_failure_rolls_back_publication_identity_version_and_revision(): void
    {
        $this->mock(SecurityEventRepository::class)->shouldReceive('record')
            ->once()->andThrow(new RuntimeException('Fallo de auditoría de prueba.'));
        try {
            $this->change('organization', $this->organization->fresh(), true);
            $this->fail('La auditoría debía fallar.');
        } catch (RuntimeException) {
            $record = $this->organization->fresh();
            $this->assertFalse($record->is_public);
            $this->assertNull($record->public_code);
            $this->assertSame(1, $record->publication_version);
            $this->assertDatabaseCount('publication_revisions', 0);
        }
    }

    #[DataProvider('notPublishableInventories')]
    public function test_unavailable_or_draft_campaign_cannot_be_authorized(bool $active, string $status): void
    {
        DB::table('inventories')->where('id', $this->inventory->id)->update(['active' => $active, 'status' => $status]);
        $this->expectException(ValidationException::class);
        $this->change('inventory', $this->inventory->fresh(), true);
    }

    public static function notPublishableInventories(): array
    {
        return [[true, 'draft'], [false, 'active']];
    }

    public function test_closed_inventory_can_publish_historical_records(): void
    {
        DB::table('inventories')->where('id', $this->inventory->id)->update(['status' => 'closed']);
        $this->assertTrue($this->change('inventory', $this->inventory->fresh(), true)->is_public);
    }

    public function test_annulled_tree_and_its_inspection_and_photograph_cannot_be_newly_published(): void
    {
        $inspection = $this->capture();
        $evidence = $this->attachEvidence();
        app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
            'lock_version' => $this->tree->fresh()->lock_version,
            'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
        ]);
        foreach (['tree' => $this->tree->fresh(), 'inspection' => $inspection, 'evidence' => $evidence] as $kind => $record) {
            try {
                $this->change($kind, $record, true);
                $this->fail('No debe publicarse una raíz anulada.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('is_public', $exception->errors());
            }
        }
        $this->assertDatabaseCount('publication_revisions', 0);
    }

    public function test_maintenance_photo_requires_executed_or_verified_order(): void
    {
        $order = $this->order();
        $photo = app(EvidenceService::class)->upload($this->admin, null, null, $this->evidenceInput(), $order->id)->sole();
        try {
            $this->change('evidence', $photo, true);
            $this->fail('Una orden pendiente no debe publicar fotografías.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_public', $exception->errors());
        }
        $executed = $this->executedOrder();
        $photo = app(EvidenceService::class)->upload($this->admin, null, null, $this->evidenceInput(), $executed->id)->sole();
        $this->assertTrue($this->change('evidence', $photo, true)->is_public);
    }

    public function test_reserved_public_identities_are_immutable_in_database(): void
    {
        $organization = $this->change('organization', $this->organization->fresh(), true);
        $evidence = $this->change('evidence', $this->attachEvidence(), true);
        $this->rejects(fn () => DB::table('organizations')->where('id', $organization->id)->update(['public_code' => 'CHANGED']), '23514');
        $this->rejects(fn () => DB::table('organizations')->where('id', $organization->id)->update(['public_code' => null, 'is_public' => false]), '23514');
        $this->rejects(fn () => DB::table('evidences')->where('id', $evidence->id)->update(['public_id' => (string) Str::ulid()]), '23514');
    }

    public function test_database_rejects_public_identity_absence_invalid_version_and_oversized_caption(): void
    {
        $evidence = $this->attachEvidence();
        $this->rejects(fn () => DB::table('organizations')->where('id', $this->organization->id)->update(['is_public' => true]), '23514');
        $this->rejects(fn () => DB::table('evidences')->where('id', $evidence->id)->update(['is_public' => true]), '23514');
        $this->rejects(fn () => DB::table('evidences')->where('id', $evidence->id)->update(['public_caption' => str_repeat('a', 2001)]), '23514');
        $this->rejects(fn () => DB::table('trees')->where('id', $this->tree->id)->update(['publication_version' => 0]), '23514');
    }

    public function test_publication_history_is_append_only_and_references_exactly_one_subject_in_same_tenant(): void
    {
        $this->change('tree', $this->tree->fresh(), true);
        $revision = PublicationRevision::query()->sole();
        $this->rejects(fn () => DB::table('publication_revisions')->where('id', $revision->id)->update(['version' => 90]), '23514');
        $this->rejects(fn () => DB::table('publication_revisions')->where('id', $revision->id)->delete(), '23514');
        $values = DB::table('publication_revisions')->where('id', $revision->id)->first();
        $row = (array) $values;
        $row['id'] = (string) Str::uuid();
        $row['version'] = 99;
        $other = $this->anotherOrganization($this->organization);
        $this->rejects(fn () => DB::table('publication_revisions')->insert(array_replace($row, ['organization_id' => $other->id])), '23503');
        $this->rejects(fn () => DB::table('publication_revisions')->insert(array_replace($row, ['inventory_id' => $this->inventory->id])), '23514');
        $this->rejects(fn () => DB::table('publication_revisions')->insert(array_replace($row, ['tree_id' => null])), '23514');
    }

    public function test_ordinary_input_contracts_prohibit_publication_changes(): void
    {
        foreach ([OrganizationRules::rules(), InventoryInput::rules(), TreeInput::rules(),
            TreeInspectionInput::rules(), EvidenceInput::updateRules()] as $rules) {
            $validator = Validator::make(['is_public' => true, 'publication_version' => 2], $rules);
            $this->assertTrue($validator->fails());
            $this->assertTrue($validator->errors()->has('is_public'));
            $this->assertTrue($validator->errors()->has('publication_version'));
        }
    }

    private function subjects(): array
    {
        return [$this->organization->fresh(), $this->inventory->fresh(), $this->tree->fresh(), $this->capture(), $this->attachEvidence()];
    }

    private function subject(string $kind): Model
    {
        return match ($kind) {
            'organization' => $this->organization->fresh(), 'inventory' => $this->inventory->fresh(),
            'tree' => $this->tree->fresh(), 'inspection' => $this->capture(), 'evidence' => $this->attachEvidence(),
        };
    }

    private function change(string $kind, Model $record, bool $published): Model
    {
        $input = ['is_public' => $published, 'publication_version' => $record->publication_version];

        return $kind === 'organization'
            ? app(PublicationService::class)->organization($this->admin, $input)
            : app(PublicationService::class)->{$kind}($this->admin, $record->id, $input);
    }

    private function rejects(Closure $operation, string $sqlState): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL debía rechazar la operación.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode());
        }
    }
}

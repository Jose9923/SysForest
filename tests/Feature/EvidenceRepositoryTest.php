<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClassificationRuleVersion;
use App\Models\Evidence;
use App\Models\EvidenceType;
use App\Models\Organization;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\EvidenceRepository;
use App\Repositories\EvidenceRevisionRepository;
use App\Repositories\EvidenceTypeRepository;
use App\Repositories\TreeInspectionRepository;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\EvidenceTypeSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class EvidenceRepositoryTest extends PostgresTestCase
{
    private Organization $organization;
    private User $actor;
    private Tree $tree;
    private TreeInspection $inspection;
    private string $typeId;
    private string $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ForestryCatalogSeeder::class, ClassificationRuleSeeder::class, EvidenceTypeSeeder::class, MaintenanceCatalogSeeder::class]);
        $this->organization = $this->pilot();
        $this->actor = User::factory()->create();
        $this->typeId = EvidenceType::query()->where('code', 'EVD_WHOLE_TREE')->value('id');
        $this->ruleId = ClassificationRuleVersion::query()->where('code', 'EXCEL_V1')->value('id');
        $this->tree = $this->treeFor($this->organization);
        $this->inspection = $this->visit($this->tree);
    }

    public function test_both_explicit_owner_kinds_work_without_duplicating_the_evidence(): void
    {
        $treeEvidence = $this->record();
        $inspectionEvidence = $this->record(['tree_id' => null, 'tree_inspection_id' => $this->inspection->id]);
        $this->assertSame([$treeEvidence->id], $this->tree->fresh()->evidences->modelKeys());
        $this->assertSame([$inspectionEvidence->id], $this->inspection->fresh()->evidences->modelKeys());
        $this->assertNull($treeEvidence->inspection);
        $this->assertNull($inspectionEvidence->tree);
        $this->assertSame($this->tree->id, $inspectionEvidence->inspection->tree->id);
        $this->assertSame($this->typeId, $treeEvidence->evidenceType->id);
        $this->assertSame($this->actor->id, $treeEvidence->uploader->id);
        $this->assertDatabaseCount('evidences', 2);
    }

    #[DataProvider('invalidOwners')]
    public function test_database_requires_exactly_one_owner(string $selection): void
    {
        $attributes = $selection === 'both'
            ? ['tree_id' => $this->tree->id, 'tree_inspection_id' => $this->inspection->id]
            : ['tree_id' => null, 'tree_inspection_id' => null];
        $this->sqlFailure(fn () => $this->record($attributes), '23514');
    }

    public static function invalidOwners(): array
    {
        return [['both'], ['neither']];
    }

    public function test_database_rejects_a_tree_owner_of_another_tenant(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $this->sqlFailure(fn () => $this->record(['organization_id' => $other->id]), '23503');
    }

    public function test_database_rejects_an_inspection_owner_of_another_tenant(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $this->sqlFailure(fn () => $this->record([
            'organization_id' => $other->id, 'tree_id' => null, 'tree_inspection_id' => $this->inspection->id,
        ]), '23503');
    }

    public function test_evidence_type_is_one_required_real_foreign_key(): void
    {
        $this->sqlFailure(fn () => $this->record(['evidence_type_id' => null]), '23502');
        $this->sqlFailure(fn () => $this->record(['evidence_type_id' => (string) Str::uuid()]), '23503');
        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_upload_author_is_a_required_real_user(): void
    {
        $this->sqlFailure(fn () => $this->record(['uploaded_by' => null]), '23502');
        $this->sqlFailure(fn () => $this->record(['uploaded_by' => 999999999]), '23503');
    }

    #[DataProvider('immutableFields')]
    public function test_database_preserves_owner_identity_and_original_file_provenance(string $field, mixed $value): void
    {
        $evidence = $this->record();
        $this->sqlFailure(fn () => DB::table('evidences')->where('id', $evidence->id)->update([$field => $value]), '23514');
        $this->assertSame($evidence->getRawOriginal($field), $evidence->fresh()->getRawOriginal($field));
    }

    public static function immutableFields(): array
    {
        $uuid = '11111111-1111-4111-8111-111111111111';

        return [
            ['id', $uuid], ['organization_id', $uuid], ['tree_id', $uuid], ['tree_inspection_id', $uuid],
            ['uploaded_by', 999999999], ['created_at', '2000-01-01 00:00:00+00'],
            ['disk', 'other-disk'], ['path', 'replacement.jpg'], ['original_name', 'replacement.jpg'],
            ['mime_type', 'image/png'], ['extension', 'png'], ['size_bytes', 500],
            ['width', 500], ['height', 500], ['checksum_sha256', str_repeat('a', 64)],
            ['metadata', '{"source":"changed"}'],
        ];
    }

    public function test_repository_metadata_update_drops_owner_and_provenance_fields(): void
    {
        $evidence = $this->record();
        $before = $evidence->getRawOriginal();
        $otherActor = User::factory()->create();
        $changed = app(EvidenceRepository::class)->update($evidence, [
            'caption' => 'Detalle de copa', 'notes' => 'Texto institucional',
            'captured_at' => '2026-09-01 13:24:00.123456+00', 'updated_by' => $otherActor->id, 'lock_version' => 2,
            'tree_id' => (string) Str::uuid(), 'organization_id' => (string) Str::uuid(),
            'disk' => 'public', 'path' => 'overwrite.php', 'uploaded_by' => $otherActor->id, 'metadata' => ['client' => true],
            'deleted_at' => now(), 'deleted_by' => $otherActor->id,
        ]);
        foreach (['tree_id', 'organization_id', 'disk', 'path', 'uploaded_by', 'metadata'] as $field) {
            $this->assertSame($before[$field], $changed->getRawOriginal($field));
        }
        $this->assertSame('Detalle de copa', $changed->caption);
        $this->assertSame($otherActor->id, $changed->updated_by);
        $this->assertSame(2, $changed->lock_version);
        $this->assertSame('123456', $changed->captured_at->format('u'));
        $this->assertNull($changed->deleted_at);
    }

    #[DataProvider('invalidValues')]
    public function test_database_checks_file_metadata_and_lifecycle_values(string $field, mixed $value): void
    {
        $this->sqlFailure(fn () => $this->record([$field => $value]), '23514');
    }

    public static function invalidValues(): array
    {
        return [
            ['size_bytes', 0], ['size_bytes', -1], ['width', 0], ['height', -1],
            ['lock_version', 0], ['checksum_sha256', 'not-a-checksum'], ['metadata', 'not-an-object'],
            ['disk', ''], ['path', ''], ['original_name', ''], ['mime_type', ''], ['extension', ''],
            ['deleted_at', '2026-09-12 00:00:00+00'],
        ];
    }

    public function test_nullable_metadata_is_unknown_and_empty_metadata_is_a_json_object(): void
    {
        $unknown = $this->record(['metadata' => null, 'captured_at' => null, 'width' => null, 'height' => null, 'checksum_sha256' => null]);
        $empty = $this->record(['metadata' => []]);
        $this->assertNull($unknown->metadata);
        $this->assertNull($unknown->captured_at);
        $this->assertNull($unknown->width);
        $this->assertNull($unknown->height);
        $this->assertNull($unknown->checksum_sha256);
        $this->assertSame('object', DB::table('evidences')->where('id', $empty->id)->selectRaw('jsonb_typeof(metadata) as kind')->value('kind'));
    }

    public function test_storage_path_is_unique_even_for_annulled_evidence_but_checksum_can_repeat(): void
    {
        $evidence = $this->record();
        $repository = app(EvidenceRepository::class);
        $repository->void($evidence, ['deleted_by' => $this->actor->id, 'updated_by' => $this->actor->id, 'lock_version' => 2]);
        $this->sqlFailure(fn () => $this->record(['disk' => $evidence->disk, 'path' => $evidence->path]), '23505');
        $duplicateBytes = $this->record(['checksum_sha256' => $evidence->checksum_sha256]);
        $this->assertNotSame($evidence->path, $duplicateBytes->path);
        $this->assertTrue($repository->referencedStoragePath($evidence->disk, $evidence->path));
        $this->assertFalse($repository->referencedStoragePath('other-disk', $evidence->path));
        $this->assertFalse($repository->referencedStoragePath($evidence->disk, 'missing.jpg'));
    }

    public function test_softdelete_and_restore_keep_identity_and_original_uploader(): void
    {
        $evidence = $this->record();
        $repository = app(EvidenceRepository::class);
        $originalId = $evidence->id;
        $originalPath = $evidence->path;
        $otherActor = User::factory()->create();
        $voided = $repository->void($evidence, [
            'deleted_by' => $otherActor->id, 'updated_by' => $otherActor->id, 'lock_version' => 2,
            'deletion_notes' => 'Archivo seleccionado por error.',
        ]);
        $this->assertTrue($voided->trashed());
        $this->assertSame($otherActor->id, $voided->deleted_by);
        $this->assertCount(0, $repository->forOwner($this->organization->id, $this->tree->id, null));
        $this->assertCount(1, $repository->forOwner($this->organization->id, $this->tree->id, null, ['trashed' => 'only']));
        $this->assertTrue($repository->referencedStoragePath($voided->disk, $originalPath));
        $restored = $repository->restore($voided, ['updated_by' => $otherActor->id, 'lock_version' => 3]);
        $this->assertSame($originalId, $restored->id);
        $this->assertSame($originalPath, $restored->path);
        $this->assertSame($this->actor->id, $restored->uploaded_by);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->deleted_by);
        $this->assertNull($restored->deletion_notes);
        $this->assertSame(3, $restored->lock_version);
        $this->assertCount(1, $repository->forOwner($this->organization->id, $this->tree->id, null));
        $this->assertDatabaseCount('evidences', 1);
    }

    public function test_database_rejects_hard_delete(): void
    {
        $evidence = $this->record();
        $this->sqlFailure(fn () => DB::table('evidences')->where('id', $evidence->id)->delete(), '23514');
    }

    public function test_disabled_type_is_visible_historically_but_not_available_for_upload_or_change(): void
    {
        $evidence = $this->record();
        $type = EvidenceType::query()->findOrFail($this->typeId);
        $type->update(['active' => false]);
        $types = app(EvidenceTypeRepository::class);
        $this->assertFalse($types->validate($type->id, null, true));
        $this->assertTrue($types->validate($type->id, $evidence->evidence_type_id, true));
        $this->assertFalse($types->options()->contains('id', $type->id));
        $this->assertTrue($types->options($evidence)->contains('id', $type->id));
        $this->assertTrue($types->optionsForFilter()->contains('id', $type->id));
        $this->sqlFailure(fn () => $this->record(), '23514');

        $otherType = EvidenceType::query()->whereKeyNot($type->id)->firstOrFail();
        $other = $this->record(['evidence_type_id' => $otherType->id]);
        $this->assertFalse($types->validate($type->id, $otherType->id, true));
        $this->sqlFailure(fn () => DB::table('evidences')->where('id', $other->id)->update(['evidence_type_id' => $type->id]), '23514');
        $updated = app(EvidenceRepository::class)->update($evidence, ['caption' => 'Referencia histórica conservada', 'evidence_type_id' => $type->id, 'lock_version' => 2]);
        $this->assertSame($type->id, $updated->evidence_type_id);
        $this->assertFalse($updated->evidenceType->active);
        $voided = app(EvidenceRepository::class)->void($updated, ['deleted_by' => $this->actor->id, 'lock_version' => 3]);
        $restored = app(EvidenceRepository::class)->restore($voided, ['lock_version' => 4]);
        $this->assertSame($type->id, $restored->evidence_type_id);
        $this->assertFalse($types->validate((string) Str::uuid(), null, true));
        $this->assertFalse($types->validate('not-a-uuid'));
    }

    public function test_revision_snapshots_are_structured_unique_and_append_only(): void
    {
        $evidence = $this->record();
        $repository = app(EvidenceRevisionRepository::class);
        $snapshot = ['owner' => ['tree_id' => $this->tree->id], 'evidence_type_id' => $this->typeId, 'caption' => 'Detalle'];
        $revision = $repository->record($this->organization->id, $evidence->id, (int) $this->actor->id, 1, [], $snapshot, 'evidence.uploaded');
        $this->assertEquals($snapshot, $revision->fresh()->new_values);
        $this->assertSame('object', DB::table('evidence_revisions')->where('id', $revision->id)->selectRaw('jsonb_typeof(old_values) as kind')->value('kind'));
        $this->sqlFailure(fn () => $repository->record($this->organization->id, $evidence->id, (int) $this->actor->id, 1, [], [], 'evidence.uploaded'), '23505');
        $this->sqlFailure(fn () => DB::table('evidence_revisions')->where('id', $revision->id)->update(['new_values' => '{}']), '23514');
        $this->sqlFailure(fn () => DB::table('evidence_revisions')->where('id', $revision->id)->delete(), '23514');
        $this->assertSame([$revision->id], $repository->forEvidence($this->organization->id, $evidence->id)->modelKeys());
        $this->assertSame($this->actor->id, $repository->forEvidence($this->organization->id, $evidence->id)->sole()->changedBy->id);
        $this->assertCount(0, $repository->forEvidence($this->anotherOrganization($this->organization)->id, $evidence->id));
    }

    public function test_revision_tenant_fk_and_json_shape_are_enforced_by_database(): void
    {
        $evidence = $this->record();
        $other = $this->anotherOrganization($this->organization);
        $attributes = [
            'id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'evidence_id' => $evidence->id,
            'version' => 1, 'changed_by' => $this->actor->id, 'changed_at' => now(), 'event' => 'evidence.uploaded',
            'old_values' => '{}', 'new_values' => '{}',
        ];
        $this->sqlFailure(fn () => DB::table('evidence_revisions')->insert(array_replace($attributes, ['organization_id' => $other->id])), '23503');
        $this->sqlFailure(fn () => DB::table('evidence_revisions')->insert(array_replace($attributes, ['old_values' => '[]'])), '23514');
        $this->sqlFailure(fn () => DB::table('evidence_revisions')->insert(array_replace($attributes, ['new_values' => 'null'])), '23514');
        $this->sqlFailure(fn () => DB::table('evidence_revisions')->insert(array_replace($attributes, ['event' => 'unapproved.event'])), '23514');
    }

    public function test_revisions_can_record_void_and_restore_with_original_resource_link(): void
    {
        $evidence = $this->record();
        $repository = app(EvidenceRepository::class);
        $revisions = app(EvidenceRevisionRepository::class);
        $voided = $repository->void($evidence, ['deleted_by' => $this->actor->id, 'lock_version' => 2]);
        $revision = $revisions->record($this->organization->id, $voided->id, (int) $this->actor->id, 2, ['deleted' => false], ['deleted' => true], 'evidence.voided');
        $this->assertTrue($revision->fresh()->evidence->trashed());
        $restored = $repository->restore($voided, ['lock_version' => 3]);
        $restoration = $revisions->record($this->organization->id, $restored->id, (int) $this->actor->id, 3, ['deleted' => true], ['deleted' => false], 'evidence.restored');
        $this->assertSame([$restoration->id, $revision->id], $revisions->forEvidence($this->organization->id, $restored->id)->modelKeys());
    }

    public function test_owner_queries_and_known_foreign_ids_remain_scoped(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $foreignTree = $this->treeFor($other);
        $foreign = $this->record(['organization_id' => $other->id, 'tree_id' => $foreignTree->id]);
        $repository = app(EvidenceRepository::class);
        $this->assertCount(0, $repository->forOwner($this->organization->id, $foreignTree->id, null));
        $this->assertCount(0, $repository->forOwners($this->organization->id, [$foreignTree->id]));
        $this->expectException(ModelNotFoundException::class);
        $repository->findForTenant($this->organization->id, $foreign->id, false, true);
    }

    #[DataProvider('invalidOwners')]
    public function test_owner_query_rejects_ambiguous_selection(string $selection): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(EvidenceRepository::class)->forOwner(
            $this->organization->id,
            $selection === 'both' ? $this->tree->id : null,
            $selection === 'both' ? $this->inspection->id : null
        );
    }

    public function test_gallery_filters_date_type_and_lifecycle_without_mixing_owners(): void
    {
        $faunaType = EvidenceType::query()->where('code', 'EVD_FAUNA')->value('id');
        $old = $this->record(['created_at' => '2025-01-01 00:00:00+00']);
        $current = $this->record(['evidence_type_id' => $faunaType, 'created_at' => '2026-09-12 12:00:00.123456+00']);
        $voided = $this->record(['created_at' => '2026-09-13 00:00:00+00']);
        $this->record(['tree_id' => null, 'tree_inspection_id' => $this->inspection->id, 'evidence_type_id' => $faunaType]);
        $repository = app(EvidenceRepository::class);
        $repository->void($voided, ['deleted_by' => $this->actor->id, 'lock_version' => 2]);
        $this->assertSame([$current->id, $old->id], $repository->forOwner($this->organization->id, $this->tree->id, null)->modelKeys());
        $this->assertSame([$current->id], $repository->forOwner($this->organization->id, $this->tree->id, null, ['evidence_type_id' => $faunaType])->modelKeys());
        $this->assertSame([$current->id], $repository->forOwner($this->organization->id, $this->tree->id, null, ['date_from' => '2026-09-12', 'date_to' => '2026-09-12'])->modelKeys());
        $this->assertSame([$voided->id], $repository->forOwner($this->organization->id, $this->tree->id, null, ['trashed' => 'only'])->modelKeys());
        $this->assertCount(3, $repository->forOwner($this->organization->id, $this->tree->id, null, ['trashed' => 'with']));
        $this->assertSame(2, $repository->paginateForOwner($this->organization->id, $this->tree->id, null)->total());
        $this->assertSame([$old->id, $current->id], $repository->forOwner($this->organization->id, $this->tree->id, null, ['direction' => 'asc'])->modelKeys());
        $this->assertSame('123456', $current->fresh()->created_at->format('u'));
    }

    public function test_owner_summary_limits_records_but_preserves_total_and_ignores_requested_page(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->record();
        }
        $voided = $this->record();
        $repository = app(EvidenceRepository::class);
        $repository->void($voided, ['deleted_by' => $this->actor->id, 'lock_version' => 2]);
        $this->record(['tree_id' => null, 'tree_inspection_id' => $this->inspection->id]);
        request()->query->set('evidence_summary', 3);
        $summary = $repository->summaryForOwner($this->organization->id, $this->tree->id, null);

        $this->assertSame(8, $summary->total());
        $this->assertSame(6, $summary->perPage());
        $this->assertSame(1, $summary->currentPage());
        $this->assertCount(6, $summary->items());
        $this->assertFalse($summary->getCollection()->contains('id', $voided->id));
    }

    public function test_batch_loading_has_fixed_query_count_and_omits_private_storage_fields(): void
    {
        $trees = [$this->tree];
        $inspections = [$this->inspection];
        for ($i = 0; $i < 3; $i++) {
            $trees[] = $this->treeFor($this->organization);
            $inspections[] = $this->visit($trees[$i + 1]);
        }
        foreach ($trees as $index => $tree) {
            $this->record(['tree_id' => $tree->id]);
            $this->record(['tree_id' => null, 'tree_inspection_id' => $inspections[$index]->id]);
        }
        $repository = app(EvidenceRepository::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $repository->forOwners($this->organization->id, [$trees[0]->id], [$inspections[0]->id]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $batch = $repository->forOwners($this->organization->id, array_map(fn (Tree $tree) => $tree->id, $trees), array_map(fn (TreeInspection $inspection) => $inspection->id, $inspections));
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(9, $many);
        $this->assertCount(8, $batch);
        foreach ($batch as $evidence) {
            foreach (['evidenceType', 'uploader', 'updater', 'tree', 'inspection'] as $relation) {
                $this->assertTrue($evidence->relationLoaded($relation));
            }
            $this->assertArrayNotHasKey('disk', $evidence->toArray());
            $this->assertArrayNotHasKey('path', $evidence->toArray());
            $this->assertArrayNotHasKey('metadata', $evidence->toArray());
        }
        $this->assertCount(0, $repository->forOwners($this->organization->id));
    }

    public function test_annulled_tree_and_inspection_owners_remain_resolvable_for_policy_checks(): void
    {
        $treeEvidence = $this->record();
        $inspectionEvidence = $this->record(['tree_id' => null, 'tree_inspection_id' => $this->inspection->id]);
        $this->tree->forceFill([
            'deleted_at' => now(), 'deleted_by' => $this->actor->id,
            'deletion_reason_id' => DB::table('deletion_reasons')->value('id'),
        ])->save();
        $repository = app(EvidenceRepository::class);
        $this->assertTrue($repository->findForTenant($this->organization->id, $treeEvidence->id)->tree->trashed());
        $this->assertTrue($repository->findForTenant($this->organization->id, $inspectionEvidence->id)->inspection->tree->trashed());
    }

    public function test_upload_and_reconciliation_share_a_transaction_lock_before_a_row_exists(): void
    {
        $id = (string) Str::uuid();
        $connectionName = 'evidence_lock_probe';
        config(['database.connections.'.$connectionName => config('database.connections.pgsql')]);
        $probe = DB::connection($connectionName);
        $initialLevel = DB::transactionLevel();
        DB::beginTransaction();
        $probe->beginTransaction();
        try {
            app(EvidenceRepository::class)->lockStorageOperation(strtoupper($id));
            $same = $probe->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['sysforest:evidence:'.$id]);
            $this->assertFalse($same->acquired);
            $different = $probe->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['sysforest:evidence:'.Str::uuid()]);
            $this->assertTrue($different->acquired);
            DB::rollBack();
            $released = $probe->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['sysforest:evidence:'.$id]);
            $this->assertTrue($released->acquired);
            $this->assertDatabaseMissing('evidences', ['id' => $id]);
        } finally {
            while (DB::transactionLevel() > $initialLevel) {
                DB::rollBack();
            }
            $probe->rollBack();
            DB::purge($connectionName);
        }
    }

    private function record(array $attributes = []): Evidence
    {
        $id = (string) Str::uuid();

        return app(EvidenceRepository::class)->create(array_replace([
            'id' => $id, 'organization_id' => $this->organization->id, 'evidence_type_id' => $this->typeId,
            'tree_id' => $this->tree->id, 'tree_inspection_id' => null, 'disk' => 'evidences',
            'path' => $this->organization->id.'/'.$id.'.jpg', 'original_name' => 'Individuo original.jpg',
            'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1200, 'width' => 80, 'height' => 60,
            'checksum_sha256' => hash('sha256', 'fixture de procedencia'), 'metadata' => ['orientation' => 1],
            'uploaded_by' => $this->actor->id, 'updated_by' => $this->actor->id, 'lock_version' => 1,
        ], $attributes));
    }

    private function treeFor(Organization $organization): Tree
    {
        $tree = new Tree();
        $tree->forceFill([
            'organization_id' => $organization->id, 'code' => 'EVIDENCE-'.strtoupper(Str::random(12)),
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ])->save();

        return $tree;
    }

    private function visit(Tree $tree): TreeInspection
    {
        return app(TreeInspectionRepository::class)->create([
            'organization_id' => $tree->organization_id, 'tree_id' => $tree->id, 'inspection_date' => '2026-09-12',
            'classification_rule_version_id' => $this->ruleId, 'calculation_rule_version' => 'DENDROMETRY_V1',
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id, 'lock_version' => 1,
        ]);
    }

    private function sqlFailure(callable $operation, string $state): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL debía rechazar esta referencia o valor.');
        } catch (QueryException $exception) {
            $this->assertSame($state, (string) $exception->getCode());
        }
    }
}

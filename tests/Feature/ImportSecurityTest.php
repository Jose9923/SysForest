<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\RoleAssignmentRepository;
use App\Services\ImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ImportTestCase;

class ImportSecurityTest extends ImportTestCase
{
    public function test_foreign_batch_is_not_disclosed_even_when_actor_belongs_to_both_organizations(): void
    {
        $batch = $this->batch();
        $other = $this->anotherOrganization();
        $this->member($this->admin, $other);
        $this->activateInspectionTenant($this->admin, $other);
        $this->expectException(ModelNotFoundException::class);
        app(ImportService::class)->show($this->admin, $batch->id);
    }

    public function test_foreign_confirmation_cannot_mutate_batch_or_create_trees(): void
    {
        $batch = $this->batch();
        $other = $this->anotherOrganization();
        $this->member($this->admin, $other);
        $this->actingTenant($this->admin, $other);
        $before = Tree::withTrashed()->count();
        $this->postJson(route('imports.confirm', $batch->id), ['confirmation' => '1', 'lock_version' => 1])->assertNotFound();
        $this->assertSame($before, Tree::withTrashed()->count());
        $this->assertSame('ready', $batch->fresh()->status->value);
    }

    public function test_inventory_from_another_organization_cannot_receive_uploaded_rows(): void
    {
        $other = $this->anotherOrganization();
        $this->member($this->admin, $other);
        $this->activateInspectionTenant($this->admin, $other);
        $this->expectException(ModelNotFoundException::class);
        app(ImportService::class)->upload($this->admin, $this->workbook([$this->importInput()]), $this->inventory->id);
    }

    public function test_membership_revocation_after_preview_prevents_confirmation(): void
    {
        $batch = $this->batch();
        DB::table('organization_memberships')->where('organization_id', $this->organization->id)
            ->where('user_id', $this->admin->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(ImportService::class)->confirm($this->admin, $batch->id, 1);
    }

    public function test_account_deactivation_after_preview_prevents_access(): void
    {
        $batch = $this->batch();
        DB::table('users')->where('id', $this->admin->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(ImportService::class)->show($this->admin, $batch->id);
    }

    public function test_department_role_cannot_read_internal_lots_or_download_template(): void
    {
        $actor = User::factory()->create();
        $this->ensureAuthorizationCatalog();
        app(RoleAssignmentRepository::class)->grantDepartment((int) $actor->id, $this->organization->municipality->department_id);
        $this->actingTenant($actor, $this->organization);
        foreach (['imports.index', 'imports.create', 'imports.template'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_super_admin_must_select_an_authorized_tenant_and_can_read_its_lots(): void
    {
        $batch = $this->batch();
        $actor = User::factory()->create();
        $this->allowNationalManagement($actor);
        $this->activateInspectionTenant($actor);
        $this->assertSame($batch->id, app(ImportService::class)->show($actor, $batch->id)['batch']->id);
    }

    public function test_database_rejects_cross_tenant_row_batch_reference(): void
    {
        $batch = $this->batch();
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        DB::transaction(fn () => DB::table('import_rows')->insert(array_replace($row->getRawOriginal(), [
            'id' => (string) Str::uuid(), 'organization_id' => $this->anotherOrganization()->id, 'row_number' => 10,
        ])));
    }

    #[DataProvider('immutableBatchFields')]
    public function test_database_preserves_batch_source_and_creator(string $field, mixed $value): void
    {
        $batch = $this->batch();
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('import_batches')->where('id', $batch->id)->update([$field => $value]);
    }

    public static function immutableBatchFields(): array
    {
        return [['file_sha256', str_repeat('a', 64)], ['file_name', 'cambiado.xlsx'], ['code', 'IMP-CAMBIADO'],
            ['schema_version', 'OTRA_VERSION'], ['file_size', 1]];
    }

    public function test_original_row_cannot_be_modified_or_deleted(): void
    {
        $batch = $this->batch();
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('import_rows')->where('import_batch_id', $batch->id)->update(['raw_values' => '{}']);
    }

    public function test_database_cannot_mark_unwritten_rows_as_completed(): void
    {
        $batch = $this->batch();
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'completed',
            'imported_rows' => 1, 'confirmed_by' => $this->admin->id, 'confirmed_at' => now()]);
    }

    public function test_confirmed_rows_and_result_are_immutable(): void
    {
        $batch = $this->batch();
        app(ImportService::class)->confirm($this->admin, $batch->id, 1);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('import_rows')->where('import_batch_id', $batch->id)->update(['errors' => '{}']);
    }

    public function test_real_http_upload_and_confirmation_require_csrf_and_keep_cache_private(): void
    {
        $batch = $this->batch();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('imports.show', $batch->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('imports.confirm', $batch->id), ['confirmation' => '1', 'lock_version' => 1])->assertStatus(419);
        $this->post(route('imports.store'), ['file' => $this->workbook([$this->importInput()]), 'inventory_id' => $this->inventory->id])->assertStatus(419);
        $response = $this->get('/login')->assertOk();
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null) {
            $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        }
        preg_match('/name="_token"\s+value="([^"]+)"/', (string) $response->getContent(), $match);
        $this->assertArrayHasKey(1, $match);
        $this->post(route('imports.confirm', $batch->id), ['confirmation' => '1', 'lock_version' => 1,
            '_token' => html_entity_decode($match[1], ENT_QUOTES)])->assertRedirect(route('imports.show', $batch->id));
        $this->assertSame('completed', $batch->fresh()->status->value);
    }

    private function batch(): ImportBatch
    {
        return app(ImportService::class)->upload($this->admin, $this->workbook([$this->importInput()]), $this->inventory->id);
    }
}

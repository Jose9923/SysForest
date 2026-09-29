<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Repositories\ExportFileRepository;
use App\Repositories\ReportSnapshotRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\PublicPortalTestCase;

/** Transporte real, credenciales JWT de prueba y archivos efímeros; no omite middleware. */
class ExportDownloadTest extends PublicPortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['reports.download_rate_private' => 1000, 'reports.download_rate_public' => 1000]);
    }

    #[DataProvider('formats')]
    public function test_private_and_public_downloads_are_complete_typed_and_deleted_after_sending(string $format, bool $public, string $signature): void
    {
        if ($public) {
            $this->publishTree();
        } else {
            $this->actingTenant($this->admin, $this->organization);
        }
        $response = $this->get($this->download($format, $public));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Export-Row-Count', '1');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.'.$format, (string) $response->headers->get('Content-Disposition'));
        $bytes = $this->send($response);
        $this->assertStringStartsWith($signature, $bytes);
        if ($format === 'geojson') {
            $this->assertCount(1, json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['features']);
        }
    }

    public static function formats(): array
    {
        $cases = [];
        foreach (['csv' => "\xEF\xBB\xBF", 'xlsx' => 'PK', 'geojson' => '{"type":"FeatureCollection"', 'pdf' => '%PDF-'] as $format => $signature) {
            $cases[] = [$format, false, $signature];
            $cases[] = [$format, true, $signature];
        }

        return $cases;
    }

    public function test_private_download_requires_authentication(): void
    {
        $this->getJson($this->download('csv'))->assertUnauthorized();
    }

    public function test_export_permission_is_enforced_by_the_real_download_endpoint(): void
    {
        $this->removePermission('reports.export');
        $this->actingTenant($this->admin, $this->organization)->getJson($this->download('csv'))->assertForbidden();
    }

    public function test_inventory_photo_links_require_evidence_permission_even_when_there_are_no_photos(): void
    {
        $this->removePermission('evidences.view');
        $this->actingTenant($this->admin, $this->organization)->getJson($this->download('csv'))->assertForbidden();
    }

    public function test_other_organization_filter_and_individual_uuid_do_not_expand_scope(): void
    {
        $other = $this->anotherOrganization($this->organization);
        $foreign = $this->point(-73.5, 4, organization: $other);
        $this->actingTenant($this->admin, $this->organization);
        $this->getJson($this->download('csv', false, ['organization_id' => $other->id]))->assertNotFound();
        $this->getJson(route('reports.trees.pdf', ['tree' => $foreign->id], false))->assertNotFound();
    }

    public function test_public_uuid_and_unpublished_tree_cannot_be_downloaded_as_an_individual(): void
    {
        $this->publishOrganization();
        $this->getJson(route('public.trees.pdf', ['organization' => $this->organization->public_code, 'tree' => $this->tree->code], false))->assertNotFound();
        $this->publishTree();
        $this->getJson(route('public.trees.pdf', ['organization' => $this->organization->id, 'tree' => $this->tree->id], false))->assertNotFound();
    }

    public function test_public_individual_and_private_individual_have_pdf_transport(): void
    {
        $this->publishTree();
        $public = $this->get(route('public.trees.pdf', ['organization' => $this->organization->public_code, 'tree' => $this->tree->code], false));
        $public->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $this->send($public));
        $this->actingTenant($this->admin, $this->organization);
        $private = $this->get(route('reports.trees.pdf', ['tree' => $this->tree->id], false));
        $private->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->send($private));
    }

    public function test_public_geojson_and_metadata_never_include_private_values_or_source_references(): void
    {
        $this->tree->forceFill(['external_number' => 'PRIVATE-LEGACY', 'gps_model' => 'PRIVATE-GPS',
            'location_description' => 'PRIVATE-LOCATION'])->save();
        $inspection = $this->capture(['general_observations' => 'PRIVATE-OBSERVATIONS', 'fauna_observations' => 'PRIVATE-FAUNA',
            'flowering_development' => 'PRIVATE-FLOWERING', 'fruiting_development' => 'PRIVATE-FRUITING']);
        $this->publishTree();
        $this->publishInspection($inspection);
        $evidence = $this->publishEvidence($this->attachEvidence(['caption' => 'PRIVATE-PHOTO'], inspectionId: $inspection->id));
        $response = $this->get($this->download('geojson', true, ['organization_id' => $this->organization->public_code]));
        $response->assertOk();
        $bytes = $this->send($response);
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-', $this->admin->email, $this->tree->id, $inspection->id, $evidence->id, $this->organization->id,
            '_source', 'storage_path', 'checksum_sha256', 'created_by', 'selectedOptions', 'filterConfig', 'gps_model', 'general_observations'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $bytes);
        }
        $this->assertStringContainsString($evidence->public_id, $bytes);
        $this->assertSame(['title', 'generatedAt', 'isPublic', 'scopeLabel', 'appliedFilters', 'summary', 'notices'], array_keys($data['metadata']));
        $this->assertSame($this->organization->public_code.'/'.$this->tree->code, $data['features'][0]['id']);
    }

    public function test_csv_download_neutralizes_untrusted_text_and_preserves_whole_filtered_rows(): void
    {
        $this->tree->forceFill(['location_description' => "\t=HYPERLINK(\"https://example.invalid\")"])->save();
        $this->point(-73.5, 4, ['current_status' => 'dead']);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get($this->download('csv', false, ['current_status' => 'active', 'page' => 3]));
        $response->assertOk()->assertHeader('X-Export-Row-Count', '1');
        $rows = $this->csv($this->send($response));

        $this->assertCount(2, $rows);
        $this->assertContains("'\t=HYPERLINK(\"https://example.invalid\")", $rows[1]);
    }

    #[DataProvider('limits')]
    public function test_row_limits_reject_the_entire_file_and_remove_the_temporary(bool $public, string $format, string $limit): void
    {
        $other = $this->point(-73.5, 4);
        if ($public) {
            $this->publishTree();
            $this->publishTree($other);
        } else {
            $this->actingTenant($this->admin, $this->organization);
        }
        config([$limit => 1]);
        $before = $this->temporaryFiles();
        $response = $this->getJson($this->download($format, $public));
        $response->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->assertFalse($response->headers->has('Content-Disposition'));
        $this->assertSame($before, $this->temporaryFiles());
    }

    public static function limits(): array
    {
        return [[true, 'csv', 'reports.max_export_rows_public'], [false, 'xlsx', 'reports.max_export_rows_private'],
            [true, 'pdf', 'reports.max_pdf_rows'], [false, 'pdf', 'reports.max_pdf_rows']];
    }

    public function test_membership_revoked_after_generation_prevents_sending_the_file(): void
    {
        $before = $this->temporaryFiles();
        $this->afterSnapshot(function (): void {
            DB::table('organization_memberships')->where('user_id', $this->admin->id)->update(['active' => false, 'left_at' => now()]);
        });
        $this->actingTenant($this->admin, $this->organization)->getJson($this->download('csv'))->assertForbidden();
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_tree_unpublished_after_generation_prevents_public_delivery(): void
    {
        $this->publishTree();
        $before = $this->temporaryFiles();
        $this->afterSnapshot(fn () => $this->tree->forceFill(['is_public' => false])->save());
        $this->getJson($this->download('geojson', true))->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_inspection_unpublished_after_generation_prevents_public_delivery(): void
    {
        $this->publishTree();
        $inspection = $this->publishInspection($this->capture());
        $before = $this->temporaryFiles();
        $this->afterSnapshot(fn () => $inspection->forceFill(['is_public' => false])->save());
        $this->getJson($this->download('csv', true))->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_photo_unpublished_after_generation_prevents_public_delivery_of_its_link(): void
    {
        $this->publishTree();
        $inspection = $this->publishInspection($this->capture());
        $evidence = $this->publishEvidence($this->attachEvidence(inspectionId: $inspection->id));
        $before = $this->temporaryFiles();
        $this->afterSnapshot(fn () => $evidence->forceFill(['is_public' => false])->save());
        $this->getJson($this->download('csv', true))->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_maintenance_aggregate_is_recomputed_before_public_delivery(): void
    {
        $this->publishTree();
        $order = $this->executedOrder();
        $before = $this->temporaryFiles();
        $this->afterSnapshot(fn () => $order->forceFill(['deleted_at' => now(), 'deleted_by' => $this->admin->id,
            'deletion_notes' => 'Revocación durante la descarga', 'lock_version' => $order->lock_version + 1])->save());
        $this->getJson($this->download('csv', true, ['report' => 'maintenances']))->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_empty_maintenance_aggregate_revalidates_the_tree_summary_before_delivery(): void
    {
        $this->publishTree();
        $before = $this->temporaryFiles();
        $this->afterSnapshot(fn () => $this->tree->forceFill(['is_public' => false])->save());
        $this->getJson($this->download('pdf', true, ['report' => 'maintenances']))
            ->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_invalid_combinations_and_internal_filters_are_rejected_without_a_file(): void
    {
        $this->getJson($this->download('geojson', true, ['report' => 'species']))->assertUnprocessable();
        $this->getJson($this->download('csv', true, ['scope' => 'national']))->assertUnprocessable();
        $this->actingTenant($this->admin, $this->organization);
        $this->getJson($this->download('csv', false, ['trashed' => 'only']))->assertUnprocessable();
        $this->getJson($this->download('csv', false, ['tree_id' => $this->tree->id]))->assertUnprocessable();
        $this->getJson($this->download('csv', false, ['report' => 'zones']))->assertUnprocessable();
    }

    public function test_public_download_rate_limit_returns_retry_after_without_partial_bytes(): void
    {
        $this->publishTree();
        config(['reports.download_rate_public' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.213']);
        $first = $this->get($this->download('csv', true));
        $first->assertOk();
        $this->send($first);
        $response = $this->getJson($this->download('csv', true));
        $response->assertStatus(429)->assertHeader('Retry-After');
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    #[DataProvider('errorModes')]
    public function test_infrastructure_errors_never_expose_paths_or_traces_even_in_debug(bool $public): void
    {
        config(['app.debug' => true]);
        if ($public) {
            $this->publishTree();
        } else {
            $this->actingTenant($this->admin, $this->organization);
        }
        $this->mock(ExportFileRepository::class)->shouldReceive('create')->once()
            ->andThrow(new \RuntimeException('PRIVATE-SIMULATED-PATH /srv/private/report.bin'));
        $response = $this->getJson($this->download('csv', $public));
        $response->assertStatus(503);
        $this->assertSame(['message'], array_keys($response->json()));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        foreach (['PRIVATE-SIMULATED', '/srv/private', 'trace', 'exception', 'RuntimeException'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    public static function errorModes(): array
    {
        return [[false], [true]];
    }

    private function download(string $format, bool $public = false, array $filters = []): string
    {
        return route($public ? 'public.reports.download' : 'reports.download', ['format' => $format] + $filters, false);
    }

    private function send(TestResponse $response): string
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertSame(app(ExportFileRepository::class)->directory(), dirname($path));
        $this->assertFileExists($path);
        ob_start();
        try {
            $response->baseResponse->sendContent();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        clearstatcache(true, $path);
        $this->assertFileDoesNotExist($path);

        return $bytes;
    }

    private function temporaryFiles(): array
    {
        $files = glob(app(ExportFileRepository::class)->directory().'/*.part') ?: [];
        sort($files);

        return $files;
    }

    /** Simula una revocación ya confirmada entre la lectura y la comprobación final, sin omitir servicios. */
    private function afterSnapshot(Closure $change): void
    {
        $this->mock(ReportSnapshotRepository::class)->shouldReceive('read')->once()->andReturnUsing(function (Closure $read) use ($change): mixed {
            $result = $read();
            $change();

            return $result;
        });
    }

    private function removePermission(string $code): void
    {
        Role::query()->where('name', 'ORGANIZATION_ADMIN')->sole()->permissions()->detach(Permission::query()->where('code', $code)->sole()->id);
    }

    private function csv(string $bytes): array
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, substr($bytes, 3));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}

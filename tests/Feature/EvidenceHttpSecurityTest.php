<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\EvidenceTestCase;

class EvidenceHttpSecurityTest extends EvidenceTestCase
{
    #[DataProvider('foreignActions')]
    public function test_official_b_cannot_access_or_mutate_evidence_and_owners_a(string $action): void
    {
        $inspection = $this->capture();
        $evidence = $this->attachEvidence(inspectionId: $inspection->id);
        $other = $this->anotherOrganization($this->organization);
        $outsider = User::factory()->create();
        $this->member($outsider, $other);
        $this->actingTenant($outsider, $other);
        $response = match ($action) {
            'tree_upload' => $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput()),
            'inspection_upload' => $this->postJson(route('inspections.evidences.store', $inspection->id), $this->evidenceInput()),
            'tree_gallery' => $this->getJson(route('trees.evidences.index', $this->tree->id)),
            'inspection_gallery' => $this->getJson(route('inspections.evidences.index', $inspection->id)),
            'tree_form' => $this->getJson(route('trees.evidences.create', $this->tree->id)),
            'inspection_form' => $this->getJson(route('inspections.evidences.create', $inspection->id)),
            'show' => $this->getJson(route('evidences.show', $evidence->id)),
            'view' => $this->getJson(route('evidences.view', $evidence->id)),
            'download' => $this->getJson(route('evidences.download', $evidence->id)),
            'change_type' => $this->putJson(route('evidences.update', $evidence->id), [
                'lock_version' => 1, 'evidence_type_id' => $this->evidenceType('EVD_CROWN')->id,
            ]),
            'void' => $this->deleteJson(route('evidences.void', $evidence->id), ['lock_version' => 1]),
            'restore' => $this->postJson(route('evidences.restore', $evidence->id), ['lock_version' => 1]),
        };
        $response->assertNotFound();
        $this->assertSafeError($response, $evidence);
        $this->assertSame(1, $evidence->fresh()->lock_version);
        $this->assertDatabaseCount('evidences', 1);
        $this->assertDatabaseCount('evidence_revisions', 1);
        Storage::disk('evidences')->assertExists($evidence->path);
    }

    public static function foreignActions(): array
    {
        return array_map(fn ($action) => [$action], [
            'tree_upload', 'inspection_upload', 'tree_gallery', 'inspection_gallery', 'tree_form', 'inspection_form',
            'show', 'view', 'download', 'change_type', 'void', 'restore',
        ]);
    }

    public function test_private_evidence_has_no_alternate_storage_route(): void
    {
        $evidence = $this->attachEvidence();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('storage.local'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('storage.evidences'));
        $response = $this->getJson('/storage/evidences/'.$evidence->path)->assertNotFound();
        $this->assertSafeError($response);
        $this->assertNotSame(Storage::disk('evidences')->get($evidence->path), $response->getContent());
        $this->getJson(route('evidences.view', $evidence->id))->assertUnauthorized();
        Storage::disk('evidences')->assertExists($evidence->path);
        $this->assertNull($evidence->fresh()->deleted_at);
    }

    public function test_private_view_and_download_deliver_real_bytes_with_defensive_headers(): void
    {
        $evidence = $this->attachEvidence();
        $bytes = Storage::disk('evidences')->get($evidence->path);
        $this->actingTenant($this->admin, $this->organization);
        foreach (['view' => 'inline', 'download' => 'attachment'] as $route => $disposition) {
            $response = $this->get(route('evidences.'.$route, $evidence->id))->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg')
                ->assertHeader('Content-Length', (string) strlen($bytes))
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                ->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertFalse($response->headers->hasCacheControlDirective('public'));
            $this->assertFalse($response->headers->has('ETag'));
            $this->assertSame($disposition.'; filename="'.$evidence->id.'.jpg"', $response->headers->get('Content-Disposition'));
            $this->assertSame(hash('sha256', $bytes), hash('sha256', $response->streamedContent()));
        }
    }

    public function test_file_delivery_never_discloses_private_paths_when_original_is_missing(): void
    {
        $evidence = $this->attachEvidence();
        Storage::disk('evidences')->delete($evidence->path);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->getJson(route('evidences.view', $evidence->id))->assertNotFound()
            ->assertJsonPath('message', 'La evidencia o su propietario no está disponible.');
        $this->assertSafeError($response, $evidence);
    }

    public function test_anonymous_browser_and_json_clients_cannot_retrieve_files(): void
    {
        $evidence = $this->attachEvidence();
        $this->get(route('evidences.view', $evidence->id))->assertRedirect(route('login'));
        $response = $this->getJson(route('evidences.download', $evidence->id))->assertUnauthorized();
        $this->assertSafeError($response, $evidence);
    }

    public function test_multiple_http_uploads_keep_their_individual_types_and_safe_response(): void
    {
        $inspection = $this->capture();
        $payload = $this->evidenceInput(['evidence_type_id' => $this->evidenceType('EVD_FAUNA')->id]);
        $payload['items'][] = [
            'file' => UploadedFile::fake()->image('individuo.png', 32, 32),
            'evidence_type_id' => $this->evidenceType('EVD_WHOLE_TREE')->id,
            'caption' => 'Vista del individuo en esta inspección',
        ];
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->postJson(route('inspections.evidences.store', $inspection->id), $payload)
            ->assertCreated()->assertJsonStructure(['message', 'redirect']);
        $this->assertSame(['message', 'redirect'], array_keys($response->json()));
        $this->assertSame(route('inspections.show', $inspection->id), $response->json('redirect'));
        $records = Evidence::query()->get();
        $this->assertCount(2, $records);
        $this->assertEqualsCanonicalizing([
            $this->evidenceType('EVD_FAUNA')->id, $this->evidenceType('EVD_WHOLE_TREE')->id,
        ], $records->pluck('evidence_type_id')->all());
        foreach ($records as $evidence) {
            $this->assertNull($evidence->tree_id);
            $this->assertSame($inspection->id, $evidence->tree_inspection_id);
            Storage::disk('evidences')->assertExists($evidence->path);
            $response->assertDontSee($evidence->path, false);
        }
    }

    #[DataProvider('invalidUploads')]
    public function test_http_rejects_invalid_file_or_type_before_writing_any_evidence(string $kind): void
    {
        $payload = $this->evidenceInput();
        $error = 'items.0.file';
        switch ($kind) {
            case 'missing_type':
                unset($payload['items'][0]['evidence_type_id']);
                $error = 'items.0.evidence_type_id';
                break;
            case 'inactive_type':
                $this->evidenceType('EVD_WHOLE_TREE')->update(['active' => false]);
                $error = 'items.0.evidence_type_id';
                break;
            case 'mime':
                $payload['items'][0]['file'] = UploadedFile::fake()->createWithContent('falsa.jpg', '<?php echo "No es una fotografía";');
                break;
            case 'corrupt':
                $payload['items'][0]['file'] = UploadedFile::fake()->createWithContent('rota.png', "\x89PNG\r\n\x1A\nfragmento");
                break;
            case 'oversized':
                $payload['items'][0]['file'] = UploadedFile::fake()->image('grande.jpg')->size((int) config('evidence.max_size_mb') * 1024 + 1);
                break;
            case 'double_extension':
                $payload['items'][0]['file'] = UploadedFile::fake()->image('foto.php.jpg', 32, 32);
                break;
            case 'second_file_invalid':
                $payload['items'][] = array_replace($payload['items'][0], ['file' => UploadedFile::fake()->createWithContent('segunda.jpg', 'texto')]);
                $error = 'items.1.file';
                break;
        }
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->postJson(route('trees.evidences.store', $this->tree->id), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSafeError($response);
        $this->assertDatabaseCount('evidences', 0);
        $this->assertDatabaseCount('evidence_revisions', 0);
        $this->assertSame([], Storage::disk('evidences')->allFiles());
    }

    public static function invalidUploads(): array
    {
        return array_map(fn ($value) => [$value], [
            'missing_type', 'inactive_type', 'mime', 'corrupt', 'oversized', 'double_extension', 'second_file_invalid',
        ]);
    }

    public function test_stale_http_metadata_edit_preserves_the_first_change(): void
    {
        $evidence = $this->attachEvidence();
        $this->actingTenant($this->admin, $this->organization);
        $first = ['lock_version' => 1, 'evidence_type_id' => $this->evidenceType('EVD_CROWN')->id, 'caption' => 'Cambio confirmado primero'];
        $this->putJson(route('evidences.update', $evidence->id), $first)->assertRedirect();
        $response = $this->putJson(route('evidences.update', $evidence->id), array_replace($first, ['caption' => 'Cambio obsoleto']))
            ->assertStatus(409)->assertJsonPath('message', 'La evidencia fue modificada por otro usuario.');
        $this->assertSafeError($response, $evidence);
        $this->assertSame('Cambio confirmado primero', $evidence->fresh()->caption);
        $this->assertSame(2, $evidence->fresh()->lock_version);
        $this->assertDatabaseCount('evidence_revisions', 2);
    }

    public function test_real_csrf_rejects_missing_token_and_accepts_the_form_token(): void
    {
        // Se activa la protección real, retirando únicamente la excepción especial de PHPUnit.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingTenant($this->admin, $this->organization);
        $form = $this->get(route('trees.evidences.create', $this->tree->id))->assertOk();
        preg_match('/name="_token"\s+value="([^"]+)"/', $form->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches, 'El formulario requiere un token CSRF real.');
        $this->postJson(route('trees.evidences.store', $this->tree->id), $this->evidenceInput())->assertStatus(419);
        $this->assertDatabaseCount('evidences', 0);
        $payload = $this->evidenceInput();
        $payload['_token'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $this->postJson(route('trees.evidences.store', $this->tree->id), $payload)->assertCreated();
        $this->assertDatabaseCount('evidences', 1);
    }

    private function assertSafeError(TestResponse $response, ?Evidence $evidence = null): void
    {
        foreach (['trace', 'exception', 'file', 'line'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json());
        }
        $decoded = json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['/var/www/', '/home/josev/', 'SQLSTATE', 'Stack trace'] as $private) {
            $this->assertStringNotContainsString($private, $decoded);
        }
        if ($evidence !== null) {
            $this->assertStringNotContainsString($evidence->path, $decoded);
        }
    }
}

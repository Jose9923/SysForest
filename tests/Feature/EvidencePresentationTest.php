<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\EvidenceType;
use App\Models\TreeInspection;
use App\Models\User;
use App\Services\EvidenceService;
use Database\Seeders\EvidenceTypeSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\InspectionTestCase;

/** Verifica contratos Blade con imágenes sintéticas, sin exponer archivos o credenciales operativas. */
class EvidencePresentationTest extends InspectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EvidenceTypeSeeder::class);
        Storage::fake((string) config('evidence.disk'));
    }

    public function test_upload_form_has_single_type_per_file_camera_fallback_and_private_owner_context(): void
    {
        $inspection = $this->capture();
        $this->actingTenant($this->admin, $this->organization);
        foreach ([route('trees.evidences.create', $this->tree->id), route('inspections.evidences.create', $inspection->id)] as $url) {
            $response = $this->get($url)->assertOk()->assertViewIs('evidences.upload')
                ->assertSee('Cada fotografía tendrá un único tipo de evidencia.')
                ->assertSee('Tomar fotografía')->assertSee('Fecha y hora de captura (UTC)');
            $xpath = $this->document($response->getContent());
            $this->assertSame(1, $xpath->query('//form[@enctype="multipart/form-data" and @data-evidence-upload]')->length);
            $this->assertSame(1, $xpath->query('//input[@name="items[0][file]" and @type="file"]')->length);
            $this->assertSame(1, $xpath->query('//select[@name="items[0][evidence_type_id]" and not(@multiple)]')->length);
            $this->assertSame(12, $xpath->query('//select[@name="items[0][evidence_type_id]"]/option[@value!=""]')->length);
            $this->assertSame(1, $xpath->query('//template//select[@data-upload-field="evidence_type_id" and not(@multiple)]')->length);
            $this->assertSame(1, $xpath->query('//input[@capture="environment"]')->length);
            $this->assertSame(0, $xpath->query('//input[@name="organization_id" or @name="tree_id" or @name="tree_inspection_id" or @name="path" or @name="disk"]')->length);
            $this->assertSame(1, $xpath->query('//template//*[@data-upload-remove]')->length);
        }
        $this->snapshot('evidence-upload', $response);
    }

    public function test_multiple_upload_uses_individual_types_and_returns_owner_redirect(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $this->post(route('trees.evidences.store', $this->tree->id), ['items' => [
            ['file' => $this->image('perfil.png'), 'evidence_type_id' => $this->type('EVD_WHOLE_TREE'), 'caption' => 'Perfil permanente'],
            ['file' => $this->image('fuste.png'), 'evidence_type_id' => $this->type('EVD_STEM'), 'caption' => 'Detalle del fuste'],
        ]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('redirect', route('trees.show', $this->tree->id));
        $records = Evidence::query()->where('tree_id', $this->tree->id)->get();
        $this->assertCount(2, $records);
        $response = $this->get(route('trees.evidences.index', $this->tree->id))->assertOk()
            ->assertSee('Perfil permanente')->assertSee('Detalle del fuste')->assertSee('Individuo completo')->assertSee('Fuste');
        foreach ($records as $record) {
            $response->assertSee(route('evidences.view', $record->id), false)->assertDontSee($record->path, false);
        }
        $this->snapshot('evidence-index', $response);
    }

    public function test_tree_and_latest_inspection_galleries_do_not_duplicate_or_mix_owners(): void
    {
        $older = $this->capture(['inspection_date' => '2026-03-01']);
        $latest = $this->capture(['inspection_date' => '2026-09-12']);
        $profile = $this->evidence(['caption' => 'PERFIL-PERMANENTE']);
        $oldPhoto = $this->evidence(['caption' => 'VISITA-ANTIGUA'], $older);
        $visitPhoto = $this->evidence(['caption' => 'FAUNA-VISITA-RECIENTE', 'evidence_type_id' => $this->type('EVD_FAUNA')], $latest);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.show', $this->tree->id))->assertOk()
            ->assertSee('Fotografías del árbol')->assertSee('Fotografías de la inspección')
            ->assertSee('PERFIL-PERMANENTE')->assertSee('FAUNA-VISITA-RECIENTE')->assertDontSee('VISITA-ANTIGUA');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//section[@id="evidence-gallery-'.$this->tree->id.'"]//article[@data-evidence-id="'.$profile->id.'"]')->length);
        $this->assertSame(1, $xpath->query('//section[@id="evidence-gallery-'.$latest->id.'"]//article[@data-evidence-id="'.$visitPhoto->id.'"]')->length);
        $this->assertSame(0, $xpath->query('//section[@id="evidence-gallery-'.$this->tree->id.'"]//article[@data-evidence-id="'.$visitPhoto->id.'"]')->length);
        $this->get(route('inspections.show', $older->id))->assertOk()->assertSee('VISITA-ANTIGUA')
            ->assertDontSee('PERFIL-PERMANENTE')->assertDontSee('FAUNA-VISITA-RECIENTE');
        $this->assertNotSame($oldPhoto->id, $visitPhoto->id);
    }

    public function test_detail_preserves_zero_and_unknown_capture_and_uses_only_controlled_image_urls(): void
    {
        $record = $this->evidence(['caption' => '0', 'notes' => '0']);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('evidences.show', $record->id))->assertOk()->assertViewIs('evidences.show')
            ->assertSee('No informada')->assertSee('image/png')->assertSee('48 × 36 px')
            ->assertSee('Huella técnica del archivo')->assertSee('Fotografía adjuntada')
            ->assertDontSee('Sin descripción')->assertDontSee('Sin notas registradas.')
            ->assertDontSee($record->path, false)->assertDontSee('/storage/app', false);
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//img[@src="'.route('evidences.view', $record->id).'"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="captured_at" and @value=""]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="_method" and @value="PUT"]')->length);
        $this->assertSame(0, $xpath->query('//input[@type="file"]')->length);
        $this->snapshot('evidence-show', $response);
    }

    public function test_captions_notes_and_filename_are_escaped_in_detail_and_gallery(): void
    {
        $unsafe = '<img src=x onerror=alert("EVIDENCE-XSS")>';
        $record = $this->evidence(['caption' => $unsafe, 'notes' => $unsafe], fileName: 'foto-<img onerror=alert(1)>.png');
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('evidences.show', $record->id))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        $this->get(route('trees.evidences.index', $this->tree->id))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
    }

    public function test_inactive_type_remains_visible_for_historical_evidence_but_is_not_offered_for_new_uploads(): void
    {
        $record = $this->evidence(['evidence_type_id' => $this->type('EVD_STEM'), 'captured_at' => '2026-09-11T14:35']);
        EvidenceType::query()->whereKey($record->evidence_type_id)->update(['active' => false]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('evidences.show', $record->id))->assertOk()->assertSee('Tipo histórico inactivo');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="evidence_type_id"]/option[@value="'.$record->evidence_type_id.'" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="captured_at" and @value="2026-09-11T14:35"]')->length);
        $create = $this->get(route('trees.evidences.create', $this->tree->id))->assertOk();
        $this->assertSame(0, $this->document($create->getContent())->query('//option[@value="'.$record->evidence_type_id.'"]')->length);
        $this->get(route('trees.evidences.index', [$this->tree->id, 'evidence_type_id' => $record->evidence_type_id]))
            ->assertOk()->assertSee('Tipo histórico inactivo');
    }

    public function test_voided_evidence_disappears_from_normal_gallery_and_restore_action_is_authorized(): void
    {
        $record = $this->evidence(['caption' => 'ANULADA-UI']);
        app(EvidenceService::class)->void($this->admin, $record->id, ['lock_version' => 1, 'deletion_notes' => 'Carga errónea']);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('trees.evidences.index', $this->tree->id))->assertOk()->assertDontSee('ANULADA-UI');
        $response = $this->get(route('trees.evidences.index', [$this->tree->id, 'trashed' => 'only']))
            ->assertOk()->assertSee('ANULADA-UI')->assertSee('Restaurar');
        $detail = $this->get(route('evidences.show', $record->id))->assertOk()
            ->assertSee('Esta evidencia está anulada')->assertSee('Carga errónea')->assertSee('Restaurar evidencia')
            ->assertDontSee('Guardar datos')->assertDontSee('Anular evidencia');
        $this->assertSame(1, $this->document($detail->getContent())->query('//form[@action="'.route('evidences.restore', $record->id).'"]')->length);
        Storage::disk((string) config('evidence.disk'))->assertExists($record->path);
    }

    public function test_conflicting_metadata_edit_uses_specific_safe_message(): void
    {
        $record = $this->evidence(['caption' => 'Original']);
        app(EvidenceService::class)->update($this->admin, $record->id, ['lock_version' => 1, 'caption' => 'Corrección vigente']);
        $this->actingTenant($this->admin, $this->organization);
        $this->put(route('evidences.update', $record->id), ['lock_version' => 1,
            'evidence_type_id' => $record->evidence_type_id, 'caption' => 'Edición obsoleta',
        ])->assertStatus(409)->assertSee('La evidencia fue modificada por otro usuario.')
            ->assertDontSee($record->path, false);
        $this->assertSame('Corrección vigente', $record->fresh()->caption);
    }

    public function test_collaborating_official_can_edit_metadata_without_seeing_restore_controls(): void
    {
        $record = $this->evidence(['caption' => 'Autoría compartida']);
        $official = User::factory()->create();
        $this->member($official, $this->organization);
        $this->actingTenant($official, $this->organization);
        $this->get(route('evidences.show', $record->id))->assertOk()
            ->assertSee('Guardar datos')->assertSee('Anular evidencia')->assertDontSee('Restaurar evidencia');
        $index = $this->get(route('trees.evidences.index', $this->tree->id))->assertOk()->assertSee('Agregar fotografías');
        $this->assertSame(0, $this->document($index->getContent())->query('//select[@name="trashed"]')->length);
    }

    public function test_views_render_without_lazy_loaded_relationships(): void
    {
        $inspection = $this->capture();
        $record = $this->evidence(['caption' => 'Vista general']);
        $this->evidence(['evidence_type_id' => $this->type('EVD_FAUNA')], $inspection);
        $this->actingTenant($this->admin, $this->organization);
        Model::preventLazyLoading();
        try {
            foreach ([route('trees.show', $this->tree->id), route('inspections.show', $inspection->id),
                route('evidences.show', $record->id), route('trees.evidences.index', $this->tree->id),
                route('inspections.evidences.index', $inspection->id), route('trees.evidences.create', $this->tree->id)] as $url) {
                $this->get($url)->assertOk();
            }
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    private function evidence(array $attributes = [], ?TreeInspection $inspection = null, string $fileName = 'evidencia.png'): Evidence
    {
        return app(EvidenceService::class)->upload($this->admin, $inspection ? null : $this->tree->id, $inspection?->id, [
            'items' => [array_replace(['file' => $this->image($fileName), 'evidence_type_id' => $this->type('EVD_WHOLE_TREE')], $attributes)],
        ])->sole();
    }

    private function type(string $code): string
    {
        return EvidenceType::query()->where('code', $code)->sole()->id;
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 48, 36);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    /** Exporta solo fixtures sintéticos: neutraliza acciones, credenciales y rutas privadas de imagen. */
    private function snapshot(string $name, TestResponse $response): void
    {
        if (getenv('SYSFOREST_VISUAL_SNAPSHOTS') !== '1') {
            return;
        }
        $xpath = $this->document($response->getContent());
        foreach ($xpath->query('//input[@name="_token"] | //meta[@name="csrf-token"]') as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('//form') as $form) {
            $form->setAttribute('action', '#');
            $form->setAttribute('onsubmit', 'return false');
            $form->removeAttribute('data-evidence-upload');
        }
        foreach ($xpath->query('//button[not(@type) or @type="submit"]') as $button) {
            $button->setAttribute('type', 'button');
        }
        $previewImage = $this->image('preview.png');
        $previewUrl = 'data:image/png;base64,'.base64_encode(file_get_contents($previewImage->getRealPath()));
        foreach ($xpath->query('//img[@data-evidence-image]') as $image) {
            $image->setAttribute('src', $previewUrl);
        }
        $directory = '/tmp/sysforest-phase8-visual';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $html = $xpath->document->saveHTML();
        $this->assertStringNotContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('data-evidence-upload', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\MaturityState;
use App\Repositories\ImportRowRepository;
use App\Services\ImportService;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\ImportTestCase;

/** Las rutas exigen sesión, tenant y una confirmación explícita antes de delegar escrituras. */
class ImportHttpTest extends ImportTestCase
{
    public function test_guest_cannot_open_the_import_module_or_download_the_template(): void
    {
        foreach ([route('imports.index'), route('imports.create'), route('imports.template')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_form_lists_only_available_inventories_from_the_active_organization(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('imports.create'))->assertOk()->assertViewIs('imports.create')
            ->assertSee($this->inventory->code)->assertSee($this->organization->name)
            ->assertSee('Cargar y revisar archivo')->assertSee('Descargar plantilla oficial');
        $this->get(route('imports.index'))->assertOk()->assertViewIs('imports.index')
            ->assertSee('Aún no hay lotes de importación.');
    }

    public function test_upload_form_rejects_missing_fields_and_untrusted_ownership(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $this->mock(ImportService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('upload');
        });
        $this->postJson(route('imports.store'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'inventory_id']);
        $this->postJson(route('imports.store'), [
            'file' => UploadedFile::fake()->create('plantilla.xlsx', 4), 'inventory_id' => $this->inventory->id,
            'organization_id' => $this->organization->id, 'created_by' => $this->admin->id,
            'path' => 'storage/archivo-ajeno.xlsx', 'disk' => 'public',
        ])->assertUnprocessable()->assertJsonValidationErrors(['organization_id', 'created_by', 'path', 'disk']);
    }

    public function test_upload_form_rejects_other_extensions_and_files_above_the_limit(): void
    {
        config(['imports.max_upload_kb' => 5]);
        $this->actingTenant($this->admin, $this->organization);
        $this->mock(ImportService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('upload');
        });
        foreach ([UploadedFile::fake()->create('datos.csv', 1), UploadedFile::fake()->create('datos.xlsx', 6)] as $file) {
            $this->postJson(route('imports.store'), ['file' => $file, 'inventory_id' => $this->inventory->id])
                ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        }
    }

    public function test_validated_upload_redirects_to_the_review_without_confirming_it(): void
    {
        $batch = $this->batch();
        $this->actingTenant($this->admin, $this->organization);
        $this->mock(ImportService::class, function (MockInterface $mock) use ($batch): void {
            $mock->shouldReceive('upload')->once()->withArgs(fn ($actor, $file, $inventoryId): bool =>
                $actor->id === $this->admin->id && $file instanceof UploadedFile && $inventoryId === $this->inventory->id)
                ->andReturn($batch);
            $mock->shouldNotReceive('confirm');
        });
        $this->post(route('imports.store'), [
            'file' => UploadedFile::fake()->create('plantilla.xlsx', 4), 'inventory_id' => $this->inventory->id,
        ])->assertRedirect(route('imports.show', $batch->id))->assertSessionHas('status');
    }

    public function test_confirmation_rejects_missing_consent_or_a_replacement_of_the_reviewed_payload(): void
    {
        $batch = $this->batch();
        $this->actingTenant($this->admin, $this->organization);
        $this->mock(ImportService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('confirm');
        });
        $this->postJson(route('imports.confirm', $batch->id), ['lock_version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);
        $this->postJson(route('imports.confirm', $batch->id), ['confirmation' => '0', 'lock_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['confirmation']);
        $this->postJson(route('imports.confirm', $batch->id), [
            'confirmation' => '1', 'lock_version' => 1, 'inventory_id' => $this->inventory->id,
            'normalized_values' => ['tree' => ['current_status' => 'dead']], 'rows' => [['code' => 'CAMBIADO']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['inventory_id', 'normalized_values', 'rows']);
    }

    public function test_confirmation_delegates_only_actor_batch_and_reviewed_version(): void
    {
        $batch = $this->batch();
        $this->actingTenant($this->admin, $this->organization);
        $this->mock(ImportService::class, function (MockInterface $mock) use ($batch): void {
            $mock->shouldReceive('confirm')->once()->withArgs(fn ($actor, $id, $version): bool =>
                $actor->id === $this->admin->id && $id === $batch->id && $version === 3)->andReturn($batch);
        });
        $this->post(route('imports.confirm', $batch->id), ['confirmation' => '1', 'lock_version' => '3'])
            ->assertRedirect(route('imports.show', $batch->id));
    }

    public function test_cancel_requires_a_version_and_never_accepts_another_inventory(): void
    {
        $batch = $this->batch();
        $this->actingTenant($this->admin, $this->organization);
        $this->mock(ImportService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('cancel');
        });
        $this->postJson(route('imports.cancel', $batch->id), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['lock_version']);
        $this->postJson(route('imports.cancel', $batch->id), ['lock_version' => 1, 'inventory_id' => $this->inventory->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['inventory_id']);
    }

    public function test_real_preview_displays_calculations_and_maturity_without_internal_identifiers(): void
    {
        $batch = app(ImportService::class)->upload($this->admin, $this->workbook([$this->importInput()]), $this->inventory->id);
        $row = app(ImportRowRepository::class)->all($batch)->sole();
        $original = $row->normalized_values;
        $calculations = $original['calculations'];
        $maturity = MaturityState::query()->findOrFail($calculations['maturity_state_id']);
        // Una revisión conserva la etiqueta histórica aun si el catálogo ya no permite nuevas capturas.
        $maturity->forceFill(['active' => false])->save();
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('imports.show', $batch->id))->assertOk()->assertViewIs('imports.show')
            ->assertSee($calculations['dap_m'])->assertSee($maturity->name)
            ->assertSee('DAP (m)')->assertSee('Radio (m)')->assertSee('Área basal (m²)')
            ->assertSee('Volumen comercial (m³)')->assertSee('Volumen total (m³)')
            ->assertSee('Clase diamétrica')->assertSee('Clase de altura')->assertSee('Estado de madurez')
            ->assertDontSee('maturity_state_id')->assertDontSee('classification_rule_version_id')
            ->assertDontSee('calculation_rule_version')->assertDontSee($calculations['maturity_state_id'])
            ->assertDontSee($calculations['classification_rule_version_id'])->assertDontSee($calculations['calculation_rule_version']);
        $fields = $response->viewData('calculatedFields')[$row->row_number];
        $this->assertCount(8, $fields);
        $this->assertSame($calculations['dap_m'], $fields[0]['value']);
        $this->assertSame($maturity->name, $fields[7]['value']);
        $this->assertSame($original, $row->fresh()->normalized_values);
    }

    private function batch(): ImportBatch
    {
        return (new ImportBatch())->forceFill(['id' => 'd7f8c7ae-1e12-4889-b2d8-3f13f462e53c']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\FaunaCategory;
use App\Models\Inventory;
use App\Models\InventoryTree;
use App\Models\Organization;
use App\Models\Species;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Services\TreeInspectionService;
use App\Services\TreeService;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\ConditionCatalogSeeder;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Database\Seeders\TaxonomySeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Tests\PostgresTestCase;

class InspectionPresentationTest extends PostgresTestCase
{
    private Organization $organization;

    private User $admin;

    private Inventory $inventory;

    private Tree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->pilot();
        $this->admin = User::factory()->create(['name' => 'Responsable de inspección']);
        $this->member($this->admin, $this->organization);
        $this->allowManagement($this->admin);
        $this->seed([ForestryCatalogSeeder::class, MaintenanceCatalogSeeder::class, TaxonomySeeder::class,
            ConditionCatalogSeeder::class, ClassificationRuleSeeder::class]);
        app(TenantContextService::class)->activate($this->admin, $this->organization->id);
        $this->inventory = app(InventoryService::class)->create($this->admin, [
            'code' => 'INSPECCION-2026', 'name' => 'Reconocimiento forestal del campus', 'status' => 'active', 'active' => true,
        ]);
        $this->tree = app(TreeService::class)->create($this->admin, [
            'inventory_ids' => [$this->inventory->id], 'current_status' => 'active',
            'species_id' => Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole()->id,
            'coordinate_raw' => '4.149168, -73.628504',
        ]);
    }

    public function test_form_has_seven_sections_65_distinct_conditions_and_only_observed_measurement_inputs(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.inspections.create', $this->tree->id))->assertOk()
            ->assertViewIs('inspections.form')->assertSee($this->tree->code)
            ->assertSee('Calculado automáticamente')->assertSee('Sin campaña formal')
            ->assertSee('Copa X');
        $xpath = $this->document($response->getContent());
        $this->assertSame(7, $xpath->query('//section[contains(@class,"inspection-section")]')->length);
        $this->assertSame(23, $xpath->query('//input[@name="physical_condition_ids[]"]')->length);
        $this->assertSame(42, $xpath->query('//input[@name="phytosanitary_condition_ids[]"]')->length);
        $this->assertSame(6, $xpath->query('//input[@name="fauna_category_ids[]"]')->length);
        $this->assertSame(7, $xpath->query('//input[@data-dendrometry-input]')->length);
        $ids = [];
        foreach ($xpath->query('//input[@name="physical_condition_ids[]" or @name="phytosanitary_condition_ids[]"]') as $node) {
            $ids[] = $node->getAttribute('value');
        }
        $this->assertCount(65, array_unique($ids));
        foreach (['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3',
            'diameter_class_number', 'height_class_number', 'maturity_state_id', 'tree_id', 'organization_id', 'lock_version'] as $field) {
            $this->assertSame(0, $xpath->query('//input[@name="'.$field.'"]')->length, $field);
        }
        $this->assertSame(0, $xpath->query('//input[@type="file"]')->length);
        $response->assertSee('name="flowering_date"', false)->assertSee('name="fruiting_date"', false)
            ->assertSee('name="flowering_development"', false)->assertSee('name="fruiting_development"', false)
            ->assertSee('name="fauna_observations"', false)->assertSee('name="general_observations"', false);
        $this->snapshot('inspection-form', $response);
    }

    public function test_store_roundtrip_renders_measurements_phenology_and_multiple_observations(): void
    {
        $payload = $this->input([
            'physical_condition_ids' => [$this->condition('PHY_ROOT_GOOD_ANCHORAGE')],
            'phytosanitary_condition_ids' => [$this->condition('PHT_FOLIAGE_HEALTHY'), $this->condition('PHT_FOLIAGE_CHLOROSIS')],
            'fauna_category_ids' => [FaunaCategory::query()->where('code', 'FAU_BIRDS')->value('id')],
            'flowering_date' => '2026-09-10', 'flowering_development' => 'Botones y flores abiertas observados',
            'fruiting_date' => null, 'fruiting_development' => 'Frutos observados sin fecha confirmada',
            'fauna_observations' => 'Aves posadas en la copa', 'general_observations' => 'Visita de control institucional',
        ]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->post(route('trees.inspections.store', $this->tree->id), $payload)->assertRedirect();
        $inspection = TreeInspection::query()->where('tree_id', $this->tree->id)->sole();
        $response->assertRedirect(route('inspections.show', $inspection->id));
        $show = $this->get(route('inspections.show', $inspection->id))->assertOk()
            ->assertSee('Fustal sobremaduro')->assertSee('EXCEL_V1')
            ->assertSee('Anclaje bueno')->assertSee('Sano')->assertSee('Clorosis')
            ->assertSee('Botones y flores abiertas observados')->assertSee('Frutos observados sin fecha confirmada')
            ->assertSee('Aves posadas en la copa')->assertSee('Visita de control institucional')
            ->assertSee('Reconocimiento forestal del campus');
        $this->assertSame('active', $this->tree->fresh()->current_status->value);
        $this->snapshot('inspection-show', $show);
    }

    public function test_edit_preserves_selected_inactive_conditions_and_fauna_and_can_clear_all_checkboxes(): void
    {
        $conditionId = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $faunaId = FaunaCategory::query()->where('code', 'FAU_BIRDS')->value('id');
        $inspection = $this->inspection(['phytosanitary_condition_ids' => [$conditionId], 'fauna_category_ids' => [$faunaId]]);
        Condition::query()->whereKey($conditionId)->update(['active' => false]);
        FaunaCategory::query()->whereKey($faunaId)->update(['active' => false]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('inspections.edit', $inspection->id))->assertOk()->assertSee('Referencia histórica inactiva');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//input[@name="phytosanitary_condition_ids[]" and @value="'.$conditionId.'" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="fauna_category_ids[]" and @value="'.$faunaId.'" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="lock_version" and @value="1"]')->length);
        $this->put(route('inspections.update', $inspection->id), [
            'inspection_date' => '2026-09-12', 'lock_version' => 1,
        ])->assertRedirect(route('inspections.show', $inspection->id));
        $this->assertSame(0, $inspection->conditions()->count());
        $this->assertSame(0, $inspection->faunaCategories()->count());
        $this->get(route('inspections.show', $inspection->id))->assertOk()->assertSee('Sin condiciones registradas en esta evaluación.');
    }

    public function test_show_escapes_phenology_fauna_and_observation_content(): void
    {
        $unsafe = '<img src=x onerror=alert("INSPECTION-XSS")>';
        $inspection = $this->inspection(['flowering_development' => $unsafe, 'fruiting_development' => $unsafe,
            'fauna_observations' => $unsafe, 'general_observations' => $unsafe]);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('inspections.show', $inspection->id))->assertOk()
            ->assertSee($unsafe)->assertDontSee($unsafe, false)->assertSee('Versión 1')
            ->assertSee('Responsable de inspección');
        $this->get(route('inspections.edit', $inspection->id))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
    }

    public function test_optional_text_preserves_literal_zero_and_form_supports_an_empty_assessment_catalog(): void
    {
        $inspection = $this->inspection(['flowering_development' => '0', 'fruiting_development' => '0',
            'fauna_observations' => '0', 'general_observations' => '0']);
        Condition::query()->where('assessment_type', 'physical_mechanical')->update(['active' => false]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('inspections.show', $inspection->id))->assertOk()
            ->assertDontSee('Sin descripción de fauna registrada.')
            ->assertDontSee('Sin observaciones generales registradas.');
        $xpath = $this->document($response->getContent());
        $this->assertGreaterThanOrEqual(2, $xpath->query('//dd[normalize-space(.)="0"]')->length);
        $this->get(route('trees.inspections.create', $this->tree->id))->assertOk()
            ->assertSee('Condiciones físicas observadas')->assertSee('Condiciones fitosanitarias observadas');
    }

    public function test_index_combines_conditions_from_the_same_inspection_and_keeps_filter_fields(): void
    {
        $first = $this->condition('PHT_STEM_FUNGI');
        $second = $this->condition('PHT_FOLIAGE_CHLOROSIS');
        $match = $this->inspection(['inspection_date' => '2026-09-12', 'phytosanitary_condition_ids' => [$first, $second]]);
        $this->inspection(['inspection_date' => '2026-03-10', 'phytosanitary_condition_ids' => [$first]]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('inspections.index', [
            'tree_id' => $this->tree->id, 'inventory_id' => $this->inventory->id,
            'phytosanitary_condition_ids' => [$first, $second], 'condition_mode' => 'all',
        ]))->assertOk()->assertViewIs('inspections.index')->assertSee('1 inspección encontrada')
            ->assertSee(route('inspections.show', $match->id), false)
            ->assertSee('Todas en la misma inspección')->assertSee('name="cap_cm_min"', false)
            ->assertSee('name="dap_m_max"', false)->assertSee('name="total_height_m_max"', false)
            ->assertSee('name="height_class_number"', false);
        $this->snapshot('inspection-index', $response);
    }

    public function test_tree_summary_and_timeline_display_latest_visit_without_replacing_permanent_state(): void
    {
        $first = $this->inspection(['inspection_date' => '2026-03-10', 'cap_cm' => '80']);
        $latest = $this->inspection(['inspection_date' => '2026-09-12', 'cap_cm' => '154',
            'phytosanitary_condition_ids' => [$this->condition('PHT_FOLIAGE_CHLOROSIS')]]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.show', $this->tree->id))->assertOk()
            ->assertSee('Última inspección')->assertSee('Clorosis')->assertSee('Fustal sobremaduro')
            ->assertSee(route('trees.inspections.create', $this->tree->id), false)
            ->assertSee(route('inspections.show', $first->id), false)
            ->assertSee(route('inspections.show', $latest->id), false)->assertSee('Activo');
        $xpath = $this->document($response->getContent());
        $summary = $xpath->query('//section[@id="tree-inspections"]')->item(0)->textContent;
        $this->assertStringContainsString('12/09/2026', $summary);
        $this->assertStringNotContainsString('10/03/2026', $summary);
    }

    public function test_stale_edit_displays_inspection_specific_conflict_message(): void
    {
        $inspection = $this->inspection();
        app(TreeInspectionService::class)->update($this->admin, $inspection->id, [
            'lock_version' => 1, 'general_observations' => 'Corrección ya guardada',
        ]);
        $this->actingTenant($this->admin, $this->organization);
        $this->put(route('inspections.update', $inspection->id), [
            'lock_version' => 1, 'general_observations' => 'Formulario obsoleto',
        ])->assertStatus(409)
            ->assertSee('Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.')
            ->assertSee(route('inspections.index'), false);
        $this->assertSame('Corrección ya guardada', $inspection->fresh()->general_observations);
    }

    public function test_preview_returns_flat_backend_outputs_with_nulls_for_missing_measurements(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $this->postJson(route('dendrometry.preview'), ['cap_cm' => '154'])
            ->assertOk()->assertJsonStructure(['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3',
                'total_volume_m3', 'diameter_class_number', 'height_class_number', 'maturity_name',
                'classification_rule_version_id', 'calculation_rule_version'])
            ->assertJsonPath('diameter_class_number', 10)->assertJsonPath('height_class_number', null)
            ->assertJsonPath('commercial_volume_m3', null)->assertJsonPath('total_volume_m3', null);
        $this->postJson(route('dendrometry.preview'), ['cap_cm' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('cap_cm');
    }

    public function test_read_views_do_not_require_lazy_loaded_relationships(): void
    {
        $inspection = $this->inspection(['phytosanitary_condition_ids' => [$this->condition('PHT_FOLIAGE_CHLOROSIS')]]);
        $this->inspection(['inspection_date' => '2026-09-11']);
        $this->actingTenant($this->admin, $this->organization);
        Model::preventLazyLoading();
        try {
            $this->get(route('inspections.index'))->assertOk();
            $this->get(route('inspections.show', $inspection->id))->assertOk();
            $this->get(route('inspections.edit', $inspection->id))->assertOk();
            $this->get(route('trees.show', $this->tree->id))->assertOk();
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    private function input(array $extra = []): array
    {
        return array_replace(['inspection_date' => '2026-09-12',
            'inventory_tree_id' => InventoryTree::query()->where('tree_id', $this->tree->id)->where('inventory_id', $this->inventory->id)->sole()->id,
            'cap_cm' => '154', 'number_of_stems' => 3, 'form_factor' => '0.5', 'total_height_m' => '12',
            'commercial_height_m' => '0.8', 'crown_x_m' => '8', 'crown_y_m' => '7',
        ], $extra);
    }

    private function inspection(array $extra = []): TreeInspection
    {
        return app(TreeInspectionService::class)->create($this->admin, $this->tree->id, $this->input($extra));
    }

    private function condition(string $code): string
    {
        return Condition::query()->where('code', $code)->sole()->id;
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

    /** Solo exporta fixtures sintéticos y neutraliza credenciales y acciones de escritura. */
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
            $form->removeAttribute('data-dendrometry-form');
            $form->removeAttribute('data-preview-url');
        }
        foreach ($xpath->query('//button[not(@type) or @type="submit"]') as $button) {
            $button->setAttribute('type', 'button');
        }
        $directory = '/tmp/sysforest-phase7-visual';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $html = $xpath->document->saveHTML();
        $this->assertStringNotContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('data-dendrometry-form', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}

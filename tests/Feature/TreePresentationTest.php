<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Species;
use App\Models\Tree;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TenantContextService;
use App\Services\TreeService;
use Database\Seeders\ForestryCatalogSeeder;
use Database\Seeders\MaintenanceCatalogSeeder;
use Database\Seeders\TaxonomySeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Tests\PostgresTestCase;

class TreePresentationTest extends PostgresTestCase
{
    private Organization $organization;

    private User $admin;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->pilot();
        $this->admin = User::factory()->create(['name' => 'Responsable de inventario']);
        $this->member($this->admin, $this->organization);
        $this->allowManagement($this->admin);
        $this->seed([ForestryCatalogSeeder::class, MaintenanceCatalogSeeder::class, TaxonomySeeder::class]);
        app(TenantContextService::class)->activate($this->admin, $this->organization->id);
        $this->inventory = app(InventoryService::class)->create($this->admin, [
            'code' => 'CAMPO-2026', 'name' => 'Reconocimiento del campus', 'status' => 'active', 'active' => true,
        ]);
    }

    public function test_index_renders_real_identity_filters_local_assets_and_navigation(): void
    {
        $tree = $this->tree(['external_number' => 'PLACA-007', 'location_description' => 'Andén del bloque A']);
        $this->actingTenant($this->admin, $this->organization);

        $response = $this->get(route('trees.index', [
            'q' => 'Oití', 'inventory_id' => $this->inventory->id,
            'sort' => 'scientific_name', 'direction' => 'asc',
        ]))->assertOk()->assertViewIs('trees.index')
            ->assertSee($tree->code)->assertSee('PLACA-007')->assertSee('Oití')
            ->assertSee('Licania tomentosa (Benth.) Fritsch.')->assertSee('CHRYSOBALANACEAE')
            ->assertSee('Andén del bloque A')->assertSee('1 árbol encontrado')
            ->assertSee('name="inventory_id"', false)->assertSee('name="species_id"', false)
            ->assertSee('name="placement_id"', false)->assertSee('name="current_status"', false)
            ->assertSee('name="trashed"', false)->assertSee('css/forestry.css', false)
            ->assertSee(route('inventories.index'), false)->assertSee(route('trees.create'), false);
        $this->snapshot('tree-index', $response);
    }

    public function test_create_form_uses_permanent_identity_and_independent_origin_fields(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.create'))->assertOk()->assertViewIs('trees.form')
            ->assertSee('Se asignará al guardar')->assertSee('Reconocimiento del campus')
            ->assertSee('name="inventory_ids[]"', false)->assertSee('name="species_id"', false)
            ->assertSee('name="establishment_origin_id"', false)->assertSee('name="biogeographic_origin_id"', false)
            ->assertSee('name="coordinate_raw"', false)->assertSee('name="datum"', false)
            ->assertSee('name="accuracy_m"', false)->assertSee('name="captured_at_local" type="datetime-local"', false)
            ->assertSee('Fecha y hora de captura (hora de Colombia)')->assertSee('America/Bogota')
            ->assertDontSee('Formato ISO 8601')
            ->assertSee('No informado')->assertSee('vendor/leaflet/leaflet.js', false)
            ->assertSee('data-editable="true"', false)
            ->assertDontSee('name="code"', false)->assertDontSee('name="family_id"', false)
            ->assertDontSee('name="coordinate_format"', false)->assertDontSee('name="lock_version"', false);

        foreach (['dap_m', 'cap_cm', 'total_height_m', 'condition_ids[]', 'fauna', 'maintenance', 'photos[]'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }
        $this->snapshot('tree-form', $response);
    }

    public function test_edit_keeps_campaign_links_and_moves_status_and_coordinates_to_explicit_actions(): void
    {
        $tree = $this->tree(['external_number' => 'PLACA-007']);
        app(InventoryService::class)->create($this->admin, [
            'code' => 'CAMPO-2027', 'name' => 'Seguimiento siguiente', 'status' => 'active', 'active' => true,
        ]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.edit', $tree->id))->assertOk()
            ->assertSee($tree->code)->assertSee('Campañas vinculadas')->assertSee('Reconocimiento del campus')
            ->assertSee('Seguimiento siguiente')->assertSee('name="lock_version" value="1"', false)
            ->assertSee('data-editable="false"', false)
            ->assertDontSee('name="code"', false)->assertDontSee('name="current_status"', false)
            ->assertDontSee('name="coordinate_raw"', false)->assertDontSee('name="datum"', false);
        $document = $this->document($response->getContent());
        $this->assertSame(0, $document->query('//select[@name="inventory_ids[]"]/option[@value="'.$this->inventory->id.'"]')->length);
    }

    public function test_show_escapes_user_content_and_retains_precise_gps_metadata_and_history(): void
    {
        $unsafe = '<script>alert("TREE-XSS")</script>';
        $tree = $this->tree([
            'location_description' => $unsafe, 'external_number' => '<svg/onload=alert(1)>',
            'is_protected' => true, 'is_heritage' => true,
            'captured_at' => '2026-09-12T14:30:15.123456-05:00',
        ]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.show', $tree->id))->assertOk()->assertViewIs('trees.show')
            ->assertSee($tree->code)->assertSee('Protegido')->assertSee('Patrimonial')
            ->assertSee($unsafe)->assertDontSee($unsafe, false)
            ->assertSee('<svg/onload=alert(1)>')->assertDontSee('<svg/onload=alert(1)>', false)
            ->assertSee('12/09/2026 14:30:15.123456 (hora de Colombia)')
            ->assertSee('value="2026-09-12T14:30:15.123"', false)
            ->assertSee('Cambios de estado')->assertSee('Cambios de ubicación')
            ->assertSee('data-editable="false"', false)->assertSee('data-editable="true"', false)
            ->assertSee(route('trees.status', $tree->id), false)
            ->assertSee(route('trees.location', $tree->id), false)
            ->assertSee(route('trees.void', $tree->id), false);
        $this->snapshot('tree-show', $response);
    }

    public function test_status_and_location_actions_render_the_saved_changes_and_stale_conflict(): void
    {
        $tree = $this->tree();
        $this->actingTenant($this->admin, $this->organization);
        $this->patch(route('trees.status', $tree->id), [
            'current_status' => 'under_observation', 'reason' => 'Revisión programada', 'lock_version' => 1,
        ])->assertRedirect(route('trees.show', $tree->id));
        $this->patch(route('trees.location', $tree->id), [
            'coordinate_raw' => '4.150000, -73.630000', 'datum' => 'WGS84',
            'location_change_type' => 'correction', 'location_reason' => 'Punto confirmado en campo', 'lock_version' => 2,
        ])->assertRedirect(route('trees.show', $tree->id));
        $this->get(route('trees.show', $tree->id))->assertOk()
            ->assertSee('En observación')->assertSee('Revisión programada')
            ->assertSee('Punto confirmado en campo')->assertSee('4.150000, -73.630000')
            ->assertSee('name="lock_version" value="3"', false);
        $this->put(route('trees.update', $tree->id), ['external_number' => 'VIEJO', 'lock_version' => 1])
            ->assertStatus(409)->assertSee('Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.');
        $this->assertNull($tree->fresh()->external_number);
    }

    public function test_filters_keep_the_selected_campaign_and_exclude_other_matches(): void
    {
        $first = $this->tree(['external_number' => 'BUSCAR-01']);
        $second = $this->tree(['external_number' => 'DISTINTO-02']);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('trees.index', ['q' => 'BUSCAR-01', 'inventory_id' => $this->inventory->id]))
            ->assertOk()->assertSee($first->code)->assertDontSee($second->code)
            ->assertSee('1 árbol encontrado');
        $this->get(route('trees.index', ['q' => 'NO-COINCIDE']))
            ->assertOk()->assertSee('No hay árboles para mostrar')->assertSee('0 árboles encontrados');
    }

    public function test_pagination_retains_campaign_and_search_filters(): void
    {
        for ($number = 1; $number <= 21; $number++) {
            $this->tree(['external_number' => 'PAGINA-'.$number]);
        }
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.index', [
            'q' => 'PAGINA-', 'inventory_id' => $this->inventory->id,
        ]))->assertOk()->assertSee('Página 1 de 2');
        $document = $this->document($response->getContent());
        $next = $document->query('//nav[@aria-label="Paginación"]/a[contains(., "Siguiente")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $next);
        parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $filters);
        $this->assertSame($this->inventory->id, $filters['inventory_id']);
        $this->assertSame('PAGINA-', $filters['q']);
        $this->assertSame('2', $filters['page']);
        $this->get($next->getAttribute('href'))->assertOk()->assertSee('Página 2 de 2');
    }

    public function test_inventory_pages_render_dates_availability_and_readonly_existing_code(): void
    {
        $this->tree();
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('inventories.index'))->assertOk()
            ->assertSee('Reconocimiento del campus')->assertSee('CAMPO-2026');
        $this->get(route('inventories.show', $this->inventory->id))->assertOk()
            ->assertSee(route('trees.index', ['inventory_id' => $this->inventory->id]), false)
            ->assertSee('árboles en esta campaña');
        $response = $this->get(route('inventories.edit', $this->inventory->id))->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame(1, $document->query('//input[@name="code" and @readonly]')->length);
        $this->assertSame(1, $document->query('//input[@name="lock_version" and @value="1"]')->length);
    }

    public function test_read_pages_use_preloaded_relations_without_lazy_loading_failures(): void
    {
        $tree = $this->tree();
        $this->tree(['external_number' => 'OTRO']);
        $this->actingTenant($this->admin, $this->organization);
        Model::preventLazyLoading();
        try {
            $this->get(route('trees.index'))->assertOk();
            $this->get(route('trees.create'))->assertOk();
            $this->get(route('trees.edit', $tree->id))->assertOk();
            $this->get(route('trees.show', $tree->id))->assertOk();
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    private function tree(array $extra = []): Tree
    {
        return app(TreeService::class)->create($this->admin, array_replace([
            'current_status' => 'active', 'inventory_ids' => [$this->inventory->id],
            'species_id' => Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole()->id,
            'coordinate_raw' => '4.149168, -73.628504',
        ], $extra));
    }

    /** DOM de prueba: se toleran únicamente las advertencias del analizador HTML5. */
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

    /** Captura optativa de fixtures sintéticos, sin credenciales ni acciones ejecutables. */
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
        }
        foreach ($xpath->query('//button[not(@type) or @type="submit"]') as $button) {
            $button->setAttribute('type', 'button');
        }
        foreach ($xpath->query('//*[@data-preview-url]') as $node) {
            if ($node instanceof DOMElement) {
                $node->removeAttribute('data-preview-url');
                $node->setAttribute('data-editable', 'false');
            }
        }
        $directory = '/tmp/sysforest-phase6-visual';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $html = $xpath->document->saveHTML();
        $this->assertStringNotContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('data-preview-url', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TreeStatus;
use App\Models\Permission;
use App\Models\Placement;
use App\Models\Role;
use App\Models\Species;
use App\Services\MapReadService;
use App\Services\TreeService;
use App\Tenancy\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MapTestCase;

/** Presentación real con fixtures transaccionales; las exportaciones visuales son optativas y privadas. */
class MapPresentationTest extends MapTestCase
{
    #[DataProvider('roleScopes')]
    public function test_roles_render_their_scope_and_map_navigation_without_fabricated_membership(string $role, string $scope): void
    {
        $actor = $this->maintenanceActor($role);
        if ($scope === 'organization') {
            $this->actingTenant($actor, $this->organization);
        } else {
            app(TenantContext::class)->clear();
            $this->actingAs($actor);
        }
        $response = $this->get(route('maps.index'))->assertOk()->assertViewIs('maps.index')
            ->assertSee('Mapa general')->assertSee('Total filtrado')->assertSee('Sin ubicación');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//nav//a[@href="'.route('maps.index').'"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="scope" and @type="hidden" and @value="'.$scope.'"]')->length);
        $this->assertSame(3, $xpath->query('//input[@name="render_mode" and @type="radio"]')->length);
        $boot = $this->bootstrap($xpath);
        $this->assertSame($scope, $boot['config']['scope']['mode']);
        $this->assertSame('clusters', $boot['filters']['render_mode']);
        $this->assertTrue($boot['config']['can_inspect']);
        $this->assertTrue($boot['config']['can_filter_maintenance']);
        if ($scope !== 'organization') {
            $this->assertDatabaseMissing('organization_memberships', ['user_id' => $actor->id]);
            $this->assertNull(session('active_organization_id'));
        }
    }

    public static function roleScopes(): array
    {
        return [['OFFICIAL', 'organization'], ['ORGANIZATION_ADMIN', 'organization'],
            ['DEPARTMENT_ADMIN', 'department'], ['SUPER_ADMIN', 'national']];
    }

    public function test_selected_references_keep_human_labels_and_zero_remains_a_real_filter(): void
    {
        $species = Species::query()->firstOrFail();
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maps.index', ['species_id' => $species->id,
            'inventory_id' => $this->inventory->id, 'is_protected' => '0', 'is_heritage' => '0',
            'physical_condition_ids' => [$this->condition('PHY_ROOT_GOOD_ANCHORAGE')->id], 'condition_mode' => 'all']))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="species_id"]/option[@value="'.$species->id.'" and @selected]')->length);
        $this->assertSame($species->scientific_name, trim($xpath->query('//select[@name="species_id"]/option[@selected]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//select[@name="is_protected"]/option[@value="0" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="is_heritage"]/option[@value="0" and @selected]')->length);
        $this->assertFalse($this->bootstrap($xpath)['filters']['is_protected']);
        $this->assertFalse($this->bootstrap($xpath)['filters']['is_heritage']);
        $this->assertSame(1, $xpath->query('//select[@name="physical_condition_ids[]" and @multiple]/option[@selected]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="condition_mode"]/option[@value="all" and @selected]')->length);
        $this->assertSame($this->inventory->name, trim($xpath->query('//select[@name="inventory_id"]/option[@selected]')->item(0)->textContent));
    }

    public function test_ungranted_inspection_and_maintenance_filters_are_not_rendered(): void
    {
        $actor = $this->maintenanceActor('OFFICIAL');
        $role = Role::query()->where('name', 'OFFICIAL')->sole();
        $role->permissions()->sync(Permission::query()->whereIn('code', ['organizations.view', 'trees.view'])->pluck('id')->all());
        $this->actingTenant($actor, $this->organization);
        $response = $this->get(route('maps.index'))->assertOk();
        $xpath = $this->document($response->getContent());
        foreach (['physical_condition_ids[]', 'phytosanitary_condition_ids[]', 'maturity_state_id',
            'maintenance_type_id', 'maintenance_status', 'maintenance_priority'] as $field) {
            $this->assertSame(0, $xpath->query('//select[@name="'.$field.'"]')->length);
        }
        $this->assertSame(0, $xpath->query('//input[@name="date_from" or @name="has_pending_maintenance"]')->length);
        $boot = $this->bootstrap($xpath);
        $this->assertFalse($boot['config']['can_inspect']);
        $this->assertFalse($boot['config']['can_filter_maintenance']);
    }

    public function test_untrusted_search_and_catalog_names_are_escaped_in_html_and_json_bootstrap(): void
    {
        $unsafe = '</script><img src=x onerror=alert("MAP-XSS")>';
        $species = Species::query()->firstOrFail();
        $species->update(['scientific_name' => $unsafe]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maps.index', ['q' => $unsafe, 'species_id' => $species->id]))->assertOk()
            ->assertSee($unsafe)->assertDontSee($unsafe, false);
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertSame(1, $xpath->query('//script[@id="map-bootstrap"]')->length);
        $this->assertSame($unsafe, $this->bootstrap($xpath)['filters']['q']);
        $this->assertSame($unsafe, $xpath->query('//input[@name="q"]')->item(0)->getAttribute('value'));
    }

    public function test_legend_modes_and_accessible_controls_use_server_configuration(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maps.index', ['render_mode' => 'heat']))->assertOk()
            ->assertSee('No representa riesgo ni condición sanitaria.')
            ->assertSee('Densidad de árboles');
        $xpath = $this->document($response->getContent());
        $boot = $this->bootstrap($xpath);
        $this->assertSame('heat', $boot['filters']['render_mode']);
        $this->assertSame(count(TreeStatus::cases()), $xpath->query('//*[@data-map-status-legend]//li')->length);
        foreach ($boot['config']['legend'] as $item) {
            $response->assertSee($item['label'])->assertSee($item['color'], false);
        }
        foreach (config('maps.heat_gradient') as $color) {
            $this->assertStringContainsString($color, $xpath->query('//*[contains(@class,"map-heat-ramp")]')->item(0)->getAttribute('style'));
        }
        $this->assertSame(1, $xpath->query('//*[@data-map-canvas and @tabindex="0" and @role="region"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-map-status and @aria-live="polite"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-map-filter-panel]/summary')->length);
        $this->assertSame(1, $xpath->query('//script[@type="module" and contains(@src,"/js/map/map.js")]')->length);
    }

    public function test_error_view_can_render_without_database_queries_or_authenticated_navigation(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $html = view('maps.error', ['message' => 'No se pudo consultar el mapa.', 'status' => 503])->render();
            $this->assertSame([], DB::getQueryLog());
            $this->assertStringContainsString('No se pudo consultar el mapa.', $html);
            $this->assertStringContainsString('Volver al inicio', $html);
            $this->assertStringNotContainsString('<nav', $html);
            $this->assertStringNotContainsString('SQLSTATE', $html);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_visual_fixture_renders_only_test_data_and_can_export_sanitized_preview(): void
    {
        $species = Species::query()->where('active', true)->firstOrFail();
        $placement = Placement::query()->where('active', true)->firstOrFail();
        DB::update('UPDATE trees SET geometry = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?', [-73.61, 4.15, $this->tree->id]);
        $this->tree->forceFill(['species_id' => $species->id, 'placement_id' => $placement->id])->save();
        $this->capture();
        $this->order();
        for ($index = 1; $index <= 9; $index++) {
            $status = TreeStatus::cases()[$index % count(TreeStatus::cases())];
            $this->point(
                -73.61 + ($index % 3) * .0005,
                4.15 + intdiv($index, 3) * .0004,
                ['code' => sprintf('VISUAL-%03d', $index), 'current_status' => $status->value,
                    'species_id' => $species->id, 'placement_id' => $placement->id]
            );
        }
        app(TreeService::class)->create($this->admin, ['inventory_ids' => [$this->inventory->id],
            'external_number' => 'VISUAL-SIN-UBICACION', 'current_status' => 'active']);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maps.index'))->assertOk()->assertSee('Mapa general');
        $this->activateInspectionTenant();
        $reader = app(MapReadService::class);
        $params = ['scope' => 'organization'];
        $payloads = ['trees' => $reader->points($this->admin, $params + $this->bbox()),
            'clusters' => $reader->clusters($this->admin, $params + $this->bbox()),
            'clusters-aggregated' => $reader->clusters($this->admin, $params + $this->bbox(['zoom' => 8])),
            'heat' => $reader->heat($this->admin, $params + $this->bbox()),
            'extent' => $reader->extent($this->admin, $params),
            'search' => $reader->search($this->admin, $params + ['term' => 'VISUAL'])];
        $this->assertCount(10, $payloads['trees']['features']);
        $this->assertSame(11, $payloads['extent']['total']);
        $this->assertSame(1, $payloads['extent']['without_geometry']);
        $this->snapshot($response, $payloads, $reader, $params);
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

    private function bootstrap(DOMXPath $xpath): array
    {
        return json_decode($xpath->query('//script[@id="map-bootstrap"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Los JSON proceden de servicios reales sobre sysforest_testing; ninguna URL permite abrir fichas. */
    private function snapshot(TestResponse $response, array $payloads, MapReadService $reader, array $params): void
    {
        if (getenv('SYSFOREST_MAP_VISUAL') !== '1') {
            return;
        }
        $xpath = $this->document($response->getContent());
        foreach ($xpath->query('//input[@name="_token" or @data-map-csrf]') as $node) {
            $node->setAttribute('value', 'TEST_ONLY_CSRF_NOT_VALID');
        }
        foreach ($xpath->query('//meta[@name="csrf-token"]') as $node) {
            $node->setAttribute('content', 'TEST_ONLY_CSRF_NOT_VALID');
        }
        foreach ($xpath->query('//form') as $form) {
            $form->setAttribute('action', '#');
            $form->setAttribute('onsubmit', 'return false');
        }
        foreach ($xpath->query('//a') as $link) {
            $link->setAttribute('href', '#');
        }
        foreach ($xpath->query('//link[@href] | //script[@src] | //img[@src]') as $node) {
            $attribute = $node->hasAttribute('src') ? 'src' : 'href';
            $node->setAttribute($attribute, parse_url($node->getAttribute($attribute), PHP_URL_PATH));
        }
        $directory = '/tmp/sysforest-phase10-visual';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $html = $xpath->document->saveHTML();
        $this->assertStringNotContainsString(csrf_token(), $html);
        file_put_contents($directory.'/map.html', $html);
        foreach (['departments', 'municipalities', 'organizations', 'inventories', 'species', 'families',
            'placements', 'growth_habits', 'establishment_origins', 'biogeographic_origins',
            'maturity_states', 'physical_conditions', 'phytosanitary_conditions', 'maintenance_types'] as $kind) {
            $payloads['options-'.$kind] = $reader->options($this->admin, $kind, $params);
        }
        foreach ($payloads as $name => $payload) {
            array_walk_recursive($payload, static function (&$value, $key): void {
                if ($key === 'open_url') {
                    $value = null;
                }
            });
            file_put_contents($directory.'/'.$name.'.json', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        file_put_contents($directory.'/README.txt', "Vista de revisión: fixtures transaccionales de sysforest_testing. CSRF inválido y acciones deshabilitadas. GeoJSON generado por MapReadService; no representa datos productivos.\n");
    }
}

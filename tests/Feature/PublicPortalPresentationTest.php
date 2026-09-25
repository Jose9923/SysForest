<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TreeStatus;
use App\Models\Placement;
use App\Models\Species;
use App\Services\PublicEvidenceDeliveryService;
use App\Services\PublicMapService;
use App\Services\PublicPortalService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\PublicPortalTestCase;

/** Presentación ciudadana real con datos publicados exclusivamente en la base transaccional de pruebas. */
class PublicPortalPresentationTest extends PublicPortalTestCase
{
    public function test_empty_home_is_anonymous_and_contains_public_navigation_and_seo(): void
    {
        $response = $this->get(route('public.home'))->assertOk()->assertViewIs('public.home')
            ->assertSee('Aún no hay árboles publicados')->assertSee('Explorar el mapa')->assertSee('Consultar inventario');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//meta[@name="description" and @content!=""]')->length);
        $this->assertSame(1, $xpath->query('//link[@rel="canonical"]')->length);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Navegación pública"]')->length);
        $this->assertSame(1, substr_count($xpath->query('//title')->item(0)->textContent, 'SYSFOREST'));
        $this->assertPublicHtml($response);
    }

    public function test_home_shows_real_published_indicators_and_distinguishes_missing_geometry(): void
    {
        $this->publishTree();
        $this->publishTree($this->point(-73.61, 4.15));
        $response = $this->get(route('public.home'))->assertOk()->assertDontSee('Aún no hay árboles publicados')
            ->assertSee('1 árboles con ubicación')->assertSee('1 sin ubicación registrada');
        $xpath = $this->document($response->getContent());
        $this->assertSame('2', trim($xpath->query('//*[contains(@class,"public-indicator")]/strong')->item(0)->textContent));
        $this->assertPublicHtml($response);
    }

    public function test_map_selected_options_use_public_codes_and_preserve_explicit_false(): void
    {
        $this->publishTree();
        $species = Species::query()->firstOrFail();
        $this->tree->forceFill(['species_id' => $species->id])->save();
        $condition = $this->condition('PHY_ROOT_GOOD_ANCHORAGE');
        $this->publishInspection($this->capture(['physical_condition_ids' => [$condition->id]]));
        $response = $this->get(route('public.map', ['species_id' => $species->code,
            'inventory_id' => $this->organization->public_code.'~'.$this->inventory->code,
            'physical_condition_ids' => [$condition->code], 'condition_mode' => 'all', 'is_protected' => '0']))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="species_id"]/option[@value="'.$species->code.'" and @selected]')->length);
        $this->assertSame($species->scientific_name, trim($xpath->query('//select[@name="species_id"]/option[@selected]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//select[@name="physical_condition_ids[]"]/option[@value="'.$condition->code.'" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="is_protected"]/option[@value="0" and @selected]')->length);
        $boot = $this->bootstrap($xpath, 'map-bootstrap');
        $this->assertSame('0', $boot['filters']['is_protected']);
        $this->assertSame('public', $boot['config']['access']);
        $this->assertSame('omit', $boot['config']['credentials']);
        $this->assertArrayNotHasKey('scope', $boot['config']);
        $this->assertArrayNotHasKey('scope', $boot['filters']);
        $response->assertDontSee($species->id)->assertDontSee($condition->id)->assertDontSee($this->inventory->id);
        $this->assertPublicHtml($response);
    }

    public function test_public_map_legend_controls_and_endpoint_configuration_are_accessible(): void
    {
        $response = $this->get(route('public.map', ['render_mode' => 'heat']))->assertOk()
            ->assertSee('Última inspección publicada')->assertSee('No representa riesgo ni condición sanitaria.');
        $xpath = $this->document($response->getContent());
        $boot = $this->bootstrap($xpath, 'map-bootstrap');
        $this->assertSame('heat', $boot['filters']['render_mode']);
        $this->assertSame(count(TreeStatus::cases()), $xpath->query('//*[@data-map-status-legend]//li')->length);
        $this->assertSame(3, $xpath->query('//input[@name="render_mode"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-map-canvas and @tabindex="0" and @role="region"]')->length);
        $this->assertSame(1, $xpath->query('//script[@type="module" and contains(@src,"/js/public/map.js")]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="has_pending_maintenance"] | //select[@name="maintenance_priority"]')->length);
        $this->assertSame(['executed', 'verified'], array_column($boot['config']['maintenance_statuses'], 'value'));
        foreach ($boot['config']['endpoints'] as $endpoint) {
            $this->assertStringStartsWith('/api/public/v1/', $endpoint);
        }
        foreach (config('maps.heat_gradient') as $color) {
            $this->assertStringContainsString($color, $xpath->query('//*[contains(@class,"map-heat-ramp")]')->item(0)->getAttribute('style'));
        }
        $this->assertPublicHtml($response);
    }

    public function test_listing_and_map_link_share_filters_sort_and_explicit_negative_flags(): void
    {
        $this->publishTree();
        $response = $this->get(route('public.trees.index', ['code' => $this->tree->code,
            'is_heritage' => '0', 'sort' => 'scientific_name', 'direction' => 'desc']))->assertOk()
            ->assertViewIs('public.trees.index')->assertSee($this->tree->code)->assertSee('Ver árbol');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="sort"]/option[@value="scientific_name" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="direction"]/option[@value="desc" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-public-order]/input[@name="is_heritage" and @value="0"]')->length);
        parse_str(parse_url($xpath->query('//a[@data-public-map-link]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $filters);
        $this->assertSame($this->tree->code, $filters['code']);
        $this->assertSame('0', $filters['is_heritage']);
        $this->assertPublicHtml($response);
    }

    public function test_listing_paginates_and_keeps_public_filters_in_links(): void
    {
        $this->publishTree();
        for ($index = 1; $index <= 20; $index++) {
            $this->publishTree($this->point(-73.61, 4.15, ['code' => sprintf('LIST-%03d', $index)]));
        }
        $response = $this->get(route('public.trees.index', ['organization_id' => $this->organization->public_code, 'sort' => 'code']))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(20, $xpath->query('//table[contains(@class,"public-tree-table")]/tbody/tr')->length);
        $links = $xpath->query('//*[contains(@class,"public-pagination")]//a');
        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $filters);
            $this->assertSame($this->organization->public_code, $filters['organization_id']);
            $this->assertSame('code', $filters['sort']);
            $this->assertArrayNotHasKey('scope', $filters);
        }
    }

    public function test_detail_uses_last_published_inspection_without_old_free_text_or_authors(): void
    {
        $this->publishTree();
        $this->tree->forceFill(['location_description' => 'PRIVATE-LOCATION-ADDRESS'])->save();
        $published = $this->publishInspection($this->capture(['inspection_date' => '2026-04-03',
            'general_observations' => 'PRIVATE-OBSERVATIONS', 'flowering_development' => 'PRIVATE-DEVELOPMENT']));
        $private = $this->capture(['inspection_date' => '2026-09-10']);
        $response = $this->get($this->publicTreeUrl())->assertOk()->assertViewIs('public.trees.show')
            ->assertSee('Última inspección publicada')->assertSee('2026-04-03')
            ->assertSee((string) $published->dap_m)->assertDontSee('2026-09-10')
            ->assertDontSee('PRIVATE-LOCATION-ADDRESS')->assertDontSee('PRIVATE-OBSERVATIONS')->assertDontSee('PRIVATE-DEVELOPMENT')
            ->assertDontSee($this->admin->email)->assertDontSee($this->admin->name)
            ->assertDontSee($published->id)->assertDontSee($private->id);
        $this->assertPublicHtml($response);
    }

    public function test_detail_with_unknown_location_and_without_published_records_has_neutral_empty_states(): void
    {
        $this->publishTree();
        $response = $this->get($this->publicTreeUrl())->assertOk()
            ->assertSee('Este árbol no tiene ubicación geográfica publicada.')
            ->assertSee('No hay inspecciones publicadas para este árbol.')
            ->assertSee('Sin mantenimientos realizados publicados.')
            ->assertSee('Este árbol aún no tiene fotografías publicadas.');
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//*[@data-public-tree-map]')->length);
        $boot = $this->bootstrap($xpath, 'public-tree-map-data');
        $this->assertNull($boot['tree']['geometry']);
        $this->assertPublicHtml($response);
    }

    public function test_gallery_shows_only_explicit_public_caption_and_derivative_url(): void
    {
        $this->publishTree();
        $photo = $this->publishEvidence($this->attachEvidence(['caption' => 'PRIVATE-ORIGINAL-CAPTION',
            'notes' => 'PRIVATE-PHOTO-NOTES']), '0');
        $private = $this->attachEvidence(['caption' => 'PRIVATE-HIDDEN-PHOTO']);
        $response = $this->get($this->publicTreeUrl())->assertOk()->assertSee('Fotografías publicadas')
            ->assertDontSee('PRIVATE-ORIGINAL-CAPTION')->assertDontSee('PRIVATE-PHOTO-NOTES')->assertDontSee('PRIVATE-HIDDEN-PHOTO')
            ->assertDontSee($photo->id)->assertDontSee($photo->path)->assertDontSee($private->id);
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//img[@data-public-evidence]')->length);
        $this->assertSame('/portal/media/'.$photo->public_id, $xpath->query('//img[@data-public-evidence]')->item(0)->getAttribute('src'));
        $this->assertSame('0', trim($xpath->query('//figcaption/p')->item(0)->textContent));
        $this->assertPublicHtml($response);
    }

    public function test_public_text_and_bootstrap_escape_untrusted_catalog_and_search_values(): void
    {
        $unsafe = '</script><img src=x onerror=alert("PUBLIC-XSS")>';
        $species = Species::query()->firstOrFail();
        $species->forceFill(['scientific_name' => $unsafe])->save();
        $this->tree->forceFill(['species_id' => $species->id])->save();
        $this->publishTree();
        $response = $this->get(route('public.map', ['species_id' => $species->code, 'q' => $unsafe]))->assertOk()
            ->assertSee($unsafe)->assertDontSee($unsafe, false);
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertSame($unsafe, $this->bootstrap($xpath, 'map-bootstrap')['filters']['q']);
        $response = $this->get($this->publicTreeUrl())->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        $this->assertSame(0, $this->document($response->getContent())->query('//img[@onerror]')->length);
    }

    public function test_prepared_public_views_and_error_render_without_queries_or_private_navigation(): void
    {
        $this->publishTree();
        $pages = app(PublicPortalService::class);
        $views = ['public.home' => $pages->home(), 'public.map' => $pages->map([]),
            'public.trees.index' => $pages->listing([]),
            'public.trees.show' => $pages->detail($this->organization->public_code, $this->tree->code, []),
            'public.error' => ['message' => 'Información no disponible.', 'status' => 404,
                'seo' => ['title' => 'Información no disponible · SYSFOREST', 'description' => 'Consulta pública.', 'canonical' => null]]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ($views as $view => $data) {
                $html = view($view, $data)->render();
                $this->assertStringContainsString('Navegación pública', $html);
                $this->assertStringNotContainsString('name="_token"', $html);
                $this->assertStringNotContainsString('SQLSTATE', $html);
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_public_visual_fixtures_can_export_real_pages_and_map_payloads_without_private_data(): void
    {
        $species = Species::query()->where('active', true)->firstOrFail();
        $placement = Placement::query()->where('active', true)->firstOrFail();
        $this->tree->forceFill(['species_id' => $species->id, 'placement_id' => $placement->id, 'is_protected' => true])->save();
        DB::update('UPDATE trees SET geometry = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?', [-73.61, 4.15, $this->tree->id]);
        $this->publishTree();
        $this->publishInspection($this->capture(['physical_condition_ids' => [$this->condition('PHY_ROOT_GOOD_ANCHORAGE')->id]]));
        $this->executedOrder();
        $photo = $this->publishEvidence($this->attachEvidence(), 'Árbol publicado para revisión visual de la interfaz.');
        for ($index = 1; $index <= 9; $index++) {
            $this->publishTree($this->point(
                -73.61 + ($index % 3) * .0005,
                4.15 + intdiv($index, 3) * .0004,
                ['code' => sprintf('PUBLIC-VISUAL-%03d', $index), 'current_status' => TreeStatus::cases()[$index % count(TreeStatus::cases())]->value,
                    'species_id' => $species->id, 'placement_id' => $placement->id]
            ));
        }
        $pages = [
            'home' => $this->get(route('public.home'))->assertOk(),
            'map' => $this->get(route('public.map'))->assertOk(),
            'list' => $this->get(route('public.trees.index'))->assertOk(),
            'tree' => $this->get($this->publicTreeUrl())->assertOk(),
        ];
        $map = app(PublicMapService::class);
        $payloads = ['trees' => $map->points($this->bbox()), 'clusters' => $map->clusters($this->bbox()),
            'heat' => $map->heat($this->bbox()), 'extent' => $map->extent([]), 'search' => $map->search(['term' => 'PUBLIC-VISUAL'])];
        $this->assertCount(10, $payloads['trees']['features']);
        $this->assertSame(10, $payloads['extent']['total']);
        foreach ($pages as $page) {
            $this->assertPublicHtml($page);
        }
        if (getenv('SYSFOREST_PUBLIC_VISUAL') === '1') {
            $this->snapshot($pages, $payloads, $map, $photo->public_id);
        }
    }

    private function assertPublicHtml(TestResponse $response): void
    {
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//form[translate(@method,"post","POST")="POST"] | //input[@name="_token" or @name="scope"] | //meta[@name="csrf-token"]')->length);
        $this->assertSame(0, $xpath->query('//script[contains(@src,"/js/map/map.js")]')->length);
        $response->assertDontSee($this->tree->id)->assertDontSee($this->organization->id)
            ->assertDontSee('/admin/')->assertDontSee('/logout')
            ->assertSee('href="'.route('login').'">Iniciar sesión</a>', false);
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

    private function bootstrap(DOMXPath $xpath, string $id): array
    {
        return json_decode($xpath->query('//script[@id="'.$id.'"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Fixtures de revisión: sólo archivos en /tmp, sin cookies ni tokens y sin consultas al producto. */
    private function snapshot(array $pages, array $payloads, PublicMapService $map, string $photoPublicId): void
    {
        $directory = '/tmp/sysforest-phase11-visual';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach ($pages as $name => $response) {
            $xpath = $this->document($response->getContent());
            foreach ($xpath->query('//form') as $form) {
                $form->setAttribute('action', '#');
                $form->setAttribute('onsubmit', 'return false');
            }
            foreach ($xpath->query('//link[@href] | //script[@src] | //img[@src]') as $node) {
                $attribute = $node->hasAttribute('src') ? 'src' : 'href';
                $node->setAttribute($attribute, parse_url($node->getAttribute($attribute), PHP_URL_PATH));
            }
            file_put_contents($directory.'/'.$name.'.html', $xpath->document->saveHTML());
        }
        foreach (['departments', 'municipalities', 'organizations', 'inventories', 'species', 'families',
            'placements', 'growth_habits', 'establishment_origins', 'biogeographic_origins',
            'maturity_states', 'physical_conditions', 'phytosanitary_conditions', 'maintenance_types'] as $kind) {
            $payloads['options-'.$kind] = $map->options($kind, []);
        }
        foreach ($payloads as $name => $payload) {
            file_put_contents($directory.'/'.$name.'.json', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        file_put_contents($directory.'/photo.webp', app(PublicEvidenceDeliveryService::class)->image($photoPublicId)['bytes']);
        file_put_contents($directory.'/media.json', json_encode([$photoPublicId => 'photo.webp'], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/README.txt', "Datos sintéticos de sysforest_testing; no representan inventario productivo. Formularios neutralizados. Media pública de fixture: ".$photoPublicId.".\n");
    }
}

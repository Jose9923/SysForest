<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Species;
use App\Services\ReportPdfService;
use DOMDocument;
use DOMXPath;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\PublicPortalTestCase;

/** Presentación de reportes con proyecciones explícitas y fixtures aislados en la base de pruebas. */
class ReportPresentationTest extends PublicPortalTestCase
{
    public function test_public_inventory_exposes_downloads_without_private_controls_or_identifiers(): void
    {
        $this->publishTree();
        $response = $this->get(route('public.reports.index', ['report' => 'inventory',
            'code' => $this->tree->code, 'is_protected' => '0']))->assertOk()->assertViewIs('reports.index')
            ->assertSee($this->tree->code)->assertSee('La paginación de esta vista no limita la descarga.');
        $xpath = $this->document($response->getContent());
        $this->assertSame(4, $xpath->query('//a[@data-report-download]')->length);
        foreach ($xpath->query('//a[@data-report-download]') as $link) {
            $this->assertStringContainsString('/portal/reports/download/', $link->getAttribute('href'));
            $filters = $this->reportQuery($link->getAttribute('href'));
            $this->assertSame('inventory', $filters['report']);
            $this->assertSame($this->tree->code, $filters['code']);
            $this->assertSame('0', (string) $filters['is_protected']);
            $this->assertArrayNotHasKey('page', $filters);
            $this->assertArrayNotHasKey('scope', $filters);
        }
        $this->assertSame(1, $xpath->query('//select[@name="is_protected"]/option[@value="0" and @selected]')->length);
        $this->assertPublicHtml($response);
    }

    public function test_private_report_keeps_organization_scope_and_uses_the_institutional_layout(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('reports.index', ['report' => 'inventory', 'scope' => 'organization',
            'inventory_id' => $this->inventory->id]))->assertOk()->assertViewIs('reports.index')
            ->assertSee('Gestión institucional')->assertSee($this->tree->code);
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//form[@id="reports-filter-form"]/input[@name="scope" and @value="organization"]')->length);
        $this->assertSame('organization', $this->bootstrap($xpath)['config']['scope']['mode']);
        foreach ($xpath->query('//a[@data-report-download]') as $link) {
            $filters = $this->reportQuery($link->getAttribute('href'));
            $this->assertSame('organization', $filters['scope']);
            $this->assertSame($this->inventory->id, $filters['inventory_id']);
        }
    }

    public function test_public_selected_references_use_codes_and_human_labels(): void
    {
        $species = Species::query()->where('active', true)->firstOrFail();
        $this->tree->forceFill(['species_id' => $species->id])->save();
        $this->publishTree();
        $condition = $this->condition('PHY_ROOT_GOOD_ANCHORAGE');
        $this->publishInspection($this->capture(['physical_condition_ids' => [$condition->id]]));
        $response = $this->get(route('public.reports.index', ['report' => 'inventory',
            'species_id' => $species->code, 'physical_condition_ids' => [$condition->code],
            'condition_mode' => 'all']))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="species_id"]/option[@value="'.$species->code.'" and @selected]')->length);
        $this->assertSame($species->scientific_name, trim($xpath->query('//select[@name="species_id"]/option[@selected]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//select[@name="physical_condition_ids[]"]/option[@value="'.$condition->code.'" and @selected]')->length);
        $this->assertSame([$condition->code], $this->bootstrap($xpath)['filters']['physical_condition_ids']);
        $response->assertDontSee($species->id)->assertDontSee($condition->id);
        $this->assertPublicHtml($response);
    }

    public function test_unavailable_zone_report_explains_its_absence_without_a_download_button(): void
    {
        $response = $this->get(route('public.reports.index', ['report' => 'zones']))->assertStatus(422)
            ->assertViewIs('public.error')->assertSee('No existe una definición ni un catálogo de zonas.');
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//a[@data-report-download]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(@class,"report-metrics")]')->length);
        $this->assertPublicHtml($response);
    }

    public function test_public_pdf_limit_error_displays_the_specific_export_message(): void
    {
        config(['reports.max_pdf_rows' => 1]);
        $this->publishTree();
        $this->publishTree($this->point(-73.61, 4.15));
        $url = route('public.reports.download', ['format' => 'pdf', 'report' => 'inventory']);
        $json = $this->getJson($url)->assertStatus(422);
        $message = collect($json->json('errors'))->flatten()->first();
        $this->assertIsString($message);
        $this->assertStringContainsString('1', $message);
        $response = $this->get($url)->assertStatus(422)->assertViewIs('public.error')->assertSee($message);
        $this->assertPublicHtml($response);
    }

    public function test_prepared_public_view_preserves_zero_precision_and_hidden_filters_without_queries(): void
    {
        $data = $this->pageData();
        $unsafe = '</script><img src=x onerror=alert("REPORT-XSS")>';
        $data['filters']['q'] = $unsafe;
        $data['filters']['cap_cm_min'] = '0.000001';
        $data['filters']['radius_meters'] = '250.123456';
        $data['filters']['sort'] = 'scientific_name';
        $data['filters']['direction'] = 'desc';
        $data['rows'] = new LengthAwarePaginator([['code' => $unsafe, 'dap' => '0.490197224723', 'stems' => 0]], 51, 20);
        $data['appliedFilters'] = [['label' => 'Búsqueda', 'value' => $unsafe]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $html = view('reports.index', $data)->render();
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $xpath = $this->document($html);
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertStringNotContainsString($unsafe, $html);
        $this->assertSame($unsafe, $this->bootstrap($xpath)['filters']['q']);
        $this->assertSame(1, $xpath->query('//input[@name="cap_cm_min" and @value="0.000001"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="radius_meters" and @value="250.123456"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="sort" and @value="scientific_name"]')->length);
        $this->assertStringContainsString('0.490197224723', $html);
        $this->assertSame('0', trim($xpath->query('//table[contains(@class,"report-table")]/tbody/tr/td')->item(2)->textContent));
        $this->assertStringContainsString('51 filas', $html);
    }

    public function test_permission_flags_remove_inspection_and_maintenance_filter_groups(): void
    {
        $data = $this->pageData();
        $data['filterConfig']['can_inspect'] = false;
        $data['filterConfig']['can_filter_maintenance'] = false;
        $xpath = $this->document(view('reports.index', $data)->render());
        $this->assertSame(0, $xpath->query('//input[@name="date_from"] | //select[@name="physical_condition_ids[]"] | //select[@name="maintenance_type_id"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="species_id"]')->length);
    }

    public function test_pdf_table_is_standalone_escaped_and_retains_persisted_precision(): void
    {
        $data = $this->pdfData();
        $unsafe = '<script>PDF_UNSAFE()</script>';
        $data['rows'] = [['code' => $unsafe, 'dap' => '0.490197224723', 'stems' => 0]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $html = view('reports.pdf.table', $data)->render();
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $xpath = $this->document($html);
        $this->assertSame(0, $xpath->query('//script | //form | //nav | //link[@rel="stylesheet"]')->length);
        $this->assertStringNotContainsString($unsafe, $html);
        $this->assertStringContainsString('0.490197224723', $html);
        $this->assertSame('0', trim($xpath->query('//table[contains(@class,"pdf-table")]/tbody/tr/td')->item(2)->textContent));
        $this->assertStringContainsString('2026-09-28 15:20 UTC', $html);
        $this->assertSame(1, $xpath->query('//table[contains(@class,"pdf-table")]/thead/tr/th/small')->length);
        $this->assertStringContainsString('última inspección publicada', $html);
    }

    public function test_pdf_tree_groups_plain_fields_and_distinguishes_unknown_from_zero(): void
    {
        $data = $this->pdfData();
        $data['fields'] = [
            ['group' => 'Identidad', 'label' => 'Código', 'value' => 'ARB-000001'],
            ['group' => 'Identidad', 'label' => 'Información opcional', 'value' => null],
            ['group' => 'Última inspección publicada', 'label' => 'DAP', 'value' => '0.490197224723', 'unit' => 'm'],
            ['group' => 'Última inspección publicada', 'label' => 'Cantidad', 'value' => 0],
        ];
        $html = view('reports.pdf.tree', $data)->render();
        $xpath = $this->document($html);
        $this->assertSame(2, $xpath->query('//h2')->length);
        $this->assertSame(4, $xpath->query('//dl[contains(@class,"pdf-field")]')->length);
        $this->assertSame('No informado', trim($xpath->query('//dd')->item(1)->textContent));
        $this->assertSame('0', trim($xpath->query('//dd')->item(3)->textContent));
        $this->assertStringContainsString('0.490197224723', $html);
        $this->assertSame(0, $xpath->query('//img[starts-with(@src,"http")] | //script | //form')->length);
    }

    public function test_public_map_listing_and_tree_offer_public_report_urls_only(): void
    {
        $this->publishTree();
        foreach (['public.map', 'public.trees.index'] as $route) {
            $parameters = ['code' => $this->tree->code, 'is_heritage' => '0'];
            $parameters[$route === 'public.map' ? 'render_mode' : 'page'] = $route === 'public.map' ? 'heat' : 1;
            $response = $this->get(route($route, $parameters))->assertOk();
            $xpath = $this->document($response->getContent());
            $link = $xpath->query('//a[contains(@href,"/portal/reports?")]')->item(0);
            $this->assertNotNull($link);
            $filters = $this->reportQuery($link->getAttribute('href'));
            $this->assertSame($this->tree->code, $filters['code']);
            $this->assertSame('0', (string) $filters['is_heritage']);
            $this->assertArrayNotHasKey('page', $filters);
            $this->assertArrayNotHasKey('scope', $filters);
        }
        $response = $this->get($this->publicTreeUrl())->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//a[contains(@href,"/portal/organizations/'.$this->organization->public_code.'/trees/'.$this->tree->code.'/pdf")]')->length);
    }

    public function test_report_visual_fixtures_can_save_real_pages_without_live_actions_or_tokens(): void
    {
        $species = Species::query()->where('active', true)->firstOrFail();
        $this->tree->forceFill(['species_id' => $species->id])->save();
        $this->publishTree();
        $this->publishInspection($this->capture());
        $pages = [
            'public-inventory' => $this->get(route('public.reports.index', ['report' => 'inventory']))->assertOk(),
            'public-species' => $this->get(route('public.reports.index', ['report' => 'species']))->assertOk(),
            'public-zones' => $this->get(route('public.reports.index', ['report' => 'zones']))->assertStatus(422),
        ];
        $this->actingTenant($this->admin, $this->organization);
        $pages['private-inventory'] = $this->get(route('reports.index', ['scope' => 'organization', 'report' => 'inventory']))->assertOk();
        if (getenv('SYSFOREST_REPORT_VISUAL') !== '1') {
            return;
        }
        $directory = '/tmp/sysforest-phase12-visual';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        foreach ($pages as $name => $response) {
            $document = $this->document($response->getContent());
            foreach ($document->query('//input[@name="_token"] | //meta[@name="csrf-token"]') as $node) {
                $node->setAttribute($node->tagName === 'input' ? 'value' : 'content', 'TEST_ONLY_CSRF_PLACEHOLDER');
            }
            foreach ($document->query('//form') as $form) {
                $form->setAttribute('action', '#');
                $form->setAttribute('onsubmit', 'return false');
            }
            foreach ($document->query('//a[@data-report-download]') as $link) {
                $link->setAttribute('href', '#');
            }
            file_put_contents($directory.'/'.$name.'.html', $document->document->saveHTML());
        }
        file_put_contents($directory.'/README.txt', "Fixtures sintéticos de pruebas. Formularios y descargas neutralizados; sin cookies ni tokens reales.\n");
        $this->pdfSnapshots($directory);
        $this->assertFileExists($directory.'/public-inventory.html');
    }

    /** Documentos sintéticos extensos para verificar saltos de página sin alterar datos del producto. */
    private function pdfSnapshots(string $directory): void
    {
        $pdf = app(ReportPdfService::class);
        $inventoryColumns = [
            ['key' => 'code', 'label' => 'Código'], ['key' => 'common_name', 'label' => 'Nombre común'],
            ['key' => 'scientific_name', 'label' => 'Nombre científico'], ['key' => 'municipality', 'label' => 'Municipio'],
            ['key' => 'organization', 'label' => 'Organización'], ['key' => 'placement', 'label' => 'Emplazamiento'],
            ['key' => 'current_status', 'label' => 'Estado'], ['key' => 'latest_inspection_date', 'label' => 'Última inspección publicada'],
        ];
        $inventoryRows = array_map(fn (int $index): array => [
            'code' => sprintf('QA-ARB-%06d', $index), 'common_name' => $index % 4 === 0 ? null : 'Oití',
            'scientific_name' => 'Licania tomentosa (Benth.) Fritsch.', 'municipality' => 'Municipio de prueba visual',
            'organization' => 'Institución de prueba visual del inventario forestal',
            'placement' => 'Andén con zona verde', 'current_status' => 'Activo', 'latest_inspection_date' => '2026-09-28',
        ], range(1, 50));
        $base = $this->pdfData();
        $base['appliedFilters'] = [['label' => 'Origen de los datos', 'value' => 'Fixture sintético de pruebas; no representa un inventario real.']];
        $base['notices'] = ['Documento de revisión visual. Comprobar encabezados, unidades, texto largo y pies de página.'];
        $base['summary'] = ['totalTrees' => 50, 'totalRows' => 50, 'georeferenced' => 37, 'withoutGeometry' => 13];
        $pdf->write($directory.'/inventory-table.pdf', array_replace($base, [
            'title' => 'Inventario de prueba · 50 individuos', 'columns' => $inventoryColumns, 'rows' => $inventoryRows,
        ]));

        $groupedColumns = [['key' => 'category', 'label' => 'Categoría'], ['key' => 'group', 'label' => 'Condición'],
            ['key' => 'rule_version', 'label' => 'Versión de regla'], ['key' => 'trees', 'label' => 'Individuos', 'unit' => 'árboles', 'type' => 'integer'],
            ['key' => 'value', 'label' => 'Valor de prueba', 'unit' => 'm²', 'type' => 'decimal']];
        $groupedRows = array_map(fn (int $index): array => [
            'category' => 'Categoría de revisión '.(intdiv($index - 1, 10) + 1),
            'group' => 'Grupo de prueba con una denominación extensa para verificar la separación de palabras y el ajuste de la tabla '.$index,
            'rule_version' => 'Versión de prueba 1.0', 'trees' => $index % 7 === 0 ? 0 : $index,
            'value' => $index % 9 === 0 ? null : '0.188726409122',
        ], range(1, 50));
        $pdf->write($directory.'/grouped-table.pdf', array_replace($base, [
            'title' => 'Resumen de prueba · 50 grupos', 'isPublic' => false, 'scopeLabel' => 'Alcance institucional de prueba',
            'columns' => $groupedColumns, 'rows' => $groupedRows,
        ]));

        $fields = [];
        for ($index = 1; $index <= 125; $index++) {
            $fields[] = ['group' => 'Sección de revisión '.(intdiv($index - 1, 20) + 1),
                'label' => 'Campo de prueba '.$index, 'unit' => $index % 5 === 0 ? 'm' : null,
                'value' => match (true) {
                    $index % 17 === 0 => str_repeat('Texto extenso de prueba para comprobar su continuación entre páginas, sin recortar observaciones ni superponer el pie. ', 22),
                    $index % 13 === 0 => null,
                    $index % 11 === 0 => 0,
                    $index % 5 === 0 => '0.490197224723',
                    default => 'Información sintética para revisar la ficha individual.',
                }];
        }
        $pdf->write($directory.'/individual-tree.pdf', array_replace($base, [
            'title' => 'Ficha individual de prueba · 125 campos', 'columns' => [], 'rows' => [[]], 'fields' => $fields,
            'summary' => ['totalTrees' => 1, 'totalRows' => 1, 'georeferenced' => 1, 'withoutGeometry' => 0],
        ]), true);
        foreach (['inventory-table.pdf', 'grouped-table.pdf', 'individual-tree.pdf'] as $filename) {
            $this->assertStringStartsWith('%PDF-', file_get_contents($directory.'/'.$filename));
        }
    }

    private function pageData(): array
    {
        $report = ['key' => 'inventory', 'title' => 'Inventario general', 'description' => 'Consulta de árboles.',
            'unit' => 'Árboles', 'domains' => ['trees'], 'available' => true];

        return ['isPublic' => true, 'report' => $report, 'reports' => [$report], 'scopeLabel' => 'Información publicada',
            'filters' => ['is_protected' => '0'], 'selectedOptions' => [],
            'filterConfig' => ['access' => 'public', 'credentials' => 'omit',
                'endpoints' => ['options' => '/api/public/v1/map/options'], 'limits' => ['debounce_ms' => 300],
                'tree_statuses' => [], 'maintenance_statuses' => [], 'maintenance_priorities' => [],
                'can_inspect' => true, 'can_filter_maintenance' => true, 'filter_keys' => ['is_protected']],
            'appliedFilters' => [], 'summary' => ['totalTrees' => 1, 'totalRows' => 1, 'georeferenced' => 0, 'withoutGeometry' => 1],
            'columns' => $this->columns(), 'rows' => new LengthAwarePaginator([], 0, 20), 'notices' => [], 'downloads' => [],
            'seo' => ['title' => 'Reportes · SYSFOREST', 'description' => 'Consulta pública de reportes.', 'canonical' => null]];
    }

    private function pdfData(): array
    {
        return ['title' => 'Inventario general', 'generatedAt' => '2026-09-28 15:20 UTC', 'isPublic' => true,
            'scopeLabel' => 'Información publicada', 'appliedFilters' => [], 'columns' => $this->columns(), 'rows' => [],
            'summary' => ['totalTrees' => 1, 'totalRows' => 1, 'georeferenced' => 0, 'withoutGeometry' => 1],
            'notices' => [], 'logoDataUri' => null];
    }

    private function columns(): array
    {
        return [['key' => 'code', 'label' => 'Código'], ['key' => 'dap', 'label' => 'DAP', 'unit' => 'm', 'type' => 'number'],
            ['key' => 'stems', 'label' => 'Fustes', 'type' => 'number']];
    }

    private function assertPublicHtml(TestResponse $response): void
    {
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//input[@name="_token" or @name="scope"] | //meta[@name="csrf-token"] | //select[@name="maintenance_priority"]')->length);
        $response->assertDontSee($this->tree->id)->assertDontSee($this->organization->id)->assertDontSee($this->admin->email)
            ->assertDontSee('/admin/')->assertDontSee('/logout');
    }

    private function bootstrap(DOMXPath $xpath): array
    {
        return json_decode($xpath->query('//script[@id="reports-bootstrap"]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
    }

    private function reportQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}

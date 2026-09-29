<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** Contratos Blade aislados: escape, revisión visible, consentimiento y ausencia de consultas en vistas. */
class ImportPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors', new ViewErrorBag());
    }

    public function test_upload_form_has_private_destination_file_limits_and_no_ownership_inputs(): void
    {
        $data = $this->pageData();
        $data['inventories'] = collect([$data['inventory']]);
        $data['limits'] = ['max_file_mb' => 5, 'max_rows' => 100];
        $html = view('imports.create', $data)->render();
        $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//form[@enctype="multipart/form-data"]')->length);
        $this->assertSame(1, $xpath->query('//input[@type="file" and @name="file" and @required]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="inventory_id" and @required]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="organization_id" or @name="user_id" or @name="path" or @name="created_by"]')->length);
        $this->assertStringContainsString('Máximo 5 MB y 100 filas.', $html);
        $this->assertStringContainsString('Cargar y revisar archivo', $html);
        $this->assertStringNotContainsString('Confirmar importación', $html);
        $this->snapshot('import-upload', $html);
    }

    public function test_empty_inventory_list_explains_the_missing_destination_without_upload_form(): void
    {
        $data = $this->pageData();
        $data['inventories'] = collect();
        $data['limits'] = ['max_file_mb' => 5, 'max_rows' => 100];
        $html = view('imports.create', $data)->render();
        $this->assertStringContainsString('No hay inventarios disponibles', $html);
        $this->assertSame(0, $this->document($html)->query('//input[@type="file"]')->length);
    }

    public function test_ready_preview_requires_explicit_consent_without_resubmitting_the_rows(): void
    {
        $html = view('imports.show', $this->pageData())->render();
        $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//form[@data-import-confirmation]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="confirmation" and @type="checkbox" and @required and not(@checked)]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-import-confirmation]/input[@name="lock_version" and @value="3"]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="inventory_id" or @name="organization_id" or @name="rows" or @name="raw_values" or @name="normalized_values"]')->length);
        $this->assertStringContainsString('0.490197224723', $html);
        $this->assertStringContainsString('CAP (cm)', $html);
        $this->assertStringContainsString('DAP (m)', $html);
        $this->assertStringContainsString('1 fila', $html);
        $this->assertStringContainsString('Revisión válida hasta', $html);
        $this->assertSame(1, $xpath->query('//dt[text()="Número de fustes"]/following-sibling::dd[text()="0"]')->length);
        $this->snapshot('import-ready', $html);
    }

    public function test_invalid_preview_shows_every_field_error_and_no_confirmation_button(): void
    {
        $data = $this->pageData();
        $data['batch']->status = ImportBatchStatus::from('invalid');
        $data['batch']->valid_rows = 0;
        $data['batch']->invalid_rows = 1;
        $data['canConfirm'] = false;
        $data['rows']->items()[0]->status = 'invalid';
        $data['rows']->items()[0]->errors = ['cap_cm' => ['La medida no es válida.', 'Debe ser mayor que cero.'], 'unknown_column' => ['Encabezado no reconocido.']];
        $html = view('imports.show', $data)->render();
        $xpath = $this->document($html);
        $this->assertSame(3, $xpath->query('//ul[contains(@class,"import-row-errors")]/li')->length);
        $this->assertSame(0, $xpath->query('//form[@data-import-confirmation]')->length);
        $this->assertStringContainsString('Corrige el archivo antes de importar', $html);
        $this->assertStringContainsString('CAP (cm):', $html);
        $this->assertStringContainsString('unknown_column:', $html);
        $this->snapshot('import-errors', $html);
    }

    public function test_review_values_filenames_and_messages_are_escaped_and_internal_ids_stay_hidden(): void
    {
        $data = $this->pageData();
        $unsafe = '<img src=x onerror=alert("IMPORT-XSS")>';
        $data['batch']->file_name = $unsafe;
        $data['batch']->notice = $unsafe;
        $row = $data['rows']->items()[0];
        $row->raw_values['observations'] = $unsafe;
        $row->errors = ['observations' => [$unsafe]];
        $row->normalized_values['tree']['species_id'] = 'PRIVATE-SPECIES-ID';
        $row->normalized_values['inspection']['inspector_id'] = 'PRIVATE-INSPECTOR-ID';
        $row->normalized_values['calculations']['maturity_state_id'] = 'PRIVATE-MATURITY-ID';
        $row->normalized_values['calculations']['classification_rule_version_id'] = 'PRIVATE-RULE-ID';
        $row->normalized_values['calculations']['calculation_rule_version'] = 'PRIVATE-ALGORITHM';
        $html = view('imports.show', $data)->render();
        $this->assertSame(0, $this->document($html)->query('//img[@onerror]')->length);
        $this->assertStringNotContainsString($unsafe, $html);
        $this->assertStringNotContainsString('PRIVATE-SPECIES-ID', $html);
        $this->assertStringNotContainsString('PRIVATE-INSPECTOR-ID', $html);
        $this->assertStringNotContainsString('PRIVATE-MATURITY-ID', $html);
        $this->assertStringNotContainsString('PRIVATE-RULE-ID', $html);
        $this->assertStringNotContainsString('PRIVATE-ALGORITHM', $html);
    }

    public function test_completed_batch_links_created_records_without_confirming_again(): void
    {
        $data = $this->pageData();
        $data['batch']->status = ImportBatchStatus::from('completed');
        $data['batch']->confirmed_at = CarbonImmutable::parse('2026-09-28 16:00:00 UTC');
        $data['batch']->imported_rows = 1;
        $data['canConfirm'] = false;
        $data['canCancel'] = false;
        $row = $data['rows']->items()[0];
        $row->status = 'imported';
        $row->tree_id = '044a8283-eb1a-4535-b799-9ae0b81c04a1';
        $row->inspection_id = '3a42d491-8d84-4103-824a-00b58a250986';
        $html = view('imports.show', $data)->render();
        $this->assertStringContainsString(route('trees.show', $row->tree_id), $html);
        $this->assertStringContainsString(route('inspections.show', $row->inspection_id), $html);
        $this->assertStringContainsString('Importación confirmada', $html);
        $this->assertStringNotContainsString('Confirmar importación', $html);
        $this->assertStringNotContainsString('Cancelar este lote', $html);
        $this->snapshot('import-completed', $html);
    }

    public function test_prepared_preview_and_history_render_without_database_queries(): void
    {
        $data = $this->pageData();
        $data['batches'] = new LengthAwarePaginator([$data['batch']], 1, 25);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $preview = view('imports.show', $data)->render();
            $history = view('imports.index', $data)->render();
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertStringContainsString('IMP-PRUEBA-001', $preview);
        $this->assertStringContainsString('IMP-PRUEBA-001', $history);
        $this->snapshot('import-history', $history);
    }

    private function pageData(): array
    {
        $batch = (object) [
            'id' => 'd7f8c7ae-1e12-4889-b2d8-3f13f462e53c', 'code' => 'IMP-PRUEBA-001',
            'file_name' => 'inventario-forestal.xlsx', 'status' => ImportBatchStatus::from('ready'),
            'total_rows' => 1, 'valid_rows' => 1, 'invalid_rows' => 0, 'imported_rows' => 0,
            'lock_version' => 3, 'created_at' => CarbonImmutable::parse('2026-09-28 15:00:00 UTC'),
            'expires_at' => CarbonImmutable::parse('2026-09-29 15:00:00 UTC'), 'confirmed_at' => null, 'notice' => null,
        ];
        $row = (object) [
            'row_number' => 2, 'status' => 'valid', 'errors' => [], 'tree_code' => null,
            'tree_id' => null, 'inspection_id' => null,
            'raw_values' => ['cap_cm' => '154', 'number_of_stems' => 0, 'observations' => ''],
            'normalized_values' => ['tree' => [], 'inspection' => [], 'calculations' => ['dap_m' => '0.490197224723']],
        ];

        return [
            'organization' => (object) ['name' => 'Institución de prueba'], 'batch' => $batch,
            'inventory' => (object) ['id' => '9b2ea82d-3c2c-42fc-9a43-35771e280096', 'code' => 'INV-001', 'name' => 'Campaña de prueba'],
            'rows' => new LengthAwarePaginator([$row], 1, 25), 'canConfirm' => true, 'canCancel' => true,
            'calculatedFields' => [2 => [['label' => 'DAP (m)', 'value' => '0.490197224723']]],
            'rowStatusLabels' => ['valid' => 'Válida', 'invalid' => 'Con errores', 'imported' => 'Importada'],
            'fieldDefinitions' => ['cap_cm' => ['label' => 'CAP (cm)'], 'number_of_stems' => ['label' => 'Número de fustes'],
                'observations' => ['label' => 'Observaciones'], 'dap_m' => ['label' => 'DAP (m)']],
        ];
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

    /** Genera fixtures sin credenciales ni acciones activas para revisar la presentación en varios tamaños. */
    private function snapshot(string $name, string $html): void
    {
        if (getenv('SYSFOREST_IMPORT_VISUAL_SNAPSHOTS') !== '1') {
            return;
        }
        $xpath = $this->document($html);
        foreach ($xpath->query('//input[@name="_token"]') as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('//form') as $form) {
            $form->setAttribute('action', '#');
            $form->setAttribute('onsubmit', 'return false');
        }
        foreach ($xpath->query('//button[not(@type) or @type="submit"]') as $button) {
            $button->setAttribute('type', 'button');
        }
        $directory = '/tmp/sysforest-phase13-visual';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $xpath->document->saveHTML());
    }
}

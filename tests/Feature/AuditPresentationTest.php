<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** Verifica proyecciones de presentación sin consultar ni modificar la base de datos. */
class AuditPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'cache.default' => 'array']);
        if (!Route::has('audit.index')) {
            Route::get('/audit', static fn () => response(''))->name('audit.index');
        }
        view()->share('errors', new ViewErrorBag());
    }

    public function test_national_index_shows_scope_filters_pagination_and_escaped_names(): void
    {
        $entry = $this->entry();
        $entry['actor_name'] = '<script>usuario</script>';
        $entry['organization_name'] = '<img src=x onerror=alert(1)>';
        $data = $this->indexData([$entry]);
        $view = $this->view('audit.index', $data)->assertSee('Alcance nacional')->assertSee('Fechas en UTC')
            ->assertSee('name="organization_id"', false)->assertSee('name="scope" value="national"', false)
            ->assertSee('Página 1 de 3')->assertSee('&lt;script&gt;usuario&lt;/script&gt;', false)
            ->assertDontSee('<script>usuario</script>', false)->assertDontSee('<img src=x', false);
        $this->saveVisual('audit-national.html', (string) $view);
    }

    public function test_organization_scope_has_no_other_organization_selector(): void
    {
        $data = $this->indexData([$this->entry()]);
        $data['scope'] = (object) ['kind' => 'organization', 'organizationId' => '01900000-0000-7000-8000-000000000001',
            'organizationName' => 'Institución educativa de prueba'];
        $data['canViewNational'] = false;
        $view = $this->view('audit.index', $data)->assertSee('Institución educativa de prueba')
            ->assertSee('name="scope" value="organization"', false)->assertDontSee('name="organization_id"', false)
            ->assertDontSee('Auditoría nacional')->assertSee('Nombre del usuario')->assertSee('datos actuales');
        $this->saveVisual('audit-organization.html', (string) $view);
    }

    public function test_detail_escapes_snapshots_and_context_and_preserves_back_scope(): void
    {
        $entry = $this->entry();
        $entry['changes'] = [['label' => 'Observaciones <script>campo</script>', 'before' => '<script>anterior</script>',
            'after' => '<img src=x onerror=alert(1)>']];
        $entry['metadata'] = ['Motivo <script>contexto</script>' => '<script>valor</script>'];
        $data = ['scope' => $this->indexData([])['scope'], 'entry' => $entry,
            'back_url' => '/audit?scope=national&organization_id=01900000-0000-7000-8000-000000000001'];
        $view = $this->view('audit.show', $data)->assertSee('Antes')->assertSee('Después')
            ->assertSee('&lt;script&gt;anterior&lt;/script&gt;', false)->assertSee('&lt;script&gt;valor&lt;/script&gt;', false)
            ->assertDontSee('<script>anterior</script>', false)->assertDontSee('<script>contexto</script>', false)
            ->assertDontSee('<img src=x', false)->assertSee('scope=national&amp;organization_id=', false)
            ->assertSee('Referencia de la solicitud')->assertSee('Versión registrada');
        $this->saveVisual('audit-detail.html', (string) $view);
    }

    public function test_legacy_event_explains_missing_snapshots_without_inventing_values(): void
    {
        $entry = $this->entry();
        $entry['has_snapshots'] = false;
        $entry['changes'] = [];
        $entry['metadata'] = [];
        $entry['version'] = null;
        $entry['request_id'] = null;
        $view = $this->view('audit.show', ['scope' => $this->indexData([])['scope'], 'entry' => $entry, 'back_url' => '/audit?scope=national'])
            ->assertSee('Este evento no conserva una comparación de valores')->assertSee('eventos históricos')
            ->assertDontSee('Referencia de la solicitud')->assertDontSee('Versión registrada')->assertDontSee('Contexto del evento');
        $this->saveVisual('audit-legacy.html', (string) $view);
    }

    public function test_empty_index_keeps_filters_available(): void
    {
        $this->view('audit.index', $this->indexData([]))->assertSee('No hay eventos para esta consulta')
            ->assertSee('Aplicar filtros')->assertSee('Limpiar filtros');
    }

    public function test_presented_measurements_and_locations_have_spanish_labels_and_correct_units(): void
    {
        foreach ([
            ['tree', ['accuracy_m' => '1.250', 'external_number' => '0001', 'gps_model' => 'Modelo'],
                ['Precisión (m)', 'Número externo', 'Modelo del GPS']],
            ['tree_inspection', ['calculations' => ['radius_m' => '0.100000'], 'phenology' => ['flowering_date' => '2026-09-28']],
                ['Resultados calculados · Radio (m)', 'Fenología · Fecha de floración']],
            ['catalog', ['diameter_step' => '10.000000', 'height_step' => '5.000000'],
                ['Intervalo de clase diamétrica (cm)', 'Intervalo de clase de altura (m)']],
        ] as [$type, $after, $labels]) {
            $record = (object) ($this->entry() + ['resource_type' => $type, 'before_values' => [], 'after_values' => $after, 'context' => []]);
            $detail = app(\App\Services\AuditPresentationService::class)->detail($record);
            $this->assertSame($labels, array_column($detail['changes'], 'label'));
            $this->assertTrue($detail['has_snapshots']);
        }
    }

    private function indexData(array $entries): array
    {
        $paginator = new LengthAwarePaginator($entries, $entries === [] ? 0 : 41, 20, 1, ['path' => '/audit']);
        $paginator->appends(['scope' => 'national']);

        return ['scope' => (object) ['kind' => 'national', 'organizationId' => null, 'organizationName' => null],
            'filters' => [], 'entries' => $paginator,
            'organizations' => collect([(object) ['id' => '01900000-0000-7000-8000-000000000001', 'name' => 'Institución educativa de prueba']]),
            'canViewNational' => true, 'resourceTypes' => ['tree' => 'Árbol', 'tree_inspection' => 'Inspección']];
    }

    private function entry(): array
    {
        return ['id' => '01900000-0000-7000-8000-000000000002', 'source' => 'audit_log',
            'occurred_at' => '2026-09-28 14:35:20.000000', 'event_label' => 'Árbol actualizado', 'event' => 'tree.updated',
            'resource_label' => 'Árbol', 'resource_id' => '01900000-0000-7000-8000-000000000003',
            'actor_name' => 'Usuario de prueba', 'organization_name' => 'Institución educativa de prueba',
            'detail_url' => '/audit/audit_log/01900000-0000-7000-8000-000000000002?scope=national',
            'source_label' => 'Registro de auditoría', 'version' => 2, 'request_id' => '01900000-0000-7000-8000-000000000004',
            'metadata' => [], 'changes' => [['label' => 'Altura total', 'before' => '12.4 m', 'after' => '13.1 m']],
            'has_snapshots' => true];
    }

    private function saveVisual(string $name, string $html): void
    {
        if (getenv('SYSFOREST_AUDIT_VISUAL') !== '1') {
            return;
        }
        $directory = '/tmp/sysforest-phase14-audit-visual';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$name, $html);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MaintenanceType;
use App\Services\EvidenceService;
use App\Services\MaintenanceReadService;
use App\Services\MaintenanceService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Tests\MaintenanceTestCase;

/** Contratos de presentación con órdenes sintéticas y exportación visual únicamente optativa. */
class MaintenancePresentationTest extends MaintenanceTestCase
{
    public function test_create_form_keeps_tree_context_and_auto_identity_without_status_or_owner_fields(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.maintenances.create', $this->tree->id))->assertOk()
            ->assertViewIs('maintenances.form')->assertSee($this->tree->code)
            ->assertSee('La identificación se asigna al guardar')->assertSee('estado Pendiente');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="maintenance_type_id" and @required and contains(@class,"catalog-select")]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="priority"]/option[@value="normal" and @selected]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="scheduled_date" and @type="date"]')->length);
        $this->assertSame(0, $xpath->query('//*[@name="organization_id" or @name="tree_id" or @name="code" or @name="status" or @name="lock_version"]')->length);
        $this->snapshot('maintenance-form', $response);
    }

    public function test_index_shows_real_timeline_and_preserves_scheduled_date_filters(): void
    {
        $first = $this->order(['priority' => 'urgent', 'assigned_to' => $this->admin->id]);
        $second = $this->order(['scheduled_date' => '2026-10-10', 'priority' => 'high']);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maintenances.index', ['tree_id' => $this->tree->id,
            'date_from' => '2026-10-01', 'date_to' => '2026-10-10']))->assertOk()
            ->assertSee($first->code)->assertSee($second->code)->assertSee('01/10/2026')->assertSee('10/10/2026')
            ->assertSee('Fecha programada desde')->assertSee('Fecha programada hasta')->assertSee('2 mantenimientos encontrados');
        $xpath = $this->document($response->getContent());
        $this->assertSame(2, $xpath->query('//article[contains(@class,"maintenance-timeline-row")]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="date_from" and @value="2026-10-01"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="trashed"]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('trees.maintenances.create', $this->tree->id).'"]')->length);
        $this->snapshot('maintenance-index', $response);
    }

    public function test_detail_renders_history_gallery_and_only_pending_transitions(): void
    {
        $order = $this->order(['notes' => 'Poda de formación prevista junto al andén.', 'priority' => 'high']);
        $photo = app(EvidenceService::class)->upload(
            $this->admin,
            null,
            null,
            $this->evidenceInput(['caption' => 'Referencia previa a la intervención']),
            $order->id
        )->sole();
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maintenances.show', $order->id))->assertOk()->assertViewIs('maintenances.show')
            ->assertSee('Historial de estados')->assertSee('Registro inicial')->assertSee('Referencia previa a la intervención')
            ->assertSee('Mantenimiento registrado')->assertSee('Anular registro por error')
            ->assertDontSee($photo->path, false);
        $xpath = $this->document($response->getContent());
        foreach (['scheduled', 'assigned', 'cancelled'] as $status) {
            $this->assertSame(1, $xpath->query('//form/input[@name="status" and @value="'.$status.'"]')->length);
        }
        $this->assertSame(3, $xpath->query('//form/input[@name="status"]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@id="transition-cancelled-reason" and @required]')->length);
        $this->assertSame(1, $xpath->query('//img[@src="'.route('evidences.view', $photo->id).'"]')->length);
        $this->snapshot('maintenance-show', $response);
    }

    public function test_execution_form_requires_result_and_date_and_verification_is_reserved_to_admin(): void
    {
        $order = $this->order(['assigned_to' => $this->admin->id]);
        $order = $this->advance($order, 'assigned');
        $order = $this->advance($order, 'in_progress');
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maintenances.show', $order->id))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//input[@name="performed_date" and @type="date" and @required]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="result" and @required and @maxlength="20000"]')->length);
        $this->activateInspectionTenant();
        $order = $this->advance($order, 'executed', ['performed_date' => now()->toDateString(), 'result' => '0']);
        $adminView = $this->get(route('maintenances.show', $order->id))->assertOk()->assertDontSee('Sin resultado registrado.')
            ->assertDontSee('Editar mantenimiento')->assertSee('Verificar y cerrar mantenimiento');
        $this->assertSame(1, $this->document($adminView->getContent())->query('//input[@name="status" and @value="verified"]')->length);
        $official = $this->maintenanceActor('OFFICIAL');
        $this->actingTenant($official, $this->organization);
        $officialView = $this->get(route('maintenances.show', $order->id))->assertOk()
            ->assertDontSee('Acciones disponibles')->assertDontSee('Anular registro por error')
            ->assertSee('Pendiente de verificación administrativa')->assertSee('un administrador de la organización o superadministrador');
        $this->assertSame(0, $this->document($officialView->getContent())->query('//form/input[@name="status"]')->length);
    }

    public function test_next_action_is_visible_before_record_details_and_cancel_remains_secondary(): void
    {
        $order = $this->order(['assigned_to' => $this->admin->id]);
        $this->actingTenant($this->admin, $this->organization);
        $pending = $this->get(route('maintenances.show', $order->id))->assertOk()
            ->assertSeeInOrder(['SEGUIMIENTO Y CIERRE', 'Asignar responsable', 'Datos de la intervención']);
        $xpath = $this->document($pending->getContent());
        $this->assertSame(1, $xpath->query('//details[@open]//input[@name="status" and @value="assigned"]')->length);
        $this->assertSame(0, $xpath->query('//details[@open]//input[@name="status" and @value="cancelled"]')->length);
        $this->activateInspectionTenant();
        $order = $this->advance($order, 'assigned');
        $order = $this->advance($order, 'in_progress');
        $execution = $this->get(route('maintenances.show', $order->id))->assertOk()->assertSee('Registrar ejecución');
        $xpath = $this->document($execution->getContent());
        $this->assertSame(1, $xpath->query('//details[@open]//input[@name="performed_date" and @required]')->length);
        $this->assertSame(1, $xpath->query('//details[@open]//textarea[@name="result" and @required]')->length);
    }

    public function test_verification_is_visible_for_admin_and_confirmed_closure_has_no_status_forms(): void
    {
        $order = $this->executedOrder();
        $this->actingTenant($this->admin, $this->organization);
        $before = $this->get(route('maintenances.show', $order->id))->assertOk()
            ->assertSee('Revisa el resultado y confirma el cierre')->assertSee('Verificar y cerrar mantenimiento');
        $this->assertSame(1, $this->document($before->getContent())->query('//details[@open]//input[@name="status" and @value="verified"]')->length);
        $this->patch(route('maintenances.status', $order->id), [
            'status' => 'verified', 'lock_version' => $order->lock_version,
        ])->assertRedirect(route('maintenances.show', $order->id));
        $after = $this->get(route('maintenances.show', $order->id))->assertOk()
            ->assertSee('Mantenimiento cerrado y verificado')->assertSee('Ejecutado → Verificado')
            ->assertDontSee('Verificar y cerrar mantenimiento')->assertDontSee('Acciones disponibles');
        $this->assertSame(0, $this->document($after->getContent())->query('//form/input[@name="status"]')->length);
        $this->assertSame('verified', $order->fresh()->status->value);
    }

    public function test_cancelled_order_is_not_presented_as_executed_and_verified(): void
    {
        $order = $this->advance($this->order(), 'cancelled', ['reason' => 'Intervención descartada.']);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maintenances.show', $order->id))->assertOk()
            ->assertSee('Mantenimiento cancelado')->assertSee('no acredita una intervención ejecutada y verificada')
            ->assertDontSee('Mantenimiento cerrado y verificado');
        $this->assertSame(0, $this->document($response->getContent())->query('//form/input[@name="status"]')->length);
    }

    public function test_edit_preserves_zero_and_inactive_type_but_new_form_does_not_offer_it(): void
    {
        $order = $this->order(['notes' => '0']);
        MaintenanceType::query()->whereKey($order->maintenance_type_id)->update(['active' => false]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maintenances.edit', $order->id))->assertOk()->assertSee('referencia histórica inactiva');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//select[@name="maintenance_type_id"]/option[@value="'.$order->maintenance_type_id.'" and @selected]')->length);
        $this->assertSame('0', $xpath->query('//textarea[@name="notes"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//input[@name="lock_version" and @value="1"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="_method" and @value="PUT"]')->length);
        $this->get(route('maintenances.show', $order->id))->assertOk()->assertSee('Tipo histórico inactivo')->assertDontSee('Sin notas registradas.');
        $create = $this->get(route('trees.maintenances.create', $this->tree->id))->assertOk();
        $this->assertSame(0, $this->document($create->getContent())->query('//option[@value="'.$order->maintenance_type_id.'"]')->length);
    }

    public function test_void_record_has_restore_only_and_disappears_from_normal_timeline(): void
    {
        $order = $this->order();
        app(MaintenanceService::class)->void($this->admin, $order->id, ['lock_version' => 1, 'deletion_notes' => 'Registro duplicado']);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('maintenances.index'))->assertOk()->assertDontSee($order->code);
        $this->get(route('maintenances.index', ['trashed' => 'only']))->assertOk()->assertSee($order->code)->assertSee('Registro anulado');
        $response = $this->get(route('maintenances.show', $order->id))->assertOk()->assertSee('Este registro está anulado')
            ->assertSee('Registro duplicado')->assertSee('Restaurar mantenimiento')->assertDontSee('Editar mantenimiento')
            ->assertDontSee('Acciones disponibles')->assertDontSee('Anular registro por error');
        $this->assertSame(1, $this->document($response->getContent())->query('//form[@action="'.route('maintenances.restore', $order->id).'"]')->length);
    }

    public function test_tree_summary_contains_real_orders_and_does_not_mix_maintenance_photographs(): void
    {
        $first = $this->order(['scheduled_date' => null]);
        $second = $this->order(['priority' => 'urgent']);
        app(EvidenceService::class)->upload($this->admin, null, null, $this->evidenceInput(['caption' => 'FOTO-SOLO-MANTENIMIENTO']), $first->id);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('trees.show', $this->tree->id))->assertOk()->assertSee($first->code)
            ->assertSee($second->code)->assertSee('Por programar')->assertDontSee('FOTO-SOLO-MANTENIMIENTO');
        $xpath = $this->document($response->getContent());
        $this->assertSame(2, $xpath->query('//section[@id="tree-maintenances"]//article[contains(@class,"maintenance-timeline-row")]')->length);
        $this->assertSame(1, $xpath->query('//section[@id="tree-maintenances"]//a[@href="'.route('trees.maintenances.create', $this->tree->id).'"]')->length);
    }

    public function test_text_fields_and_search_values_are_escaped_in_blade(): void
    {
        $unsafe = '<img src=x onerror=alert("MNT-XSS")>';
        $order = $this->order(['notes' => $unsafe]);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('maintenances.show', $order->id))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        $this->get(route('maintenances.edit', $order->id))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        $this->get(route('maintenances.index', ['q' => $unsafe]))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
    }

    public function test_conflicting_form_uses_specific_safe_message_and_preserves_current_data(): void
    {
        $order = $this->order();
        app(MaintenanceService::class)->update($this->admin, $order->id, ['lock_version' => 1, 'notes' => 'Corrección vigente']);
        $this->actingTenant($this->admin, $this->organization);
        $this->put(route('maintenances.update', $order->id), $this->maintenanceInput(['lock_version' => 1, 'notes' => 'Edición obsoleta']))
            ->assertStatus(409)->assertSee('El mantenimiento fue modificado por otro usuario.')
            ->assertSee('Volver a mantenimientos')->assertDontSee('SQLSTATE', false);
        $this->assertSame('Corrección vigente', $order->fresh()->notes);
    }

    public function test_department_reader_has_timeline_and_no_mutation_controls(): void
    {
        $order = $this->order();
        $reader = $this->maintenanceActor('DEPARTMENT_ADMIN');
        $this->actingTenant($reader, $this->organization);
        $index = $this->get(route('maintenances.index'))->assertOk()->assertSee($order->code)->assertDontSee('Seleccionar árbol');
        $this->assertSame(0, $this->document($index->getContent())->query('//select[@name="trashed"]')->length);
        $this->get(route('maintenances.show', $order->id))->assertOk()->assertSee('Historial de estados')
            ->assertDontSee('Editar mantenimiento')->assertDontSee('Acciones disponibles')
            ->assertDontSee('Anular registro por error')->assertDontSee('Agregar fotografías');
    }

    public function test_views_render_with_eager_relationships_and_finished_work_has_no_transition(): void
    {
        $order = $this->executedOrder();
        $order = $this->advance($order, 'verified');
        $this->actingTenant($this->admin, $this->organization);
        Model::preventLazyLoading();
        try {
            foreach ([route('maintenances.index'), route('maintenances.show', $order->id),
                route('trees.maintenances.create', $this->tree->id), route('trees.show', $this->tree->id)] as $url) {
                $this->get($url)->assertOk();
            }
            $this->activateInspectionTenant();
            $view = app(MaintenanceReadService::class)->show($this->admin, $order->id);
            $this->assertTrue($view['maintenance']->relationLoaded('statusHistories'));
            $this->assertTrue($view['maintenance']->relationLoaded('revisions'));
            $this->assertCount(0, $view['transitionActions']);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_revision_differences_show_human_names_dates_and_escaped_changed_values(): void
    {
        $order = $this->order(['notes' => 'Nota anterior', 'priority' => 'normal']);
        $newType = MaintenanceType::query()->whereKeyNot($order->maintenance_type_id)->where('active', true)->firstOrFail();
        $unsafe = '<script>alert("REVISION-XSS")</script>';
        app(MaintenanceService::class)->update($this->admin, $order->id, [
            'lock_version' => 1, 'maintenance_type_id' => $newType->id, 'assigned_to' => $this->admin->id,
            'priority' => 'urgent', 'scheduled_date' => '2026-11-03', 'notes' => $unsafe,
        ]);
        $this->actingTenant($this->admin, $this->organization);
        $response = $this->get(route('maintenances.show', $order->id))->assertOk()
            ->assertSee('Datos actualizados')->assertSee('Nota anterior')->assertSee($unsafe)->assertDontSee($unsafe, false)
            ->assertSee($newType->name)->assertSee($this->admin->name)->assertSee('03/11/2026');
        $xpath = $this->document($response->getContent());
        $this->assertGreaterThanOrEqual(6, $xpath->query('//div[@class="maintenance-difference"]')->length);
        $this->activateInspectionTenant();
        $view = app(MaintenanceReadService::class)->show($this->admin, $order->id);
        $revision = $view['revisions']->firstWhere('version', 2);
        $this->assertSame('Datos actualizados', $revision['eventLabel']);
        $fields = collect($revision['differences'])->keyBy('label');
        $this->assertSame('Nota anterior', $fields['Notas de la intervención']['before']);
        $this->assertSame($unsafe, $fields['Notas de la intervención']['after']);
        $this->assertSame($this->admin->name, $fields['Responsable']['after']);
        $this->assertSame($newType->name, $fields['Tipo de mantenimiento']['after']);
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

    /** Exporta solo fixtures, sin credenciales, envíos de formulario ni imágenes privadas. */
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
        $image = $this->evidenceInput()['items'][0]['file'];
        $preview = 'data:image/jpeg;base64,'.base64_encode(file_get_contents($image->getRealPath()));
        foreach ($xpath->query('//img[@data-evidence-image]') as $image) {
            $image->setAttribute('src', $preview);
        }
        $directory = '/tmp/sysforest-phase9-visual';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $html = $xpath->document->saveHTML();
        $this->assertStringNotContainsString('name="_token"', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}

<section class="panel forestry-panel" data-risk-form data-preview-url="{{ $tree ? route('risk.preview', $tree->id) : route('risk.registration-preview') }}" data-inspection-id="{{ $inspection?->id }}">
<h2>Prioridad de Gestión Fitosanitaria</h2><p>El sistema sugiere; confirma tu decisión profesional. Si eliges otro nivel o no hay sugerencia por falta de condiciones evaluadas, justifica tu decisión en las observaciones generales. No seleccionar condiciones no significa que el árbol esté sano. No implica una orden de intervención ni modifica el estado del árbol.</p>
<input type="hidden" name="risk_methodology_id" value="{{ $riskMethodology->id }}" data-risk-methodology>
<p role="status" aria-live="polite" data-risk-result>Consulta la sugerencia después de registrar las condiciones.</p>
<ul data-risk-details></ul>
<button type="button" class="button secondary" data-risk-preview>Actualizar sugerencia</button>
<div class="field"><label for="risk-priority">Prioridad profesional</label><select id="risk-priority" name="risk_priority" required><option value="">Selecciona tu decisión</option>@foreach (\App\Enums\RiskPriority::cases() as $priority)<option value="{{ $priority->value }}" @selected((string) old('risk_priority', $inspection?->riskEvaluation?->priority) === (string) $priority->value)>{{ $priority->label() }}</option>@endforeach</select></div>
<label><input type="checkbox" name="risk_confirmed" value="1" required data-risk-confirmed> Confirmo la prioridad profesional según las condiciones de esta inspección.</label>
@error('risk_priority')<p class="field-error">{{ $message }}</p>@enderror
</section>
@push('scripts')<script src="{{ asset('js/risk/inspection.js') }}" defer></script>@endpush

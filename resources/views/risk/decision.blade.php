@extends('layouts.institutional')
@section('title', 'Cambiar riesgo · '.$tree->code)
@include('forestry.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/risk.css') }}">@endpush
@section('content')
<section class="risk-decision forestry-page">
    <header class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · Gestión del riesgo</p><h1>Cambiar riesgo de {{ $tree->code }}</h1><p class="muted">Revisa y confirma la prioridad profesional de la última inspección.</p></div><a class="button secondary" href="{{ route('risk.index') }}">Volver a Gestión del riesgo</a></header>
    @include('risk.navigation')
    @if ($inspection === null)
        <section class="panel forestry-empty"><h2>Este árbol aún no tiene una inspección</h2><p>Registra una visita con las condiciones observadas para evaluar y confirmar su riesgo.</p>@if ($canCreateInspection)<a class="button" href="{{ route('trees.inspections.create', $tree->id) }}">Iniciar inspección</a>@endif</section>
    @else
        @php
            $staleInput = old('inspection_id', $inspection->id) !== $inspection->id || (int) old('lock_version', $inspection->lock_version) !== $inspection->lock_version;
            $inconsistent = ($riskPreview['status'] ?? '') === 'inconsistent';
        @endphp
        <section class="panel risk-decision-context" aria-label="Inspección que se revisa"><div><span class="muted">Árbol</span><strong>{{ $tree->code }}</strong><span>{{ $tree->species?->scientific_name ?? 'Sin identificar' }}</span></div><div><span class="muted">Última inspección</span><strong>{{ $inspection->inspection_date->format('d/m/Y') }}</strong><span>{{ $inspection->inventoryTree?->inventory?->name ?? 'Sin campaña formal' }}</span></div><div><span class="muted">Prioridad profesional registrada</span><strong>{{ \App\Support\Risk\RiskPresentation::label($inspection->riskEvaluation) }}</strong>@if ($inspection->riskEvaluation?->confirmed_at)<span>{{ $inspection->riskEvaluation->confirmer?->name ?? 'Responsable no disponible' }} · {{ \App\Support\Time\BogotaDateTime::format($inspection->riskEvaluation->confirmed_at) }} (Bogotá)</span>@endif</div></section>
        <section class="panel risk-decision-suggestion" aria-label="Sugerencia automática actual"><div><p class="eyebrow">Sugerencia automática</p><h2>{{ $inconsistent ? 'Evaluación bloqueada por condiciones incompatibles' : ($riskPreview['priority_label'] ?? 'Sin sugerencia por información insuficiente') }}</h2><p class="muted">{{ $riskMethodology->name }} · versión {{ $riskMethodology->version }}</p></div>@if ($inconsistent)<p>Corrige las selecciones incompatibles de esta inspección antes de confirmar la prioridad profesional.</p>
            @elseif (($riskPreview['priority'] ?? null) === null)<p>Puedes seleccionar y confirmar una prioridad profesional aunque no haya información suficiente para una sugerencia automática. Justifica tu decisión en las observaciones generales.</p>
            @else<p>La sugerencia usa las condiciones registradas en esta inspección. La prioridad profesional se decide y confirma a continuación.</p>@endif
            @if ($riskPreview['errors'])<ul class="field-error">@foreach ($riskPreview['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
            @if ($riskPreview['warnings'])<details><summary>Alcance de la evaluación</summary><ul>@foreach ($riskPreview['warnings'] as $message)<li>{{ $message }}</li>@endforeach</ul></details>@endif
            @if ($riskPreview['determinants'])<details><summary>Condiciones que determinan la sugerencia</summary><ul>@foreach ($riskPreview['determinants'] as $item)<li>{{ $item['display_label'] }} · peso {{ $item['weight'] }}</li>@endforeach</ul></details>@endif
        </section>
        @if ($staleInput)<p class="notice">La inspección cambió después del intento anterior. <a href="{{ route('risk.trees.decision', $tree->id) }}">Actualizar esta revisión</a> antes de guardar.</p>@endif
        @if ($inconsistent)<p class="notice">Hay condiciones contradictorias en la inspección. Deben corregirse antes de confirmar el riesgo. <a href="{{ route('inspections.edit', $inspection->id) }}">Revisar condiciones de la inspección</a></p>@endif
        <form class="panel risk-decision-form" method="POST" action="{{ route('risk.trees.decision.update', $tree->id) }}" data-session-form>
            @csrf @method('PATCH')
            <input type="hidden" name="inspection_id" value="{{ old('inspection_id', $inspection->id) }}">
            <input type="hidden" name="lock_version" value="{{ old('lock_version', $inspection->lock_version) }}">
            <input type="hidden" name="risk_methodology_id" value="{{ $riskMethodology->id }}">
            <fieldset @disabled($staleInput || $inconsistent)><legend>Decisión profesional</legend>
                <div class="field"><label for="risk-decision-priority">Prioridad profesional</label><select id="risk-decision-priority" name="risk_priority" required><option value="">Selecciona una prioridad</option>@foreach (\App\Enums\Risk\RiskPriority::cases() as $priority)<option value="{{ $priority->value }}" @selected((string) old('risk_priority', $inspection->riskEvaluation?->priority) === (string) $priority->value)>{{ $priority->label() }}</option>@endforeach</select></div>
                <div class="field"><label for="risk-decision-observations">Observaciones generales y justificación</label><textarea id="risk-decision-observations" name="general_observations" rows="6" maxlength="20000" aria-describedby="risk-decision-help">{{ old('general_observations', $observations) }}</textarea><p id="risk-decision-help" class="field-hint">Justifica la prioridad si difiere de la sugerencia o no hay una sugerencia automática. Estas observaciones pertenecen a la misma inspección; conserva la información relevante que ya contiene.</p></div>
                <label class="risk-decision-confirm"><input type="checkbox" name="risk_confirmed" value="1" required> Confirmo la prioridad profesional seleccionada según las condiciones registradas en esta inspección.</label>
                <p class="field-hint">Al guardar se conserva la decisión anterior en el historial de la inspección.</p>
                <div class="form-actions"><button class="button" type="submit">Guardar riesgo</button><a class="button secondary" href="{{ route('risk.index') }}">Cancelar</a></div>
            </fieldset>
        </form>
    @endif
</section>
@endsection

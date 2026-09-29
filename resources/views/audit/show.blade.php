@extends('layouts.institutional')
@section('title', 'Detalle de auditoría')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/audit.css') }}">
@endpush
@section('content')
    <div class="audit-page">
        <a class="back-link" href="{{ $back_url }}">← Volver a auditoría</a>
        <div class="page-heading"><div><p class="eyebrow">{{ $scope->kind === 'national' ? 'AUDITORÍA NACIONAL' : $scope->organizationName }}</p><h1>{{ $entry['event_label'] }}</h1><p class="muted">{{ $entry['resource_label'] }}</p></div><span class="pill">Registro de solo lectura</span></div>
        <section class="panel audit-summary" aria-labelledby="audit-summary-title">
            <h2 id="audit-summary-title">Detalle de la operación</h2>
            <dl class="audit-facts">
                <div><dt>Fecha y hora · UTC</dt><dd>{{ $entry['occurred_at'] }}</dd></div>
                <div><dt>Usuario</dt><dd>{{ $entry['actor_name'] ?? 'No registrado' }}</dd></div>
                <div><dt>Organización</dt><dd>{{ $entry['organization_name'] ?? 'Sin organización' }}</dd></div>
                <div><dt>Acción</dt><dd>{{ $entry['event_label'] }}<span class="audit-id">{{ $entry['event'] }}</span></dd></div>
                <div><dt>Tipo de registro</dt><dd>{{ $entry['resource_label'] }}</dd></div>
                <div><dt>Identificador del registro</dt><dd class="audit-id">{{ $entry['resource_id'] ?? 'No registrado' }}</dd></div>
                <div><dt>Origen del evento</dt><dd>{{ $entry['source_label'] }}</dd></div>
                @if (($entry['version'] ?? null) !== null)<div><dt>Versión registrada</dt><dd>{{ $entry['version'] }}</dd></div>@endif
                @if ($entry['request_id'] ?? null)<div class="audit-fact-wide"><dt>Referencia de la solicitud</dt><dd class="audit-id">{{ $entry['request_id'] }}</dd></div>@endif
            </dl>
            <p class="help">Los nombres del usuario y de la organización corresponden a sus datos actuales.</p>
        </section>
        <section class="panel audit-changes" aria-labelledby="audit-changes-title">
            <div class="audit-section-heading"><div><h2 id="audit-changes-title">Valores registrados</h2><p class="muted">Comparación del estado anterior y posterior a la operación.</p></div></div>
            @if (!$entry['has_snapshots'])
                <div class="audit-empty"><h3>Este evento no conserva una comparación de valores</h3><p class="muted">Algunos eventos históricos registran la acción y su contexto sin capturar el estado anterior y posterior. La información disponible se presenta en esta ficha.</p></div>
            @elseif (count($entry['changes']) === 0)
                <p class="audit-empty muted">No se registraron diferencias entre los valores anteriores y nuevos.</p>
            @else
                <div class="audit-change-list" aria-label="Cambios de la operación">
                    @foreach ($entry['changes'] as $change)
                        <article class="audit-change"><h3>{{ $change['label'] }}</h3><dl><div><dt>Antes</dt><dd>{{ $change['before'] }}</dd></div><div><dt>Después</dt><dd>{{ $change['after'] }}</dd></div></dl></article>
                    @endforeach
                </div>
            @endif
        </section>
        @if (count($entry['metadata']) > 0)
            <section class="panel audit-context" aria-labelledby="audit-context-title"><h2 id="audit-context-title">Contexto del evento</h2><dl class="audit-facts">@foreach ($entry['metadata'] as $label => $value)<div><dt>{{ $label }}</dt><dd class="audit-value">{{ $value }}</dd></div>@endforeach</dl></section>
        @endif
        <p class="audit-footnote">Esta información pertenece a la auditoría privada de SYSFOREST.</p>
    </div>
@endsection

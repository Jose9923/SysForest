@extends('layouts.institutional')
@section('title', 'Auditoría')
@include('partials.catalog-select')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/audit.css') }}">
@endpush
@section('content')
    <div class="audit-page">
        @if ($storageNotice ?? null)<p class="panel" role="status">{{ $storageNotice }}</p>@endif
        <div class="page-heading">
            <div><p class="eyebrow">CONTROL Y TRAZABILIDAD</p><h1>Auditoría</h1><p class="muted">Consulta quién realizó una operación, cuándo ocurrió y qué cambió.</p></div>
            <span class="pill">Acceso administrativo</span>
        </div>
        <section class="audit-scope" aria-label="Alcance de la consulta">
            <div><strong>{{ $scope->label() }}</strong><p class="muted">{{ match ($scope->kind) { 'national' => 'Actividad de todas las organizaciones y de la administración nacional.', 'department' => 'Actividad de las instituciones de tus departamentos autorizados.', default => 'Actividad correspondiente a esta organización.' } }}</p></div>
            @if ($scope->kind === 'organization')<a class="button secondary" href="{{ route('audit.index') }}">{{ $canViewNational ? 'Auditoría nacional' : 'Auditoría departamental' }}</a>@endif
        </section>
        <section class="panel audit-filters" aria-labelledby="audit-filter-title">
            <div class="audit-section-heading"><h2 id="audit-filter-title">Filtrar actividad</h2><span class="muted">Fechas y horas en UTC</span></div>
            <form method="GET" action="{{ route('audit.index') }}">
                <input type="hidden" name="scope" value="{{ $scope->kind }}">
                <div class="audit-filter-grid">
                    <div class="field"><label for="audit-date-from">Desde</label><input id="audit-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
                    <div class="field"><label for="audit-date-to">Hasta</label><input id="audit-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
                    <div class="field"><label for="audit-module">Módulo</label><select id="audit-module" name="module" class="catalog-select"><option value="">Todos los módulos</option>@foreach ($moduleOptions as $key => $label)<option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field"><label for="audit-event">Acción</label><select id="audit-event" name="event" class="catalog-select"><option value="">Todas las acciones</option>@if (isset($filters['event']) && !$eventOptions->has($filters['event']))<option value="{{ $filters['event'] }}" selected>{{ $filters['event'] }} (sin actividad en este alcance)</option>@endif @foreach ($eventOptions as $key => $label)<option value="{{ $key }}" @selected(($filters['event'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field audit-filter-wide"><label for="audit-user">Usuario</label><select id="audit-user" name="user_id" class="catalog-select"><option value="">Todos los usuarios</option>@if (isset($filters['user_id']) && !$userOptions->contains(fn ($user) => (string) $user->id === $filters['user_id']))<option value="{{ $filters['user_id'] }}" selected>Usuario #{{ $filters['user_id'] }} (sin actividad en este alcance)</option>@endif @foreach ($userOptions as $user)<option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') === (string) $user->id)>{{ $user->name }} · #{{ $user->id }}</option>@endforeach</select><p class="help">Se muestran los usuarios con actividad registrada dentro de tu alcance.</p></div>
                    @if ($scope->kind !== 'organization')
                        <div class="field audit-filter-wide"><label for="audit-organization">Organización</label><select id="audit-organization" name="organization_id" class="catalog-select"><option value="">Todas las organizaciones autorizadas</option>@foreach ($organizations as $organization)<option value="{{ $organization->id }}" @selected(($filters['organization_id'] ?? '') === $organization->id)>{{ $organization->name }}</option>@endforeach</select></div>
                    @endif
                </div>
                <details class="audit-advanced" @if(isset($filters['actor']) || isset($filters['resource_type']) || isset($filters['resource_id'])) open @endif>
                    <summary>Más filtros</summary>
                    <div class="audit-filter-grid">
                        <div class="field"><label for="audit-actor">Buscar por nombre</label><input id="audit-actor" type="search" name="actor" maxlength="120" value="{{ $filters['actor'] ?? '' }}" placeholder="Parte del nombre actual"></div>
                        <div class="field"><label for="audit-resource-type">Tipo de registro</label><select id="audit-resource-type" name="resource_type" class="catalog-select"><option value="">Todos los tipos</option>@foreach ($resourceTypes as $key => $label)<option value="{{ $key }}" @selected(($filters['resource_type'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                        <div class="field audit-filter-wide"><label for="audit-resource-id">Identificador del registro</label><input id="audit-resource-id" type="text" name="resource_id" maxlength="36" value="{{ $filters['resource_id'] ?? '' }}" placeholder="UUID o identificador de usuario"></div>
                    </div>
                </details>
                <div class="form-actions audit-actions"><a class="button secondary" href="{{ route('audit.index', ['scope' => $scope->kind]) }}">Limpiar filtros</a><button class="button" type="submit">Aplicar filtros</button><button class="button secondary" type="submit" formaction="{{ route('audit.export') }}">Exportar Excel</button></div>
                <p class="help audit-export-help">El Excel incluye todos los resultados de los filtros seleccionados, con sus cambios y criterios de consulta. Máximo {{ number_format($exportLimit, 0, ',', '.') }} eventos por descarga; ajusta las fechas si necesitas reducir el resultado.</p>
            </form>
        </section>
        <section class="panel audit-results" aria-labelledby="audit-results-title">
            <div class="audit-section-heading"><div><h2 id="audit-results-title">Actividad registrada</h2><p class="muted">Los nombres de usuarios y organizaciones corresponden a sus datos actuales.</p></div><span class="pill">{{ number_format($entries->total(), 0, ',', '.') }} {{ $entries->total() === 1 ? 'evento' : 'eventos' }}</span></div>
            @if ($entries->isEmpty())
                <div class="audit-empty"><h3>No hay eventos para esta consulta</h3><p class="muted">Revisa el intervalo de fechas o cambia los filtros para consultar otra actividad dentro de tu alcance.</p></div>
            @else
                <div class="audit-table-scroll" tabindex="0" role="region" aria-label="Eventos de auditoría">
                    <table class="audit-table"><thead><tr><th scope="col">Fecha y hora · UTC</th><th scope="col">Módulo</th><th scope="col">Acción y registro</th><th scope="col">Usuario</th>@if ($scope->kind !== 'organization')<th scope="col">Organización</th>@endif<th scope="col">Detalle</th></tr></thead><tbody>
                        @foreach ($entries as $entry)
                            <tr><td class="audit-date">{{ $entry['occurred_at'] }}</td><td>{{ $entry['module_label'] }}</td><th scope="row"><span class="audit-event-title">{{ $entry['event_label'] }}</span><span class="audit-resource-name">{{ $entry['resource_label'] }}</span>@if ($entry['resource_id'] ?? null)<span class="audit-id">{{ $entry['resource_id'] }}</span>@endif</th><td>{{ $entry['actor_name'] ?? 'No registrado' }}</td>@if ($scope->kind !== 'organization')<td>{{ $entry['organization_name'] ?? 'Sin organización' }}</td>@endif<td><a class="text-link audit-detail-link" href="{{ $entry['detail_url'] }}">Ver evento<span class="audit-sr-only">: {{ $entry['event_label'] }} del {{ $entry['occurred_at'] }} UTC</span> →</a></td></tr>
                        @endforeach
                    </tbody></table>
                </div>
                @include('partials.pagination', ['paginator' => $entries])
            @endif
        </section>
        <p class="audit-footnote">Los eventos anteriores a la captura detallada de cambios pueden conservar la acción y el contexto sin valores anteriores y nuevos.</p>
    </div>
@endsection

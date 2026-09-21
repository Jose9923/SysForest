@extends('layouts.institutional')
@section('title', 'Mantenimientos')
@include('maintenances.assets')
@section('content')
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · SEGUIMIENTO DE INTERVENCIONES</p><h1>Mantenimientos</h1><p class="muted">Programación, responsables y resultados de las intervenciones del arbolado.</p>@if ($selectedTree)<p class="maintenance-context-link">Árbol <a href="{{ route('trees.show', $selectedTree->id) }}">{{ $selectedTree->code }}</a></p>@endif</div>
        @if ($canCreate)<a class="button" href="{{ $selectedTree ? route('trees.maintenances.create', $selectedTree->id) : route('trees.index') }}">{{ $selectedTree ? 'Nuevo mantenimiento' : 'Seleccionar árbol' }}</a>@endif
    </div>
    <section class="panel forestry-panel filter-panel"><form method="GET" action="{{ route('maintenances.index') }}">
        @if ($selectedTree)<input type="hidden" name="tree_id" value="{{ $selectedTree->id }}">@endif
        <div class="forestry-filters">
            <div class="field search-field"><label for="maintenance-search">Buscar mantenimiento</label><input id="maintenance-search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Código del mantenimiento o árbol"></div>
            <div class="field"><label for="maintenance-type-filter">Tipo de mantenimiento</label><select class="catalog-select" id="maintenance-type-filter" name="maintenance_type_id"><option value="">Todos los tipos</option>@foreach ($options['types'] as $type)<option value="{{ $type->id }}" @selected(($filters['maintenance_type_id'] ?? '') === $type->id)>{{ $type->name }}{{ !$type->active ? ' · inactivo' : '' }}</option>@endforeach</select></div>
            <div class="field"><label for="maintenance-status-filter">Estado del trabajo</label><select id="maintenance-status-filter" name="status"><option value="">Todos los estados</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label for="maintenance-priority-filter">Prioridad</label><select id="maintenance-priority-filter" name="priority"><option value="">Todas las prioridades</option>@foreach ($priorities as $value => $label)<option value="{{ $value }}" @selected(($filters['priority'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label for="maintenance-assignee-filter">Responsable</label><select class="catalog-select" id="maintenance-assignee-filter" name="assigned_to"><option value="">Todos los responsables</option>@foreach ($options['assignees'] as $person)<option value="{{ $person->id }}" @selected((string) ($filters['assigned_to'] ?? '') === (string) $person->id)>{{ $person->name }}</option>@endforeach</select></div>
            <div class="field"><label for="maintenance-date-from">Fecha programada desde</label><input id="maintenance-date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="field"><label for="maintenance-date-to">Fecha programada hasta</label><input id="maintenance-date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
            @if ($trashedAllowed)<div class="field"><label for="maintenance-trashed-filter">Estado del registro</label><select id="maintenance-trashed-filter" name="trashed"><option value="">Vigentes</option><option value="only" @selected(($filters['trashed'] ?? '') === 'only')>Anulados</option></select></div>@endif
        </div><div class="filter-footer"><p class="field-hint">{{ $maintenances->total() }} {{ $maintenances->total() === 1 ? 'mantenimiento encontrado' : 'mantenimientos encontrados' }}</p><div class="forestry-actions"><a class="button secondary" href="{{ route('maintenances.index', $selectedTree ? ['tree_id' => $selectedTree->id] : []) }}">Limpiar</a><button class="button">Aplicar filtros</button></div></div>
    </form></section>
    <section class="panel forestry-panel"><span class="section-kicker">PROGRAMACIÓN Y SEGUIMIENTO</span><h2>Intervenciones registradas</h2>
        @include('maintenances.timeline', ['records' => $maintenances])
    </section>
    <div class="maintenance-pagination">{{ $maintenances->links() }}</div>
@endsection

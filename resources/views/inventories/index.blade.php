@extends('layouts.institutional')
@section('title', 'Inventarios')
@include('forestry.assets')
@section('content')
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · CAMPAÑAS</p><h1>Inventarios forestales</h1><p class="muted">Organiza las campañas y consulta sus árboles vinculados.</p></div>
        @if ($canCreate)<a class="button" href="{{ route('inventories.create') }}">+ Nuevo inventario</a>@endif
    </div>
    <form class="panel forestry-panel filter-panel" method="GET" action="{{ route('inventories.index') }}">
        <div class="forestry-filters">
            <div class="field search-field"><label for="inventory-q">Buscar inventario</label><input id="inventory-q" name="q" maxlength="120" value="{{ $filters['q'] ?? '' }}" placeholder="Código o nombre"></div>
            <div class="field"><label for="inventory-status">Estado de la campaña</label><select id="inventory-status" name="status"><option value="">Todos los estados</option>@foreach ($inventoryStatuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="filter-footer"><div class="field"><label for="inventory-active">Disponibilidad administrativa</label><select id="inventory-active" name="active"><option value="">Todas</option><option value="1" @selected((string) ($filters['active'] ?? '') === '1')>Disponible</option><option value="0" @selected((string) ($filters['active'] ?? '') === '0')>Inactiva</option></select></div><div class="forestry-actions"><a class="button secondary" href="{{ route('inventories.index') }}">Limpiar</a><button class="button">Aplicar filtros</button></div></div>
    </form>
    <p class="muted" style="margin-bottom:18px">{{ $inventories->total() }} {{ $inventories->total() === 1 ? 'inventario encontrado' : 'inventarios encontrados' }}</p>
    <div class="organization-grid">
        @forelse ($inventories as $entry)
            @php($statusValue = $entry->status instanceof \BackedEnum ? $entry->status->value : $entry->status)
            <article class="organization-card inventory-card">
                <div class="card-top"><span class="org-monogram" aria-hidden="true">▤</span><div class="forestry-flags"><span class="pill">{{ $inventoryStatuses[$statusValue] ?? $statusValue }}</span>@unless ($entry->active)<span class="pill neutral">Inactivo</span>@endunless</div></div>
                <p class="code">{{ $entry->code }}</p><h2><a href="{{ route('inventories.show', $entry->id) }}">{{ $entry->name }}</a></h2>
                <p class="inventory-dates">{{ $entry->starts_at?->format('d/m/Y') ?? 'Inicio sin definir' }} — {{ $entry->ends_at?->format('d/m/Y') ?? 'Fin sin definir' }}</p>
                <div class="card-bottom"><div><span class="inventory-stat">{{ $entry->trees_count }}</span><span class="muted"> árboles</span></div><a class="button secondary" href="{{ route('inventories.show', $entry->id) }}">Abrir inventario →</a></div>
            </article>
        @empty
            <section class="panel forestry-empty"><span class="empty-symbol" aria-hidden="true">▤</span><h2>No hay inventarios para mostrar</h2><p>Ajusta los filtros o crea una campaña para organizar el registro de árboles.</p>@if ($canCreate)<a class="button" href="{{ route('inventories.create') }}">Crear inventario</a>@endif</section>
        @endforelse
    </div>
    @include('partials.pagination', ['paginator' => $inventories])
@endsection

@extends('layouts.institutional')
@section('title', $inventory->name)
@include('forestry.assets')
@section('content')
    @php($statusValue = $inventory->status instanceof \BackedEnum ? $inventory->status->value : $inventory->status)
    <a class="back-link" href="{{ route('inventories.index') }}">← Volver a inventarios</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · {{ $inventory->code }}</p><h1>{{ $inventory->name }}</h1><div class="forestry-flags"><span class="pill">{{ $inventoryStatuses[$statusValue] ?? $statusValue }}</span><span class="pill {{ $inventory->active ? '' : 'neutral' }}">{{ $inventory->active ? 'Disponible' : 'Inactivo' }}</span></div></div>
        @if ($canUpdate)<a class="button secondary" href="{{ route('inventories.edit', $inventory->id) }}">Editar inventario</a>@endif
    </div>
    <div class="detail-grid">
        <section class="panel forestry-panel"><span class="section-kicker">CAMPAÑA</span><h2>Información del inventario</h2><dl class="details forestry-details">
            <div><dt>Código</dt><dd>{{ $inventory->code }}</dd></div><div><dt>Organización</dt><dd>{{ $organization->name }}</dd></div>
            <div><dt>Inicio</dt><dd>{{ $inventory->starts_at?->format('d/m/Y') ?? 'Sin definir' }}</dd></div><div><dt>Finalización</dt><dd>{{ $inventory->ends_at?->format('d/m/Y') ?? 'Sin definir' }}</dd></div>
            <div class="wide"><dt>Descripción</dt><dd class="preserve-lines">{{ $inventory->description ?: 'Sin descripción' }}</dd></div>
        </dl></section>
        <section class="panel forestry-panel"><span class="section-kicker">ÁRBOLES VINCULADOS</span><p class="inventory-stat">{{ $inventory->trees_count }}</p><h2>árboles en esta campaña</h2><p class="muted">Consulta las fichas permanentes y su ubicación desde la bandeja filtrada.</p><div class="form-actions"><a class="button" href="{{ route('trees.index', ['inventory_id' => $inventory->id]) }}">Ver árboles del inventario →</a></div></section>
    </div>
@endsection

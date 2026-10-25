@extends('layouts.institutional')
@section('title', 'Reglas de clasificación')
@push('styles')<link rel="stylesheet" href="{{ asset('css/catalogs.css') }}">@endpush
@section('content')
<a class="back-link" href="{{ route('catalogs.dashboard') }}">← Catálogos forestales</a>
<div class="page-heading"><div><p class="eyebrow">CLASIFICACIÓN NACIONAL</p><h1>Reglas de clasificación</h1><p class="muted">Intervalos de diámetro y altura, y bandas de madurez del inventario.</p></div><span class="pill">{{ $canManage ? 'Administración nacional' : 'Solo consulta' }}</span></div>
<div class="catalog-callout"><strong>Cada versión conserva sus reglas.</strong><p>Puedes consultar los límites y probar una medida. Los cambios de fórmula requieren una nueva versión para preservar resultados históricos.</p></div>
<section class="panel">
    <form method="GET" action="{{ route('classification.index') }}" class="catalog-filters"><x-field name="q" label="Buscar versión" :value="$filters['q'] ?? ''" maxlength="120" /><div class="field"><label for="active">Disponibilidad</label><select name="active" id="active"><option value="">Todas</option><option value="1" @selected(($filters['active'] ?? '') === '1')>Activas</option><option value="0" @selected(($filters['active'] ?? '') === '0')>Inactivas</option></select></div><button class="button secondary">Buscar</button></form>
    <div class="catalog-table-wrap" tabindex="0" role="region" aria-label="Versiones de clasificación"><table class="catalog-table"><thead><tr><th>Orden</th><th>Versión</th><th>Diámetro</th><th>Altura</th><th>Disponibilidad</th><th>Consulta</th></tr></thead><tbody>@forelse ($versions as $version)<tr><td>{{ $version->sort_order }}</td><td><strong>{{ $version->name }}</strong><code>{{ \App\Support\ClassificationVersionPresentation::label($version->code) }}</code></td><td>{{ $version->diameter_step }} {{ $version->diameter_unit }}</td><td>{{ $version->height_step }} {{ $version->height_unit }}</td><td><span class="pill {{ $version->active ? '' : 'catalog-inactive' }}">{{ $version->active ? 'Activa' : 'Inactiva' }}</span></td><td><a class="text-link" href="{{ route('classification.show', $version->id) }}">Ver reglas</a></td></tr>@empty<tr><td colspan="6">No hay versiones para esta búsqueda.</td></tr>@endforelse</tbody></table></div>
    @include('catalogs.partials.pagination', ['paginator' => $versions])
</section>
@endsection

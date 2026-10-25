@extends('layouts.institutional')
@section('title', $catalog->title)
@push('styles')<link rel="stylesheet" href="{{ asset('css/catalogs.css') }}">@endpush
@section('content')
    <a class="back-link" href="{{ route('catalogs.dashboard') }}">← Catálogos forestales</a>
    <div class="page-heading"><div><p class="eyebrow">CATÁLOGO NACIONAL · {{ $catalog->group }}</p><h1>{{ $catalog->title }}</h1><p class="muted">{{ $catalog->description }}</p></div>
        @if ($canManage)<a class="button" href="{{ route('catalogs.create', $catalog->key) }}">+ Crear valor</a>@else<span class="pill">Solo consulta</span>@endif
    </div>
    <section class="panel">
        <form class="catalog-filters" method="GET" action="{{ route('catalogs.index', $catalog->key) }}">
            <x-field name="q" label="Buscar por nombre o código" :value="$filters['q'] ?? ''" maxlength="120" />
            <div class="field"><label for="active">Disponibilidad</label><select id="active" name="active"><option value="">Todos</option value="1" @selected(($filters['active'] ?? '') === '1')>Activos</option><option value="0" @selected(($filters['active'] ?? '') === '0')>Inactivos</option></select></div>
            <button class="button secondary">Buscar</button><a class="text-link" href="{{ route('catalogs.index', $catalog->key) }}">Limpiar</a>
        </form>
        <div class="catalog-table-wrap" tabindex="0" role="region" aria-label="Valores de {{ $catalog->title }}">
            <table class="catalog-table"><caption>{{ $entries->total() }} valores · Los inactivos se conservan para consulta histórica.</caption><thead><tr><th scope="col">Orden</th><th scope="col">Valor</th><th scope="col">Código</th><th scope="col">Disponibilidad</th>@if ($canManage)<th scope="col">Acciones</th>@endif</tr></thead>
                <tbody>@forelse ($entries as $entry)<tr><td>{{ $entry->sort_order }}</td><td><strong>{{ $entry->name }}</strong>@if ($entry->description)<small>{{ \App\Support\ExcelReferencePresentation::text($entry->description) }}</small>@endif</td><td><code>{{ $entry->code }}</code></td><td><span class="pill {{ $entry->active ? '' : 'catalog-inactive' }}">{{ $entry->active ? 'Activo' : 'Inactivo' }}</span></td>@if ($canManage)<td><a class="text-link" href="{{ route('catalogs.edit', [$catalog->key, $entry->id]) }}">Editar<span class="visually-hidden"> {{ $entry->name }}</span></a></td>@endif</tr>@empty<tr><td colspan="{{ $canManage ? 5 : 4 }}" class="catalog-empty">No hay valores que coincidan con la búsqueda.</td></tr>@endforelse</tbody>
            </table>
        </div>
        @include('catalogs.partials.pagination', ['paginator' => $entries])
    </section>
@endsection

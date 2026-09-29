@extends('layouts.institutional')
@section('title', $title)
@push('styles')<link rel="stylesheet" href="{{ asset('css/domain-catalogs.css') }}">@endpush
@section('content')
    <div class="page-heading"><div><p class="eyebrow">CATÁLOGOS FORESTALES NACIONALES</p><h1>{{ $title }}</h1><p class="muted">Valores compartidos por todas las organizaciones de SYSFOREST.</p></div>
        @if ($canManage)<a class="button" href="{{ route('taxonomy.create', ['catalog' => $catalog]) }}">+ Crear valor</a>@endif
    </div>
    <nav class="catalog-tabs" aria-label="Catálogos relacionados"><a class="button secondary" href="{{ route('taxonomy.index', ['catalog' => 'families']) }}">Familias</a><a class="button secondary" href="{{ route('taxonomy.index', ['catalog' => 'species']) }}">Especies</a><a class="button secondary" href="{{ route('taxonomy.index', ['catalog' => 'common-names']) }}">Nombres comunes</a></nav>
    <form class="panel catalog-tools" method="GET" action="{{ route('taxonomy.index', ['catalog' => $catalog]) }}">
        <x-field name="q" label="Buscar por nombre o código" :value="$filters['q']" maxlength="150" />
        @if ($canManage)<div class="field"><label for="active">Estado</label><select id="active" name="active"><option value="all" @selected($filters['active'] === 'all')>Todos</option><option value="1" @selected($filters['active'] === '1')>Activos</option><option value="0" @selected($filters['active'] === '0')>Inactivos</option></select></div>@endif
        
        <button class="button">Buscar</button>
    </form>
    <p class="catalog-note">{{ $records->total() }} valores. @if ($canManage)Editar permite cambiar descripción, estado y orden. Desactivar conserva las referencias históricas.@else Consulta de valores activos. La administración está reservada al administrador nacional.@endif</p>
    <div class="panel catalog-scroll" tabindex="0" role="region" aria-label="Valores de {{ $title }}"><table class="catalog-table"><thead><tr><th scope="col">Nombre</th><th scope="col">Código</th><th scope="col">Relación</th><th scope="col">Orden</th><th scope="col">Estado</th>@if ($canManage)<th scope="col">Acciones</th>@endif</tr></thead><tbody>
    @forelse ($records as $entry)
        <tr><td>{{ $entry->getAttribute($nameColumn) }}</td><td><code>{{ $entry->code }}</code></td><td>@if ($catalog === 'species'){{ $entry->family->scientific_name }}@elseif ($catalog === 'common-names'){{ $entry->species->scientific_name }} @if ($entry->is_primary)<span class="pill">Principal</span>@endif @else — @endif</td><td>{{ $entry->sort_order }}</td><td><span class="pill {{ $entry->active ? '' : 'catalog-inactive' }}">{{ $entry->active ? 'Activo' : 'Inactivo' }}</span></td>@if ($canManage)<td><a class="button secondary" href="{{ route('taxonomy.edit', ['catalog' => $catalog, 'record' => $entry->id]) }}">Editar</a></td>@endif</tr>
    @empty
        <tr><td colspan="6">No hay valores con estos filtros.</td></tr>
    @endforelse
    </tbody></table></div>
    @include('partials.pagination', ['paginator' => $records])
@endsection

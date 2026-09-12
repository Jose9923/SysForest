@extends('layouts.institutional')
@section('title', 'Catálogos forestales')
@push('styles')<link rel="stylesheet" href="{{ asset('css/catalogs.css') }}">@endpush
@section('content')
    <div class="page-heading">
        <div><p class="eyebrow">BASE FORESTAL COMPARTIDA</p><h1>Catálogos forestales</h1><p class="muted">Valores nacionales para una clasificación consistente en todas las organizaciones.</p></div>
        <span class="pill">{{ $canManage ? 'Administración nacional' : 'Solo consulta' }}</span>
    </div>
    <div class="catalog-callout">
        <strong>Un catálogo común para todo SYSFOREST</strong>
        <p>{{ $canManage ? 'Administra etiquetas, disponibilidad y orden. Los códigos conservan su identidad y los valores inactivos mantienen sus referencias.' : 'Puedes consultar los valores nacionales. Su administración está a cargo de SUPER_ADMIN.' }}</p>
    </div>
    <h2>Taxonomía y diagnóstico</h2>
    <div class="catalog-grid catalog-featured">
        <a class="catalog-card" href="{{ route('taxonomy.index', ['catalog' => 'families']) }}"><span class="eyebrow">IDENTIFICACIÓN</span><h3>Familias y especies</h3><p>Familias taxonómicas, especies y sus nombres comunes.</p><span class="text-link">Explorar taxonomía →</span></a>
        <a class="catalog-card" href="{{ route('conditions.index', ['catalog' => 'categories']) }}"><span class="eyebrow">DIAGNÓSTICO</span><h3>Condiciones forestales</h3><p>Condiciones físico-mecánicas y fitosanitarias organizadas por órgano.</p><span class="text-link">Explorar condiciones →</span></a>
        <a class="catalog-card" href="{{ route('classification.index') }}"><span class="eyebrow">CLASIFICACIÓN</span><h3>Reglas de clasificación</h3><p>Clases diamétricas, clases de altura y bandas de madurez con versión.</p><span class="text-link">Consultar reglas →</span></a>
    </div>
    <h2>Valores de referencia</h2>
    <div class="catalog-grid">
        @foreach ($cards as $card)
            <a class="catalog-card" href="{{ route('catalogs.index', $card['catalog']->key) }}">
                <span class="eyebrow">{{ $card['catalog']->group }}</span><h3>{{ $card['catalog']->title }}</h3>
                <p>{{ $card['catalog']->description }}</p>
                <div class="catalog-card-footer"><span>{{ $card['counts']['active'] }} activos · {{ $card['counts']['total'] }} valores</span><span aria-hidden="true">→</span></div>
            </a>
        @endforeach
    </div>
@endsection

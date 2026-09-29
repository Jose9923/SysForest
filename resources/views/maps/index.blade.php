@extends('layouts.institutional')
@section('title', 'Mapa general')
@include('maps.assets')
@include('reports.assets')
@section('content')
    <section class="general-map" data-general-map>
        <div class="page-heading map-heading"><div><p class="eyebrow">LECTURA TERRITORIAL · {{ $mapConfig['scope']['label'] }}</p><h1>Mapa general</h1><p class="muted">Explora el arbolado, su última inspección y sus mantenimientos dentro de tu alcance autorizado.</p></div><div class="report-entry-actions">@can('reports.view')<a class="button secondary" href="{{ route('reports.index', array_merge(array_diff_key($initialFilters, array_flip(['page', 'render_mode', 'term', 'format', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id'])), ['scope' => $mapConfig['scope']['mode'], 'report' => 'inventory'])) }}" data-report-link="{{ route('reports.index') }}">Reportes y descargas</a>@endcan<a class="map-help-link" href="#map-help">Cómo explorar</a></div></div>
        <noscript><div class="notice">Activa JavaScript para consultar el mapa interactivo. La navegación institucional sigue disponible.</div></noscript>
        <input type="hidden" data-map-csrf value="{{ csrf_token() }}">
        @include('maps.panel', ['publicMap' => false])
    </section>
    <script type="application/json" id="map-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $mapConfig, 'filters' => $initialFilters]) !!}</script>
@endsection

@extends('layouts.institutional')
@section('title', 'Cartografía del arbolado')
@include('maps.assets')
@include('reports.assets')
@section('content')
    <section class="general-map" data-general-map>
        <div class="page-heading map-heading"><div><p class="eyebrow">CARTOGRAFÍA · {{ $mapConfig['scope']['label'] }}</p><h1>El bosque, en contexto.</h1><p class="muted">Un espacio para explorar el arbolado, planear recorridos y entender cómo se distribuye en el territorio.</p></div><div class="report-entry-actions">@can('reports.view')<a class="button secondary" href="{{ route('reports.index', array_merge(array_diff_key($initialFilters, array_flip(['page', 'render_mode', 'term', 'format', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id'])), ['scope' => $mapConfig['scope']['mode'], 'report' => 'inventory'])) }}" data-report-link="{{ route('reports.index') }}">Reportes y descargas</a>@endcan<a class="map-help-link" href="#map-help">Cómo explorar</a></div></div>
        @can('reports.view')<a class="map-analytics-link" href="{{ route('analytics.index', array_diff_key($initialFilters, array_flip(['page', 'render_mode', 'term', 'format', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id']))) }}" data-map-analytics-link="{{ route('analytics.index') }}">Ver diagramas y gráficas <span aria-hidden="true">→</span></a>@endcan
        <div class="map-workspaces" aria-label="Espacios de cartografía">
            <button type="button" class="map-workspace" data-map-workspace="operation" aria-pressed="true"><span class="map-workspace-symbol" aria-hidden="true">◎</span><span><strong>Mapa operativo</strong><small>Árboles, fichas y recorridos</small></span><span class="map-workspace-arrow" aria-hidden="true">↗</span></button>
            <button type="button" class="map-workspace" data-map-workspace="analysis" aria-pressed="false"><span class="map-workspace-symbol" aria-hidden="true">▦</span><span><strong>Análisis territorial</strong><small>Densidad, distribución y contexto</small></span><span class="map-workspace-arrow" aria-hidden="true">↗</span></button>
        </div>
        <p class="map-workspace-note" data-map-workspace-note>Localiza árboles, abre sus fichas y prepara recorridos con filtros, capas y herramientas de lectura.</p>
        <noscript><div class="notice">Activa JavaScript para consultar el mapa interactivo. La navegación institucional sigue disponible.</div></noscript>
        <input type="hidden" data-map-csrf value="{{ csrf_token() }}">
        @include('maps.panel', ['publicMap' => false])
    </section>
    <script type="application/json" id="map-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $mapConfig, 'filters' => $initialFilters]) !!}</script>
@endsection

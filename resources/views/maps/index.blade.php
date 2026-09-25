@extends('layouts.institutional')
@section('title', 'Mapa general')
@include('maps.assets')
@section('content')
    <section class="general-map" data-general-map>
        <div class="page-heading map-heading"><div><p class="eyebrow">LECTURA TERRITORIAL · {{ $mapConfig['scope']['label'] }}</p><h1>Mapa general</h1><p class="muted">Explora el arbolado, su última inspección y sus mantenimientos dentro de tu alcance autorizado.</p></div><a class="map-help-link" href="#map-help">Cómo explorar</a></div>
        <noscript><div class="notice">Activa JavaScript para consultar el mapa interactivo. La navegación institucional sigue disponible.</div></noscript>
        <input type="hidden" data-map-csrf value="{{ csrf_token() }}">
        @include('maps.panel', ['publicMap' => false])
    </section>
    <script type="application/json" id="map-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $mapConfig, 'filters' => $initialFilters]) !!}</script>
@endsection

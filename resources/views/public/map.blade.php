@extends('public.layout')
@include('public.assets', ['mode' => 'map'])
@section('content')
    <section class="general-map" data-general-map>
        <div class="page-heading map-heading"><div><p class="eyebrow">EXPLORA EL TERRITORIO</p><h1>Mapa del arbolado</h1><p class="muted">Consulta árboles publicados, su última inspección publicada y los mantenimientos realizados.</p></div><a class="button secondary" href="{{ route('public.trees.index', $filters ?? []) }}" data-public-list-link="{{ route('public.trees.index') }}">Ver como listado</a></div>
        <noscript><div class="public-notice">Activa JavaScript para explorar el mapa. También puedes <a href="{{ route('public.trees.index', $filters ?? []) }}">consultar el inventario en forma de listado</a>.</div></noscript>
        @include('maps.panel', ['publicMap' => true, 'filterAction' => route('public.map')])
    </section>
    <script type="application/json" id="map-bootstrap">{!! \Illuminate\Support\Js::encode(['config' => $mapConfig, 'filters' => $initialFilters]) !!}</script>
@endsection

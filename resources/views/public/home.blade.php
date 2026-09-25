@extends('public.layout')
@section('content')
    <section class="public-hero">
        <div><p class="eyebrow">EL ARBOLADO DE TU TERRITORIO</p><h1>Conoce los árboles<br>que nos rodean.</h1><p>Explora el inventario forestal urbano publicado por las entidades participantes. Consulta especies, ubicación e información forestal de cada árbol.</p><div class="public-actions"><a class="button" href="{{ $mapUrl }}">Explorar el mapa <span aria-hidden="true">→</span></a><a class="button secondary" href="{{ $listUrl }}">Consultar inventario</a></div></div>
        <div class="public-hero-card"><span class="public-hero-icon" aria-hidden="true">♧</span><p class="eyebrow">CONSULTA ABIERTA</p><h2>Un territorio,<br>muchas especies.</h2><p>Busca por código o nombre y conoce la información publicada de su entorno.</p><form method="GET" action="{{ route('public.trees.index') }}"><label for="public-home-search">Buscar árbol o especie</label><div class="public-search-row"><input id="public-home-search" name="q" maxlength="150" placeholder="Código, nombre común o científico"><button class="button" type="submit">Buscar</button></div></form></div>
    </section>
    @include('public.indicators')
    @if (($indicators['trees'] ?? 0) === 0)
        <div class="public-empty"><h2>Aún no hay árboles publicados</h2><p>La información aparecerá aquí cuando las entidades publiquen sus inventarios.</p></div>
    @else
        <section class="public-section"><div class="public-section-heading"><div><p class="eyebrow">INFORMACIÓN DISPONIBLE</p><h2>El inventario, en contexto</h2></div><p class="muted">{{ number_format($indicators['georeferenced'] ?? 0, 0, ',', '.') }} árboles con ubicación · {{ number_format($indicators['without_geometry'] ?? 0, 0, ',', '.') }} sin ubicación registrada</p></div><div class="public-state-grid">@foreach ($indicators['states'] ?? [] as $state)<div><span>{{ $state['label'] }}</span><strong>{{ number_format($state['count'], 0, ',', '.') }}</strong></div>@endforeach</div></section>
    @endif
    <section class="public-about" id="acerca"><div><p class="eyebrow">ACERCA DEL SIGAU</p><h2>Información forestal<br>al alcance de todos.</h2></div><div><p>SYSFOREST reúne información del arbolado urbano compartida por las organizaciones participantes. El mapa y el inventario permiten explorar los mismos árboles mediante filtros de territorio, especie, estado e inspección publicada.</p><p>Las fichas muestran datos forestales, fotografías autorizadas e intervenciones publicadas. La información puede cambiar a medida que las entidades registran y publican nuevas inspecciones.</p><p class="public-about-note">Los colores del mapa de calor representan concentración de árboles. No indican un nivel de riesgo.</p></div></section>
@endsection

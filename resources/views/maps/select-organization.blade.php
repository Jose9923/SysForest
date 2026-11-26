@extends('layouts.institutional')
@section('title', $selectionTitle)
@section('content')
    <div class="page-heading"><div><p class="eyebrow">SYSFOREST</p><h1>{{ $selectionTitle }}</h1></div></div>
    <section class="panel empty">
        <h2>Selecciona una organización</h2>
        <p>Esta consulta abre los datos de la organización seleccionada. Primero elige la institución con la que vas a trabajar.</p>
        <a class="button" href="{{ route('organizations.index') }}">Seleccionar organización</a>
        @if ($wideScopeLinks !== [])
            <p>Tus permisos también permiten elegir expresamente una vista con varias organizaciones:</p>
            <nav aria-label="Consultar otras organizaciones autorizadas">
                @foreach ($wideScopeLinks as $link)<p><a class="button secondary" href="{{ $link['url'] }}">{{ $link['label'] }}</a></p>@endforeach
            </nav>
        @endif
    </section>
@endsection

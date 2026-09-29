@extends('public.layout')
@section('content')
    <section class="public-error panel">
        <p class="eyebrow">CONSULTA PÚBLICA</p>
        <h1>{{ $status === 404 ? 'Información no disponible' : 'No se pudo completar la consulta' }}</h1>
        <p>{{ $message }}</p>
        @if (isset($validationErrors) && count($validationErrors))
            <ul>
                @foreach ($validationErrors as $messages)
                    @foreach ($messages as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                @endforeach
            </ul>
        @endif
        <div class="public-actions">
            <a class="button" href="{{ route('public.trees.index') }}">Consultar inventario</a>
            <a class="button secondary" href="{{ route('public.home') }}">Volver al inicio</a>
        </div>
    </section>
@endsection

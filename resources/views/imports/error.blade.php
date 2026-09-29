@extends('layouts.institutional')
@section('title', 'Importación no disponible')
@section('content')
    <section class="card">
        <h1>Revisa la importación</h1>
        <p>{{ $message }}</p>
        @if ($errors !== [])
            <ul>
                @foreach ($errors as $messages)
                    @foreach ($messages as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                @endforeach
            </ul>
        @endif
        <a class="button" href="{{ route('imports.index') }}">Volver a importaciones</a>
    </section>
@endsection

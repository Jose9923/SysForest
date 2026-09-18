@extends('layouts.institutional')
@section('title', 'Operación de evidencia')
@section('content')
    <section class="panel access-panel"><p class="eyebrow">EVIDENCIAS · {{ $status }}</p><h1>No se completó la operación</h1>
        <p>{{ $message }}</p><a class="button" href="{{ route('trees.index') }}">Volver a árboles</a>
    </section>
@endsection

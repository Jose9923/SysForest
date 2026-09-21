@extends('layouts.institutional')
@section('title', 'Operación no disponible')
@section('content')
    <section class="panel access-panel"><p class="eyebrow">MANTENIMIENTOS · {{ $status }}</p><h1>La operación no pudo completarse</h1><p>{{ $message }}</p><a class="button" href="{{ route('maintenances.index') }}">Volver a mantenimientos</a></section>
@endsection

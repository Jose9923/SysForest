@extends('layouts.institutional')
@section('title', 'Registro modificado')
@section('content')
    @php($inspectionConflict = ($exception ?? null) instanceof \App\Exceptions\StaleInspectionException)
    <section class="panel access-panel"><span class="large-symbol" aria-hidden="true">↻</span><p class="eyebrow">CONFLICTO DE ACTUALIZACIÓN</p><h1>{{ $inspectionConflict ? 'La inspección cambió' : 'El registro cambió' }}</h1>
        <p>{{ $inspectionConflict ? 'Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.' : 'Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.' }}</p>
        @if ($inspectionConflict)<a class="button" href="{{ route('inspections.index') }}">Volver a inspecciones</a>@else<a class="button" href="{{ route('trees.index') }}">Volver a árboles</a> <a class="button secondary" href="{{ route('inventories.index') }}">Volver a inventarios</a>@endif
    </section>
@endsection

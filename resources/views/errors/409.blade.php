@extends('layouts.institutional')
@section('title', 'Registro modificado')
@section('content')
    <section class="panel access-panel"><span class="large-symbol" aria-hidden="true">↻</span><p class="eyebrow">CONFLICTO DE ACTUALIZACIÓN</p><h1>El registro cambió</h1><p>Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.</p><a class="button" href="{{ route('trees.index') }}">Volver a árboles</a> <a class="button secondary" href="{{ route('inventories.index') }}">Volver a inventarios</a></section>
@endsection

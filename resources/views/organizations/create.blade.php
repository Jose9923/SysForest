@extends('layouts.institutional')
@section('title', 'Crear organización')
@section('content')
    <a class="back-link" href="{{ route('organizations.index') }}">← Tus organizaciones</a>
    <div class="page-heading"><div><p class="eyebrow">NUEVA INSTITUCIÓN</p><h1>Crear organización</h1><p class="muted">Define su identidad y el municipio al que pertenece.</p></div></div>
    <form class="panel organization-form" method="POST" action="{{ route('organizations.store') }}">
        @csrf
        @include('organizations.partials.form')
        <div class="form-actions"><a class="button secondary" href="{{ route('organizations.index') }}">Cancelar</a><button class="button">Crear organización</button></div>
    </form>
@endsection

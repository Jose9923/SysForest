@extends('layouts.institutional')
@section('title', 'Editar organización')
@section('content')
    <a class="back-link" href="{{ route('organizations.show', $organization->id) }}">← Volver a la organización</a>
    <div class="page-heading"><div><p class="eyebrow">{{ $organization->code }}</p><h1>Editar organización</h1><p class="muted">Actualiza la información institucional y su ubicación.</p></div></div>
    <form class="panel organization-form" method="POST" action="{{ route('organizations.update', $organization->id) }}">
        @csrf @method('PUT')
        @include('organizations.partials.form')
        <div class="form-actions"><a class="button secondary" href="{{ route('organizations.show', $organization->id) }}">Cancelar</a><button class="button">Guardar cambios</button></div>
    </form>
@endsection

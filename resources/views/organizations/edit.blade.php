@extends('layouts.institutional')
@section('title', 'Editar organización')
@section('content')
    <a class="back-link" href="{{ ($administrative ?? false) ? route('organizations.index') : route('organizations.show', $organization->id) }}">← Volver</a>
    <div class="page-heading"><div><p class="eyebrow">{{ $organization->code }}</p><h1>Editar organización</h1><p class="muted">Actualiza la información institucional y su ubicación.</p></div></div>
    <form class="panel organization-form" method="POST" action="{{ ($administrative ?? false) ? route('administration.organizations.update', $organization->id) : route('organizations.update', $organization->id) }}">
        @csrf @method('PUT')
        @include('organizations.partials.form')
        <div class="form-actions"><a class="button secondary" href="{{ ($administrative ?? false) ? route('organizations.index') : route('organizations.show', $organization->id) }}">Cancelar</a><button class="button">Guardar cambios</button></div>
    </form>
@endsection

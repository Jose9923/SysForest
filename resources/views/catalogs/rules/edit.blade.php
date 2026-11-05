@extends('layouts.institutional')
@section('title', 'Editar versión '.$version->code)
@push('styles')<link rel="stylesheet" href="{{ asset('css/catalogs.css') }}">@endpush
@section('content')
<a class="back-link" href="{{ route('classification.show', $version->id) }}">← {{ $version->code }}</a>
<div class="page-heading"><div><p class="eyebrow">ADMINISTRACIÓN NACIONAL</p><h1>Editar versión</h1><p class="muted">El nombre, la disponibilidad y el orden pueden cambiar. Los límites y las bandas conservan su definición.</p></div></div>
<form method="POST" action="{{ route('classification.update', $version->id) }}" class="panel catalog-form">@csrf @method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version', $version->lock_version) }}"><div class="form-grid">
    <div class="wide"><x-field name="name" label="Nombre de la versión" :value="$version->name" required maxlength="180" /></div>
    <div class="field wide"><label for="description">Descripción</label><textarea id="description" name="description" maxlength="4000" rows="3">{{ \App\Support\Imports\ExcelReferencePresentation::text(old('description', $version->description)) }}</textarea></div>
    <x-field name="sort_order" label="Orden de presentación" type="number" :value="$version->sort_order" min="0" max="2147483647" required />
    <div class="field"><label for="active">Disponibilidad</label><select id="active" name="active"><option value="1" @selected((string) old('active', (int) $version->active) === '1')>Activa</option><option value="0" @selected((string) old('active', (int) $version->active) === '0')>Inactiva</option></select></div>
</div><div class="form-actions"><a class="button secondary" href="{{ route('classification.show', $version->id) }}">Cancelar</a><button class="button">Guardar metadatos</button></div></form>
@endsection

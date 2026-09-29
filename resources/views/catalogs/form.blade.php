@extends('layouts.institutional')
@section('title', ($entry ? 'Editar' : 'Crear').' · '.$catalog->title)
@push('styles')<link rel="stylesheet" href="{{ asset('css/catalogs.css') }}">@endpush
@section('content')
    <a class="back-link" href="{{ route('catalogs.index', $catalog->key) }}">← {{ $catalog->title }}</a>
    <div class="page-heading"><div><p class="eyebrow">ADMINISTRACIÓN NACIONAL</p><h1>{{ $entry ? 'Editar valor' : 'Crear valor' }}</h1><p class="muted">{{ $catalog->title }} · Disponible para todas las organizaciones.</p></div></div>
    <form class="panel catalog-form" method="POST" action="{{ $entry ? route('catalogs.update', [$catalog->key, $entry->id]) : route('catalogs.store', $catalog->key) }}">
        @csrf @if ($entry)@method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version', $entry->lock_version) }}">@endif
        <div class="form-grid">
            <div><x-field name="code" label="Código técnico" :value="$entry?->code ?? ''" :readonly="(bool) $entry" required maxlength="80" pattern="[A-Z][A-Z0-9_]{1,79}" aria-describedby="code-help" /><p class="help" id="code-help">Mayúsculas, números y guion bajo. El código se conserva después de la creación.</p></div>
            <x-field name="name" label="Nombre en español" :value="$entry?->name ?? ''" required maxlength="180" />
            <div class="field wide"><label for="description">Descripción <span class="muted">(opcional)</span></label><textarea id="description" name="description" rows="3" maxlength="4000">{{ old('description', $entry?->description ?? '') }}</textarea>@error('description')<span class="field-error">{{ $message }}</span>@enderror</div>
            <x-field name="sort_order" label="Orden de presentación" type="number" :value="$entry?->sort_order ?? 0" min="0" max="2147483647" step="1" required />
            <div class="field"><label for="active">Disponibilidad</label><select id="active" name="active" required><option value="1" @selected((string) old('active', $entry ? (int) $entry->active : 1) === '1')>Activo</option><option value="0" @selected((string) old('active', $entry ? (int) $entry->active : 1) === '0')>Inactivo</option></select><p class="help">Un valor inactivo conserva sus referencias y se excluye de nuevas selecciones.</p></div>
        </div>
        <div class="form-actions"><a class="button secondary" href="{{ route('catalogs.index', $catalog->key) }}">Cancelar</a><button class="button">Guardar valor</button></div>
    </form>
@endsection

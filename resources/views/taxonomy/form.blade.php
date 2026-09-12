@extends('layouts.institutional')
@section('title', ($record ? 'Editar · ' : 'Crear · ').$title)
@push('styles')<link rel="stylesheet" href="{{ asset('css/domain-catalogs.css') }}">@endpush
@include('partials.catalog-select')
@section('content')
    <a class="back-link" href="{{ route('taxonomy.index', ['catalog' => $catalog]) }}">← {{ $title }}</a>
    <div class="page-heading"><div><p class="eyebrow">TAXONOMÍA NACIONAL</p><h1>{{ $record ? 'Editar valor' : 'Crear valor' }}</h1><p class="muted">{{ $title }} · Los cambios se comparten entre organizaciones.</p></div></div>
    <form class="panel organization-form catalog-form" method="POST" action="{{ $record ? route('taxonomy.update', ['catalog' => $catalog, 'record' => $record->id]) : route('taxonomy.store', ['catalog' => $catalog]) }}">
        @csrf
        @if ($record) @method('PUT') @endif
        <x-field name="code" label="Código estable" :value="$record?->code ?? ''" maxlength="80" pattern="[A-Z][A-Z0-9_]{1,79}" required :readonly="$record !== null" />
        <x-field :name="$nameColumn" :label="$catalog === 'common-names' ? 'Nombre común' : 'Nombre científico'" :value="$record?->getAttribute($nameColumn) ?? ''" maxlength="255" required />
        @if ($catalog === 'species')
            <div class="field"><label for="family_id">Familia</label><select class="catalog-select" id="family_id" name="family_id" required><option value="">Selecciona una familia</option>@foreach ($families as $family)<option value="{{ $family->id }}" @selected(old('family_id', $record?->family_id) === $family->id)>{{ $family->scientific_name }}{{ $family->active ? '' : ' (inactiva; referencia histórica)' }}</option>@endforeach</select>@error('family_id')<span class="field-error">{{ $message }}</span>@enderror</div>
        @elseif ($catalog === 'common-names')
            <div class="field"><label for="species_id">Especie</label><select class="catalog-select" id="species_id" name="species_id" required><option value="">Selecciona una especie</option>@foreach ($species as $speciesOption)<option value="{{ $speciesOption->id }}" @selected(old('species_id', $record?->species_id) === $speciesOption->id)>{{ $speciesOption->scientific_name }}{{ $speciesOption->active && $speciesOption->family->active ? '' : ' (referencia histórica)' }}</option>@endforeach</select>@error('species_id')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="field"><label for="is_primary">Nombre principal de la especie</label><select id="is_primary" name="is_primary"><option value="0" @selected(!old('is_primary', $record?->is_primary ?? false))>No</option><option value="1" @selected(old('is_primary', $record?->is_primary ?? false))>Sí</option></select>@error('is_primary')<span class="field-error">{{ $message }}</span>@enderror</div>
        @endif
        <div class="field wide"><label for="description">Descripción y procedencia</label><textarea id="description" name="description" rows="3" maxlength="5000">{{ old('description', $record?->description) }}</textarea></div>
        <x-field name="sort_order" label="Orden de presentación" type="number" :value="$record?->sort_order ?? 0" min="0" max="1000000" step="1" required />
        <div class="field"><label for="active">Estado</label><select id="active" name="active"><option value="1" @selected(old('active', $record?->active ?? true))>Activo</option><option value="0" @selected(!old('active', $record?->active ?? true))>Inactivo</option></select></div>
        <p class="catalog-note wide">El código no cambia. Los valores inactivos se conservan para las referencias históricas. Cada especie puede tener varios nombres comunes y, como máximo, un principal.</p>
        <div class="form-actions"><a class="button secondary" href="{{ route('taxonomy.index', ['catalog' => $catalog]) }}">Cancelar</a><button class="button">Guardar valor</button></div>
    </form>
@endsection

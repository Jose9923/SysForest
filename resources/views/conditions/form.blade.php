@extends('layouts.institutional')
@section('title', ($record ? 'Editar · ' : 'Crear · ').$title)
@push('styles')<link rel="stylesheet" href="{{ asset('css/domain-catalogs.css') }}">@endpush
@include('partials.catalog-select')
@section('content')
    <a class="back-link" href="{{ route('conditions.index', ['catalog' => $catalog]) }}">← {{ $title }}</a>
    <div class="page-heading"><div><p class="eyebrow">CONDICIONES FORESTALES NACIONALES</p><h1>{{ $record ? 'Editar valor' : 'Crear valor' }}</h1><p class="muted">{{ $title }} · La categoría conserva el órgano y el tipo de evaluación.</p></div></div>
    <form class="panel organization-form catalog-form" method="POST" action="{{ $record ? route('conditions.update', ['catalog' => $catalog, 'record' => $record->id]) : route('conditions.store', ['catalog' => $catalog]) }}">
        @csrf
        @if ($record) @method('PUT') <input type="hidden" name="lock_version" value="{{ old('lock_version', $record->lock_version) }}"> @endif
        <x-field name="code" label="Código estable" :value="$record?->code ?? ''" maxlength="80" pattern="[A-Z][A-Z0-9_]{1,79}" required :readonly="$record !== null" />
        <x-field name="name" label="Nombre en español" :value="$record?->name ?? ''" maxlength="255" required />
        <div class="field"><label for="assessment_type">Tipo de evaluación</label><select id="assessment_type" name="assessment_type" required @disabled($record !== null)><option value="physical_mechanical" @selected(old('assessment_type', $record?->assessment_type) === 'physical_mechanical')>Físico-mecánica</option><option value="phytosanitary" @selected(old('assessment_type', $record?->assessment_type) === 'phytosanitary')>Fitosanitaria</option></select>@if ($record)<input type="hidden" name="assessment_type" value="{{ $record->assessment_type }}">@endif @error('assessment_type')<span class="field-error">{{ $message }}</span>@enderror</div>
        @if ($catalog === 'items')
            <div class="field"><label for="condition_category_id">Categoría y órgano</label><select class="catalog-select" id="condition_category_id" name="condition_category_id" required @disabled($record !== null)><option value="">Selecciona una categoría</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected(old('condition_category_id', $record?->condition_category_id) === $category->id)>{{ $category->assessment_type === 'physical_mechanical' ? 'Físico-mecánica' : 'Fitosanitaria' }} · {{ $category->name }}{{ $category->active ? '' : ' (inactiva; referencia histórica)' }}</option>@endforeach</select>@if ($record)<input type="hidden" name="condition_category_id" value="{{ $record->condition_category_id }}">@endif @error('condition_category_id')<span class="field-error">{{ $message }}</span>@enderror</div>
        @endif
        <div class="field wide"><label for="description">Descripción y procedencia</label><textarea id="description" name="description" rows="3" maxlength="5000">{{ \App\Support\ExcelReferencePresentation::text(old('description', $record?->description)) }}</textarea></div>
        <x-field name="sort_order" label="Orden de presentación" type="number" :value="$record?->sort_order ?? 0" min="0" max="1000000" step="1" required />
        <div class="field"><label for="active">Estado</label><select id="active" name="active"><option value="1" @selected(old('active', $record?->active ?? true))>Activo</option><option value="0" @selected(!old('active', $record?->active ?? true))>Inactivo</option></select></div>
        <p class="catalog-note wide">El código, tipo de evaluación y categoría se conservan. Desactivar un valor no elimina su identidad ni sus referencias históricas.</p>
        <div class="form-actions"><a class="button secondary" href="{{ route('conditions.index', ['catalog' => $catalog]) }}">Cancelar</a><button class="button">Guardar valor</button></div>
    </form>
@endsection

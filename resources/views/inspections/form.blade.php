@extends('layouts.institutional')
@section('title', $inspection ? 'Corregir inspección' : 'Nueva inspección')
@include('inspections.assets')
@section('content')
<p class="notice">Al guardar, SYSFOREST estima automáticamente el carbono aéreo con CAP, altura y número de fustes, si la entidad validó una referencia para la especie. Los datos faltantes quedan pendientes. Consulta el resultado en la ficha de la inspección.</p>
    <a class="back-link" href="{{ $inspection ? route('inspections.show', $inspection->id) : route('trees.show', $tree->id) }}">← {{ $inspection ? 'Volver a la inspección' : 'Volver al árbol' }}</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · SEGUIMIENTO FORESTAL</p><h1>{{ $inspection ? 'Corregir inspección' : 'Nueva inspección' }}</h1><p class="muted">Registra lo medido y observado en la fecha de visita.</p></div></div>
    <div class="inspection-context"><div><strong>{{ $tree->code }}</strong><p>{{ $tree->species?->commonNames->pluck('name')->join(', ') ?: ($tree->species ? 'Sin nombre común registrado' : 'Especie sin identificar') }} @if ($tree->species) · <i>{{ $tree->species->scientific_name }}</i>@endif</p></div><a class="text-link" href="{{ route('trees.show', $tree->id) }}">Ver identidad y ubicación →</a></div>
    <form class="forestry-form inspection-form" method="POST" action="{{ $inspection ? route('inspections.update', $inspection->id) : route('trees.inspections.store', $tree->id) }}" data-dendrometry-form data-preview-url="{{ route('dendrometry.preview') }}" data-inspection-id="{{ $inspection?->id }}">
        @csrf @if ($inspection) @method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version', $inspection->lock_version) }}">@endif
        @include('inspections.fields')
        <div class="forestry-submit"><div class="form-actions"><a class="button secondary" href="{{ $inspection ? route('inspections.show', $inspection->id) : route('trees.show', $tree->id) }}">Cancelar</a><button class="button">{{ $inspection ? 'Guardar corrección' : 'Guardar inspección' }}</button></div></div>
    </form>
@endsection

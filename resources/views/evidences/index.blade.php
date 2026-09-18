@extends('layouts.institutional')
@section('title', 'Evidencias fotográficas')
@include('evidences.assets', ['withForestryAssets' => true])
@section('content')
    <a class="back-link" href="{{ $gallery['ownerUrl'] }}">← Volver a {{ $gallery['ownerLabel'] }}</a>
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · REGISTRO FOTOGRÁFICO</p><h1>Evidencias fotográficas</h1><p class="muted">{{ $gallery['ownerLabel'] }}</p></div></div>
    <section class="panel forestry-panel filter-panel"><form method="GET" action="{{ $gallery['indexUrl'] }}">
        <div class="form-grid"><div class="field"><label for="evidence-type-filter">Tipo de evidencia</label><select id="evidence-type-filter" class="catalog-select" name="evidence_type_id"><option value="">Todos los tipos</option>@foreach ($typeOptions as $type)<option value="{{ $type->id }}" @selected(($filters['evidence_type_id'] ?? '') === $type->id)>{{ $type->name }}{{ !$type->active ? ' · inactivo' : '' }}</option>@endforeach</select></div>
            @if ($trashedAllowed)<div class="field"><label for="evidence-status-filter">Estado del registro</label><select id="evidence-status-filter" name="trashed"><option value="">Vigentes</option><option value="only" @selected(($filters['trashed'] ?? '') === 'only')>Anuladas</option></select></div>@endif
        </div>
        <div class="form-actions"><button class="button">Aplicar filtros</button><a class="button secondary" href="{{ $gallery['indexUrl'] }}">Limpiar</a></div>
    </form></section>
    @include('evidences.gallery', ['showAllLink' => false])
    @if ($gallery['pagination'] ?? null)<div class="evidence-pagination">{{ $gallery['pagination']->links() }}</div>@endif
@endsection

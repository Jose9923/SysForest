@extends('reports.pdf.layout')
@section('report')
    @forelse ($fields as $field)
        @if (($field['group'] ?? null) !== null && ($loop->first || ($fields[$loop->index - 1]['group'] ?? null) !== $field['group']))<h2>{{ $field['group'] }}</h2>@endif
        <dl class="pdf-field"><dt>{{ $field['label'] }}@if ($field['unit'] ?? null) ({{ $field['unit'] }})@endif</dt><dd>@include('reports.value', ['value' => $field['value'] ?? null])</dd></dl>
    @empty<p class="pdf-empty">No hay información disponible para esta ficha.</p>@endforelse
@endsection

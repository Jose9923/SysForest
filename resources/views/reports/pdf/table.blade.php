@extends('reports.pdf.layout')
@section('report')
    <table class="pdf-summary"><tr>@foreach (['totalTrees' => 'Árboles seleccionados', 'totalRows' => 'Filas del reporte', 'georeferenced' => 'Con ubicación', 'withoutGeometry' => 'Sin ubicación'] as $key => $label)<td>{{ $label }}<strong>{{ number_format($summary[$key], 0, ',', '.') }}</strong></td>@endforeach</tr></table>
    @if (count($rows))<table class="pdf-table"><thead><tr>@foreach ($columns as $column)<th scope="col">{{ $column['label'] }}@if ($column['unit'] ?? null)<small>{{ $column['unit'] }}</small>@endif</th>@endforeach</tr></thead><tbody>@foreach ($rows as $row)<tr>@foreach ($columns as $column)<td class="{{ in_array($column['type'] ?? '', ['number', 'integer', 'decimal'], true) ? 'pdf-number' : '' }}">@include('reports.value', ['value' => $row[$column['key']] ?? null])</td>@endforeach</tr>@endforeach</tbody></table>@else<p class="pdf-empty">No hay resultados para los filtros seleccionados.</p>@endif
@endsection

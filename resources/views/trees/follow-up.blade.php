@extends('layouts.institutional')
@section('title', 'Seguimiento de árboles')
@include('forestry.assets')
@include('reports.assets')
@section('content')
    <div class="page-heading forestry-heading">
        <div><p class="eyebrow">{{ $organization->name }} · SEGUIMIENTO FORESTAL</p><h1>Seguimiento de árboles</h1><p class="muted">Abre el historial de cada árbol o registra una nueva revisión.</p></div>
        <a class="button secondary" href="{{ route('trees.index', $filters) }}">Bandeja de árboles</a>
    </div>
    @if (($currentAccessMode ?? '') === 'department_read')<p class="forestry-readonly">Consulta departamental. Los registros se muestran en modo de lectura.</p>@endif
    @include('trees.filters', ['filterRoute' => 'trees.follow-up'])
    <section class="panel forestry-table-panel" aria-label="Seguimiento de árboles">
        <div class="forestry-table-summary"><strong>{{ $trees->total() }} {{ $trees->total() === 1 ? 'árbol encontrado' : 'árboles encontrados' }}</strong><span class="muted">{{ $canViewInspections ? 'Las visitas corresponden al historial completo de cada árbol, entre sus campañas.' : 'La consulta de visitas requiere permiso de inspecciones.' }}</span></div>
        @if ($trees->isNotEmpty())
            <div class="forestry-table-wrap"><table class="forestry-table"><thead><tr><th scope="col">Árbol</th><th scope="col">Ubicación</th><th scope="col">Estado</th>@if ($canViewInspections)<th scope="col">Visitas</th><th scope="col">Última visita</th>@endif<th scope="col">Seguimiento</th></tr></thead><tbody>
                @foreach ($trees as $entry)
                    @php($statusValue = $entry->current_status instanceof \BackedEnum ? $entry->current_status->value : $entry->current_status)
                    <tr>
                        <td data-label="Árbol"><a class="tree-code" href="{{ route('trees.show', $entry->id) }}">{{ $entry->code }}</a><small><i>{{ $entry->species?->scientific_name ?? 'Sin identificar' }}</i></small><small>{{ $entry->species?->commonNames->pluck('name')->join(', ') }}</small></td>
                        <td data-label="Ubicación"><span>{{ $entry->placement?->name ?? 'Emplazamiento no informado' }}<small>{{ $entry->location_description ?: 'Ubicación no informada' }}</small></span></td>
                        <td data-label="Estado"><div class="forestry-flags"><span class="pill">{{ $statuses[$statusValue] ?? $statusValue }}</span>@if ($entry->trashed())<span class="pill danger">Anulado</span>@endif</div></td>
                        @if ($canViewInspections)
                            <td data-label="Visitas">{{ $entry->inspection_count }}</td>
                            <td data-label="Última visita">@if ($entry->latest_inspection_id)<a href="{{ route('inspections.show', $entry->latest_inspection_id) }}">{{ $entry->latest_inspection_date?->format('d/m/Y') }}</a>@else<span class="muted">Sin visitas registradas</span>@endif</td>
                        @endif
                        <td data-label="Seguimiento"><div class="row-actions">
                            <a href="{{ route('trees.show', $entry->id) }}#tree-history" aria-label="Historial del árbol {{ $entry->code }}">Ver historial</a>
                            @if ($canViewInspections)<a href="{{ route('inspections.index', ['tree_id' => $entry->id]) }}">Todas las visitas</a>@endif
                            @if ($canCreateInspection && !$entry->trashed())<a href="{{ route('trees.inspections.create', $entry->id) }}" aria-label="Nueva revisión del árbol {{ $entry->code }}">Nueva revisión</a>@endif
                            <a href="{{ route('trees.show', $entry->id) }}">Ver ficha</a>
                        </div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @else
            <div class="forestry-empty"><h2>No hay árboles con estos filtros</h2><p>Ajusta la selección para consultar su seguimiento.</p><a class="button secondary" href="{{ route('trees.follow-up') }}">Limpiar filtros</a></div>
        @endif
    </section>
    @include('partials.pagination', ['paginator' => $trees])
@endsection

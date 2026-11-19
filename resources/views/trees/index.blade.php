@extends('layouts.institutional')
@section('title', 'Bandeja de árboles')
@include('forestry.assets')
@include('reports.assets')
@section('content')
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · INVENTARIO FORESTAL</p><h1>Bandeja de árboles</h1><p class="muted">Consulta la identidad permanente y la ubicación de cada individuo.</p></div>
        <div class="report-entry-actions"><a class="button secondary" href="{{ route('trees.follow-up', $filters) }}">Seguimiento de árboles</a>@can('reports.view')@if (empty($filters['trashed']))<a class="button secondary" href="{{ route('reports.index', array_merge(array_diff_key($filters, array_flip(['page', 'render_mode', 'term', 'format', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id'])), ['scope' => 'organization', 'report' => 'inventory'])) }}">Reportes y descargas</a>@endif @endcan @if ($canCreate)<a class="button" href="{{ route('trees.create') }}">+ Registrar árbol</a>@endif</div>
    </div>
    @if (($currentAccessMode ?? '') === 'department_read')<p class="forestry-readonly">Consulta departamental. Los registros se muestran en modo de lectura.</p>@endif
    @include('trees.filters', ['filterRoute' => 'trees.index'])
    <section class="panel forestry-table-panel" aria-label="Resultados de árboles">
        <div class="forestry-table-summary"><strong>{{ $trees->total() }} {{ $trees->total() === 1 ? 'árbol encontrado' : 'árboles encontrados' }}</strong><span class="muted">{{ ($filters['trashed'] ?? '') === 'only' ? 'Registros anulados' : 'Fichas permanentes de la organización' }}</span></div>
        @if ($trees->isNotEmpty())
            <div class="forestry-table-wrap"><table class="forestry-table"><thead><tr><th scope="col">Código</th><th scope="col">Nombre común</th><th scope="col">Nombre científico</th><th scope="col">Emplazamiento</th><th scope="col">Ubicación</th><th scope="col">Estado</th>@if ($canViewRisk)<th scope="col">Prioridad fitosanitaria</th>@endif<th scope="col">Acciones</th></tr></thead><tbody>
                @foreach ($trees as $entry)
                    @php($statusValue = $entry->current_status instanceof \BackedEnum ? $entry->current_status->value : $entry->current_status)
                    <tr>
                        <td data-label="Código"><a class="tree-code" href="{{ route('trees.show', $entry->id) }}">{{ $entry->code }}</a>@if ($entry->external_number)<small>Anterior: {{ $entry->external_number }}</small>@endif</td>
                        <td data-label="Nombre común"><span>{{ $entry->species?->commonNames->pluck('name')->join(', ') ?: 'Sin identificar' }}</span></td>
                        <td data-label="Especie"><span><i>{{ $entry->species?->scientific_name ?? 'Sin identificar' }}</i></span>@if ($entry->species)<small>{{ $entry->species->family?->scientific_name }}</small>@endif</td>
                        <td data-label="Emplazamiento"><span>{{ $entry->placement?->name ?? 'No informado' }}</span></td>
                        <td data-label="Ubicación"><span>{{ $entry->location_description ?: 'No informada' }}</span></td>
                        <td data-label="Estado"><div class="forestry-flags"><span class="pill">{{ $statuses[$statusValue] ?? $statusValue }}</span>@if ($entry->trashed())<span class="pill danger">Anulado</span>@endif @if ($entry->is_protected)<span class="pill neutral">Protegido</span>@endif @if ($entry->is_heritage)<span class="pill warning">Patrimonial</span>@endif</div></td>
                        @if ($canViewRisk)<td data-label="Prioridad fitosanitaria">{{ $entry->risk_priority !== null ? \App\Enums\Risk\RiskPriority::from((int) $entry->risk_priority)->label() : ($entry->risk_evaluation_id === null ? 'Sin evaluar' : ((json_decode($entry->risk_result, true)['status'] ?? '') === 'inconsistent' ? 'Inconsistente' : 'Pendiente de confirmación')) }}</td>@endif
                        <td data-label="Acciones"><div class="row-actions"><a href="{{ route('trees.show', $entry->id) }}" aria-label="Ver árbol {{ $entry->code }}">Ver ficha</a><a href="{{ route('trees.show', $entry->id) }}#tree-history" aria-label="Historial del árbol {{ $entry->code }}">Historial</a>@if (!$entry->trashed()) @can('update', $entry)<a href="{{ route('trees.edit', $entry->id) }}" aria-label="Editar árbol {{ $entry->code }}">Editar</a>@endcan @endif</div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @else
            <div class="forestry-empty"><span class="empty-symbol" aria-hidden="true">♧</span><h2>No hay árboles para mostrar</h2><p>Revisa los filtros o registra el primer árbol de una campaña activa.</p>@if ($canCreate)<a class="button" href="{{ route('trees.create') }}">Registrar árbol</a>@endif</div>
        @endif
    </section>
    @include('partials.pagination', ['paginator' => $trees])
@endsection

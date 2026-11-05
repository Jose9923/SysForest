@extends('layouts.institutional')
@section('title', 'Bandeja de árboles')
@include('forestry.assets')
@include('reports.assets')
@section('content')
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · INVENTARIO FORESTAL</p><h1>Bandeja de árboles</h1><p class="muted">Consulta la identidad permanente y la ubicación de cada individuo.</p></div>
        <div class="report-entry-actions">@can('reports.view')@if (empty($filters['trashed']))<a class="button secondary" href="{{ route('reports.index', array_merge(array_diff_key($filters, array_flip(['page', 'render_mode', 'term', 'format', 'trashed', 'include_deleted', 'include_voided_trees', 'tree_id'])), ['scope' => 'organization', 'report' => 'inventory'])) }}">Reportes y descargas</a>@endif @endcan @if ($canCreate)<a class="button" href="{{ route('trees.create') }}">+ Registrar árbol</a>@endif</div>
    </div>
    @if (($currentAccessMode ?? '') === 'department_read')<p class="forestry-readonly">Consulta departamental. Los registros se muestran en modo de lectura.</p>@endif
    <form method="GET" action="{{ route('trees.index') }}" class="panel forestry-panel filter-panel">
        <div class="forestry-filters">
            @if ($canViewRisk)@include('risk.filter')@endif
            <div class="field search-field"><label for="tree-q">Buscar árbol</label><input id="tree-q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="150" placeholder="Código, número anterior, especie o ubicación"></div>
            <div class="field"><label for="tree-inventory">Inventario</label><select class="catalog-select" id="tree-inventory" name="inventory_id"><option value="">Todos los inventarios</option>@foreach ($options['inventories'] as $option)<option value="{{ $option->id }}" @selected(($filters['inventory_id'] ?? '') === $option->id)>{{ $option->code }} · {{ $option->name }}</option>@endforeach</select></div>
            @include('forestry.taxonomy-filters', ['filterPrefix' => 'tree'])
            <div class="field"><label for="tree-placement">Emplazamiento</label><select class="catalog-select" id="tree-placement" name="placement_id"><option value="">Todos los emplazamientos</option>@foreach ($options['placements'] as $option)<option value="{{ $option->id }}" @selected(($filters['placement_id'] ?? '') === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
            <div class="field"><label for="tree-status">Estado del árbol</label><select id="tree-status" name="current_status"><option value="">Todos los estados</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['current_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        @if ($canViewRisk)
            <details class="condition-filter-details" @if (!empty($filters['physical_condition_ids']) || !empty($filters['phytosanitary_condition_ids'])) open @endif>
                <summary>Condiciones de la última inspección</summary>
                <div class="form-grid">
                    @foreach (['physical_mechanical' => ['physical_condition_ids', 'Estado físico-mecánico'], 'phytosanitary' => ['phytosanitary_condition_ids', 'Estado fitosanitario']] as $type => [$field, $label])
                        @include('inspections.condition-fields', ['categories' => $conditionFilterCategories[$type] ?? collect(), 'assessmentLabel' => $label, 'fieldName' => $field, 'selectedIds' => $filters[$field] ?? [], 'filterMode' => true])
                    @endforeach
                    <div class="field"><label for="tree-condition-mode">Coincidencia de condiciones</label><select id="tree-condition-mode" name="condition_mode"><option value="any" @selected(($filters['condition_mode'] ?? 'any') === 'any')>Al menos una</option><option value="all" @selected(($filters['condition_mode'] ?? '') === 'all')>Todas en la misma inspección</option></select></div>
                </div>
            </details>
        @endif
        <div class="filter-footer">
            <div class="filter-sort">
                <div class="field"><label for="tree-sort">Ordenar por</label><select id="tree-sort" name="sort">@foreach (['code' => 'Código', 'created_at' => 'Fecha de registro', 'scientific_name' => 'Nombre científico'] as $value => $label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'code') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="field"><label for="tree-direction">Dirección</label><select id="tree-direction" name="direction"><option value="asc" @selected(($filters['direction'] ?? 'asc') === 'asc')>Ascendente</option><option value="desc" @selected(($filters['direction'] ?? '') === 'desc')>Descendente</option></select></div>
                @if ($canRestore)<div class="field"><label for="tree-trashed">Registros</label><select id="tree-trashed" name="trashed"><option value="">Vigentes</option><option value="only" @selected(($filters['trashed'] ?? '') === 'only')>Anulados</option></select></div>@endif
            </div>
            <div class="forestry-actions"><a class="button secondary" href="{{ route('trees.index') }}">Limpiar</a><button class="button">Aplicar filtros</button></div>
        </div>
    </form>
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
                        @if ($canViewRisk)<td data-label="Prioridad fitosanitaria">{{ $entry->risk_priority !== null ? \App\Enums\RiskPriority::from((int) $entry->risk_priority)->label() : ($entry->risk_evaluation_id === null ? 'Sin evaluar' : ((json_decode($entry->risk_result, true)['status'] ?? '') === 'inconsistent' ? 'Inconsistente' : 'Pendiente de confirmación')) }}</td>@endif
                        <td data-label="Acciones"><div class="row-actions"><a href="{{ route('trees.show', $entry->id) }}" aria-label="Ver árbol {{ $entry->code }}">Ver ficha</a>@if (!$entry->trashed()) @can('update', $entry)<a href="{{ route('trees.edit', $entry->id) }}" aria-label="Editar árbol {{ $entry->code }}">Editar</a>@endcan @endif</div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @else
            <div class="forestry-empty"><span class="empty-symbol" aria-hidden="true">♧</span><h2>No hay árboles para mostrar</h2><p>Revisa los filtros o registra el primer árbol de una campaña activa.</p>@if ($canCreate)<a class="button" href="{{ route('trees.create') }}">Registrar árbol</a>@endif</div>
        @endif
    </section>
    @include('partials.pagination', ['paginator' => $trees])
@endsection

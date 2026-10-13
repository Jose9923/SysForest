@extends('layouts.institutional')
@section('title', 'Inspecciones forestales')
@include('inspections.assets')
@section('content')
    @php
        $physicalFilters = is_array($filters['physical_condition_ids'] ?? null) ? $filters['physical_condition_ids'] : [];
        $phytosanitaryFilters = is_array($filters['phytosanitary_condition_ids'] ?? null) ? $filters['phytosanitary_condition_ids'] : [];
    @endphp
    <div class="page-heading forestry-heading"><div><p class="eyebrow">{{ $organization->name }} · HISTÓRICO FORESTAL</p><h1>Inspecciones</h1><p class="muted">Consulta las mediciones y observaciones registradas en cada visita.</p></div>@if ($canCreateInspection)<a class="button" href="{{ !empty($filters['tree_id']) ? route('trees.inspections.create', $filters['tree_id']) : route('trees.index') }}">{{ !empty($filters['tree_id']) ? '+ Nueva inspección' : 'Seleccionar árbol para inspeccionar' }}</a>@endif</div>
    @if (!empty($filters['tree_id']))<p class="inspection-context"><span>Historial del árbol seleccionado.</span><a class="text-link" href="{{ route('trees.show', $filters['tree_id']) }}">Volver a su ficha →</a></p>@endif
    <form method="GET" action="{{ route('inspections.index') }}" class="panel forestry-panel filter-panel">
        @if (!empty($filters['tree_id']))<input type="hidden" name="tree_id" value="{{ $filters['tree_id'] }}">@endif
        <div class="forestry-filters">
            <div class="field"><label for="filter-inventory">Inventario</label><select class="catalog-select" id="filter-inventory" name="inventory_id"><option value="">Todas las campañas</option>@foreach ($options['inventories'] as $option)<option value="{{ $option->id }}" @selected(($filters['inventory_id'] ?? '') === $option->id)>{{ $option->code }} · {{ $option->name }}</option>@endforeach</select></div>
            <div class="field"><label for="date-from">Fecha desde</label><input id="date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="field"><label for="date-to">Fecha hasta</label><input id="date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            @include('forestry.taxonomy-filters', ['filterPrefix' => 'filter'])
            <div class="field"><label for="filter-maturity">Madurez registrada</label><select class="catalog-select" id="filter-maturity" name="maturity_state_id"><option value="">Todas las madureces</option>@foreach ($options['maturityStates'] as $option)<option value="{{ $option->id }}" @selected(($filters['maturity_state_id'] ?? '') === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
            <div class="field"><label for="diameter-class">Clase diamétrica</label><input id="diameter-class" name="diameter_class_number" type="number" min="1" step="1" value="{{ $filters['diameter_class_number'] ?? '' }}" placeholder="Todas las clases"></div>
        </div>
        <details class="inspection-filter-details" @if ($physicalFilters || $phytosanitaryFilters || !empty($filters['height_class_number']) || isset($filters['cap_cm_min']) || isset($filters['cap_cm_max']) || isset($filters['dap_m_min']) || isset($filters['dap_m_max']) || isset($filters['total_height_m_min']) || isset($filters['total_height_m_max'])) open @endif><summary>Condiciones, clase de altura y rangos de medidas</summary>
            <div class="form-grid">
                @foreach (['physical_mechanical' => ['physical_condition_ids', 'Condiciones físico-mecánicas', $physicalFilters], 'phytosanitary' => ['phytosanitary_condition_ids', 'Condiciones fitosanitarias', $phytosanitaryFilters]] as $type => [$field, $label, $selected])
                    <div class="field"><label for="filter-{{ $type }}">{{ $label }}</label><select class="catalog-select" id="filter-{{ $type }}" name="{{ $field }}[]" multiple>@foreach (($options['conditionCategories'][$type] ?? collect()) as $category)<optgroup label="{{ $category->name }}">@foreach ($category->conditions as $condition)<option value="{{ $condition->id }}" @selected(in_array($condition->id, $selected, true))>{{ $condition->name }} · {{ $category->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                @endforeach
                <div class="field"><label for="condition-mode">Coincidencia de condiciones</label><select id="condition-mode" name="condition_mode"><option value="any" @selected(($filters['condition_mode'] ?? 'any') === 'any')>Cualquiera de las seleccionadas</option><option value="all" @selected(($filters['condition_mode'] ?? '') === 'all')>Todas en la misma inspección</option></select></div>
                <div class="field"><label for="height-class">Clase de altura</label><input id="height-class" name="height_class_number" type="number" min="1" step="1" value="{{ $filters['height_class_number'] ?? '' }}" placeholder="Todas las clases"></div>
                @foreach (['cap_cm' => 'CAP (cm)', 'dap_m' => 'DAP (m)', 'total_height_m' => 'Altura total (m)'] as $field => $label)
                    <div class="field"><label for="{{ $field }}-min">{{ $label }} · mínimo</label><input id="{{ $field }}-min" name="{{ $field }}_min" type="number" min="0" step="any" value="{{ $filters[$field.'_min'] ?? '' }}"></div>
                    <div class="field"><label for="{{ $field }}-max">{{ $label }} · máximo</label><input id="{{ $field }}-max" name="{{ $field }}_max" type="number" min="0" step="any" value="{{ $filters[$field.'_max'] ?? '' }}"></div>
                @endforeach
            </div>
        </details>
        <div class="filter-footer"><p class="field-hint">Los filtros se aplican al histórico; cada fila representa una inspección.</p><div class="forestry-actions"><a class="button secondary" href="{{ route('inspections.index', !empty($filters['tree_id']) ? ['tree_id' => $filters['tree_id']] : []) }}">Limpiar filtros</a><button class="button">Aplicar filtros</button></div></div>
    </form>
    <section class="panel forestry-table-panel" aria-label="Resultados de inspecciones"><div class="forestry-table-summary"><strong>{{ $inspections->total() }} {{ $inspections->total() === 1 ? 'inspección encontrada' : 'inspecciones encontradas' }}</strong><span class="muted">Mediciones históricas de la organización</span></div>
        @if ($inspections->isNotEmpty())<div class="forestry-table-wrap"><table class="forestry-table"><thead><tr><th scope="col">Fecha</th><th scope="col">Árbol y especie</th><th scope="col">Campaña</th><th scope="col">DAP / altura</th><th scope="col">Madurez</th><th scope="col">Responsable</th><th scope="col">Acciones</th></tr></thead><tbody>
            @foreach ($inspections as $entry)<tr><td data-label="Fecha"><a class="inspection-date-cell" href="{{ route('inspections.show', $entry->id) }}">{{ $entry->inspection_date->format('d/m/Y') }}</a></td><td data-label="Árbol"><span><a class="tree-code" href="{{ route('trees.show', $entry->tree_id) }}">{{ $entry->tree->code }}</a><small><i>{{ $entry->tree->species?->scientific_name ?? 'Sin identificar' }}</i></small><small>{{ $entry->tree->species?->commonNames->pluck('name')->join(', ') }}</small></span></td><td data-label="Campaña"><span>{{ $entry->inventoryTree?->inventory?->name ?? 'Sin campaña formal' }}</span></td><td data-label="Mediciones"><span>DAP: {{ $entry->dap_m ?? '—' }} m<small>Altura: {{ $entry->total_height_m ?? '—' }} m</small></span></td><td data-label="Madurez"><span>{{ $entry->maturityState?->name ?? 'Sin calcular' }}</span></td><td data-label="Responsable"><span>{{ $entry->creator?->name ?? 'No disponible' }}</span></td><td data-label="Acciones"><div class="row-actions"><a href="{{ route('inspections.show', $entry->id) }}">Ver inspección</a></div></td></tr>@endforeach
        </tbody></table></div>@else<div class="forestry-empty"><span class="empty-symbol" aria-hidden="true">▤</span><h2>No hay inspecciones para mostrar</h2><p>Ajusta los filtros o abre la ficha de un árbol para registrar una visita.</p><a class="button secondary" href="{{ route('trees.index') }}">Consultar árboles</a></div>@endif
    </section>
    @include('partials.pagination', ['paginator' => $inspections])
@endsection

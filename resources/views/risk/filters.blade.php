@php
    $visibleFields = ['scope', 'report', 'page', 'format', 'q', 'code', 'inventory_id', 'risk_priority', 'current_status',
        'date_from', 'date_to', 'family_id', 'species_id', 'common_name_id', 'common_name', 'scientific_name',
        'physical_condition_ids', 'phytosanitary_condition_ids', 'condition_mode', 'department_id', 'municipality_id', 'organization_id'];
    $advancedOpen = !empty($filters['family_id']) || !empty($filters['species_id']) || !empty($filters['common_name_id'])
        || !empty($filters['physical_condition_ids']) || !empty($filters['phytosanitary_condition_ids']);
@endphp
<details class="map-filter-panel risk-filter-panel" data-map-filter-panel open>
    <summary><span>Buscar y filtrar árboles <span class="map-filter-number" data-map-filter-count>{{ count($appliedFilters) }}</span></span><span class="map-filter-toggle" aria-hidden="true">⌄</span></summary>
    <form method="GET" action="{{ route('risk.index') }}" data-risk-filters>
        <input type="hidden" name="scope" value="organization">
        @foreach ($filters as $key => $value)
            @unless (in_array($key, $visibleFields, true))
                @if (is_array($value))@foreach ($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach
                @elseif ($value !== null && $value !== '')<input type="hidden" name="{{ $key }}" value="{{ is_bool($value) ? (int) $value : $value }}">@endif
            @endunless
        @endforeach
        @foreach (['department_id', 'municipality_id', 'organization_id'] as $field)
            @if (!empty($filters[$field]))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
        @endforeach
        <div class="risk-primary-filters">
            <div class="field"><label for="risk-q">Buscar árbol</label><input id="risk-q" name="q" data-filter-label="Búsqueda" maxlength="150" value="{{ $filters['q'] ?? '' }}" placeholder="Código, número anterior, especie o ubicación"></div>
            <div class="field"><label for="risk-code">Código del árbol</label><input id="risk-code" name="code" data-filter-label="Código del árbol" maxlength="150" value="{{ $filters['code'] ?? '' }}"></div>
            @include('maps.select', ['field' => 'inventory_id', 'label' => 'Inventario', 'kind' => 'inventories'])
            @include('risk.filter')
            <div class="field"><label for="risk-tree-status">Estado del árbol</label><select id="risk-tree-status" name="current_status" data-filter-label="Estado del árbol"><option value="">Todos</option>@foreach ($filterConfig['tree_statuses'] as $option)<option value="{{ $option['value'] }}" @selected(($filters['current_status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
            <div class="field"><label for="risk-date-from">Última inspección desde</label><input id="risk-date-from" name="date_from" type="date" data-filter-label="Inspección desde" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="field"><label for="risk-date-to">Última inspección hasta</label><input id="risk-date-to" name="date_to" type="date" data-filter-label="Inspección hasta" value="{{ $filters['date_to'] ?? '' }}"></div>
        </div>
        <details class="map-filter-group risk-advanced-filters" @if ($advancedOpen) open @endif><summary>Más filtros: nombres y condiciones</summary><div class="risk-advanced-fields">
            @include('maps.select', ['field' => 'family_id', 'label' => 'Familia', 'kind' => 'families'])
            @include('maps.select', ['field' => 'species_id', 'label' => 'Nombre científico', 'kind' => 'species'])
            @include('maps.select', ['field' => 'common_name_id', 'label' => 'Nombre común', 'kind' => 'common_names'])
            @foreach (['common_name' => 'Nombre común', 'scientific_name' => 'Nombre científico'] as $field => $label)
                <input type="hidden" name="{{ $field }}" data-filter-label="{{ $label }} · búsqueda anterior" value="{{ $filters[$field] ?? '' }}">
                <div class="map-hint" data-taxonomy-legacy="{{ $field }}" @if (trim((string) ($filters[$field] ?? '')) === '') hidden @endif><strong>{{ $label }} · búsqueda anterior:</strong> <span data-taxonomy-legacy-value>{{ $filters[$field] ?? '' }}</span>. <button class="button secondary" type="button" data-taxonomy-legacy-clear="{{ $field }}">Quitar búsqueda anterior</button></div>
            @endforeach
            <p class="map-hint risk-filter-help">Las condiciones corresponden a la última inspección del árbol. Elegir órganos muestra sus opciones; solo las condiciones seleccionadas filtran resultados.</p>
            @foreach (['physical_mechanical' => ['physical_condition_ids', 'Estado físico-mecánico'], 'phytosanitary' => ['phytosanitary_condition_ids', 'Estado fitosanitario']] as $type => [$field, $label])
                @include('inspections.condition-fields', ['categories' => $conditionFilterCategories[$type] ?? collect(), 'assessmentLabel' => $label, 'fieldName' => $field, 'selectedIds' => $filters[$field] ?? [], 'filterMode' => true])
            @endforeach
            <div class="field"><label for="risk-condition-mode">Coincidencia de condiciones</label><select id="risk-condition-mode" name="condition_mode" data-filter-label="Coincidencia de condiciones"><option value="any" @selected(($filters['condition_mode'] ?? 'any') === 'any')>Al menos una</option><option value="all" @selected(($filters['condition_mode'] ?? '') === 'all')>Todas en la misma inspección</option></select></div>
        </div></details>
        <div class="map-filter-actions"><button class="button" type="submit">Aplicar filtros</button><a class="button secondary" href="{{ route('risk.index') }}">Limpiar filtros</a></div>
    </form>
</details>

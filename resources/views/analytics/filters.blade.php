@php
    $visibleFields = ['risk_priority', 'department_id', 'municipality_id', 'organization_id', 'inventory_id', 'q', 'code',
        'common_name', 'scientific_name', 'common_name_id', 'family_id', 'species_id', 'placement_id', 'growth_habit_id',
        'establishment_origin_id', 'biogeographic_origin_id', 'current_status', 'is_protected', 'is_heritage',
        'date_from', 'date_to', 'maturity_state_id', 'diameter_class_number', 'height_class_number',
        'physical_condition_ids', 'phytosanitary_condition_ids', 'physical_condition_category_ids', 'phytosanitary_condition_category_ids', 'condition_mode', 'maintenance_type_id',
        'maintenance_status', 'maintenance_priority', 'has_pending_maintenance'];
@endphp
<details class="map-filter-panel" data-map-filter-panel open>
    <summary><span>Filtrar análisis <span class="map-filter-number" data-map-filter-count>{{ count($appliedFilters) }}</span></span><span class="map-filter-toggle" aria-hidden="true">⌄</span></summary>
    <form method="GET" action="{{ route('analytics.index') }}" data-analytics-filters>
        @foreach ($filters as $key => $value)
            @unless (in_array($key, $visibleFields, true))
                @if (is_array($value))@foreach ($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach
                @elseif ($value !== null && $value !== '')<input type="hidden" name="{{ $key }}" value="{{ is_bool($value) ? (int) $value : $value }}">@endif
            @endunless
        @endforeach
        <p class="map-hint">Selecciona el territorio y el inventario. Aplica los cambios para actualizar todas las gráficas.</p>
        @if ($filterConfig['can_inspect'])
            <details class="map-filter-group" data-report-condition-filters open>
                <summary>Estado físico-mecánico y fitosanitario</summary>
                <div class="map-fields">
                    <p class="map-hint">Elige órganos para graficar sus condiciones, o marca condiciones específicas para mostrar solo esas. Las condiciones marcadas filtran árboles por su última inspección; elegir únicamente órganos conserva el conjunto de árboles.</p>
                    @include('maps.condition-filter-fields', ['initialFilters' => $filters, 'publicMap' => false, 'seriesFilterMode' => true])
                </div>
            </details>
        @endif
        @include('maps.filter-fields', ['mapConfig' => $filterConfig, 'initialFilters' => $filters, 'publicMap' => false, 'separateConditionFilters' => true])
        <div class="map-filter-actions"><button class="button" type="submit">Aplicar filtros</button><a class="button secondary" href="{{ route('analytics.index', ['scope' => $filterConfig['scope']['mode']]) }}">Limpiar filtros</a></div>
    </form>
</details>

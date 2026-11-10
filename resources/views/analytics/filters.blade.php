@php
    $visibleFields = ['risk_priority', 'department_id', 'municipality_id', 'organization_id', 'inventory_id', 'q', 'code',
        'common_name', 'scientific_name', 'common_name_id', 'family_id', 'species_id', 'placement_id', 'growth_habit_id',
        'establishment_origin_id', 'biogeographic_origin_id', 'current_status', 'is_protected', 'is_heritage',
        'date_from', 'date_to', 'maturity_state_id', 'diameter_class_number', 'height_class_number',
        'physical_condition_ids', 'phytosanitary_condition_ids', 'physical_condition_category_ids', 'phytosanitary_condition_category_ids', 'condition_mode', 'maintenance_type_id',
        'maintenance_status', 'maintenance_priority', 'has_pending_maintenance'];
    $mapConfig = $filterConfig;
    $initialFilters = $filters;
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
        <p class="map-hint">Los filtros están organizados por tema. Aplica los cambios para actualizar el conjunto de árboles de todas las gráficas.</p>

        <details class="map-filter-group" data-analytics-filter-topic="territory" open>
            <summary>Territorio e inventario</summary>
            <div class="map-fields">
                @include('maps.select', ['field' => 'department_id', 'label' => 'Departamento', 'kind' => 'departments'])
                @include('maps.select', ['field' => 'municipality_id', 'label' => 'Municipio', 'kind' => 'municipalities'])
                @include('maps.select', ['field' => 'organization_id', 'label' => 'Organización', 'kind' => 'organizations'])
                @include('maps.select', ['field' => 'inventory_id', 'label' => 'Inventario', 'kind' => 'inventories'])
            </div>
        </details>

        <details class="map-filter-group" data-analytics-filter-topic="composition">
            <summary>Composición y estado</summary>
            <div class="map-fields">
                <div class="field"><label for="map-q">Buscar en el arbolado</label><input id="map-q" name="q" data-filter-label="Búsqueda" maxlength="150" value="{{ $initialFilters['q'] ?? '' }}" placeholder="Código, nombre o ubicación"></div>
                <div class="field"><label for="map-filter-code">Código del árbol</label><input id="map-filter-code" name="code" data-filter-label="Código del árbol" maxlength="150" value="{{ $initialFilters['code'] ?? '' }}"></div>
                @include('maps.select', ['field' => 'family_id', 'label' => 'Familia', 'kind' => 'families'])
                @include('maps.select', ['field' => 'species_id', 'label' => 'Nombre científico', 'kind' => 'species'])
                @include('maps.select', ['field' => 'common_name_id', 'label' => 'Nombre común', 'kind' => 'common_names'])
                <p class="map-hint">Una especie puede tener varios nombres comunes. Puedes encontrar sus árboles con cualquiera de ellos. El nombre científico distingue especies que comparten un nombre común.</p>
                @foreach (['common_name' => 'Nombre común', 'scientific_name' => 'Nombre científico'] as $field => $label)
                    <input type="hidden" name="{{ $field }}" data-filter-label="{{ $label }} · búsqueda anterior" value="{{ $initialFilters[$field] ?? '' }}">
                    <div class="map-hint" data-taxonomy-legacy="{{ $field }}" @if (trim((string) ($initialFilters[$field] ?? '')) === '') hidden @endif>
                        <strong>{{ $label }} · búsqueda anterior:</strong> <span data-taxonomy-legacy-value>{{ $initialFilters[$field] ?? '' }}</span>.
                        Se conserva la coincidencia por texto de este enlace. Selecciona una opción del catálogo para reemplazarla.
                        <button class="button secondary" type="button" data-taxonomy-legacy-clear="{{ $field }}">Quitar búsqueda anterior de {{ mb_strtolower($label) }}</button>
                    </div>
                @endforeach
                @include('maps.select', ['field' => 'placement_id', 'label' => 'Emplazamiento', 'kind' => 'placements'])
                @include('maps.select', ['field' => 'growth_habit_id', 'label' => 'Hábito de crecimiento', 'kind' => 'growth_habits'])
                @include('maps.select', ['field' => 'establishment_origin_id', 'label' => 'Origen de establecimiento', 'kind' => 'establishment_origins'])
                @include('maps.select', ['field' => 'biogeographic_origin_id', 'label' => 'Origen biogeográfico', 'kind' => 'biogeographic_origins'])
                <div class="field"><label for="map-status">Estado del árbol</label><select id="map-status" name="current_status" data-filter-label="Estado del árbol"><option value="">Todos</option>@foreach ($mapConfig['tree_statuses'] as $option)<option value="{{ $option['value'] }}" @selected(($initialFilters['current_status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
                @foreach (['is_protected' => 'Árbol protegido', 'is_heritage' => 'Árbol patrimonial'] as $field => $label)
                    <div class="field"><label for="map-filter-{{ $field }}">{{ $label }}</label><select id="map-filter-{{ $field }}" name="{{ $field }}" data-filter-label="{{ $label }}"><option value="">Todos</option><option value="1" @selected(in_array($initialFilters[$field] ?? null, [true, 1, '1'], true))>Sí</option><option value="0" @selected(in_array($initialFilters[$field] ?? null, [false, 0, '0'], true))>No</option></select></div>
                @endforeach
            </div>
        </details>

        @if ($filterConfig['can_inspect'])
            <div data-report-condition-filters>
                <details class="map-filter-group" data-analytics-filter-topic="phytosanitary" open>
                    <summary>Estado fitosanitario</summary>
                    <div class="map-fields">
                        <p class="map-hint">Elige órganos para mostrar sus condiciones fitosanitarias. Si marcas condiciones específicas, la serie muestra solo esas y se filtran los árboles según la coincidencia global.</p>
                        @include('inspections.condition-fields', ['categories' => $conditionFilterCategories['phytosanitary'] ?? collect(), 'assessmentLabel' => 'Estado fitosanitario', 'fieldName' => 'phytosanitary_condition_ids', 'selectedIds' => $initialFilters['phytosanitary_condition_ids'] ?? [], 'filterMode' => true, 'seriesFilterMode' => true, 'selectedCategories' => $initialFilters['phytosanitary_condition_category_ids'] ?? []])
                    </div>
                </details>
                <details class="map-filter-group" data-analytics-filter-topic="physical" open>
                    <summary>Estado físico-mecánico</summary>
                    <div class="map-fields">
                        <p class="map-hint">Elige órganos para mostrar sus condiciones físico-mecánicas. Si marcas condiciones específicas, la serie muestra solo esas y se filtran los árboles según la coincidencia global.</p>
                        @include('inspections.condition-fields', ['categories' => $conditionFilterCategories['physical_mechanical'] ?? collect(), 'assessmentLabel' => 'Estado físico-mecánico', 'fieldName' => 'physical_condition_ids', 'selectedIds' => $initialFilters['physical_condition_ids'] ?? [], 'filterMode' => true, 'seriesFilterMode' => true, 'selectedCategories' => $initialFilters['physical_condition_category_ids'] ?? []])
                    </div>
                </details>
                <details class="map-filter-group" data-analytics-filter-topic="conditions" open>
                    <summary>Coincidencia global de condiciones</summary>
                    <div class="map-fields">
                        <div class="field">
                            <label for="map-condition-mode">Coincidencia de ambas evaluaciones</label>
                            <select id="map-condition-mode" name="condition_mode" data-filter-label="Coincidencia de condiciones" aria-describedby="map-condition-mode-help">
                                <option value="any" @selected(($initialFilters['condition_mode'] ?? 'any') === 'any')>Al menos una condición seleccionada</option>
                                <option value="all" @selected(($initialFilters['condition_mode'] ?? '') === 'all')>Todas las condiciones seleccionadas</option>
                            </select>
                            <p class="field-hint" id="map-condition-mode-help">Se consideran juntas las condiciones fitosanitarias y físico-mecánicas: «Al menos una» admite cualquiera de ellas; «Todas» exige cada una en la misma última inspección. Este modo se comparte entre los dos bloques anteriores.</p>
                        </div>
                        <p class="map-hint">Elegir solo órganos cambia las series de condiciones, sin restringir el conjunto de árboles. Las condiciones seleccionadas sí filtran ese conjunto.</p>
                    </div>
                </details>
            </div>

            <details class="map-filter-group" data-analytics-filter-topic="size">
                <summary>Tamaño y madurez</summary>
                <div class="map-fields">
                    <p class="map-hint">Clases y madurez de la última inspección de cada árbol.</p>
                    @include('maps.select', ['field' => 'maturity_state_id', 'label' => 'Madurez', 'kind' => 'maturity_states'])
                    @foreach (['diameter_class_number' => 'Clase diamétrica', 'height_class_number' => 'Clase de altura'] as $field => $label)
                        <div class="field"><label for="map-filter-{{ $field }}">{{ $label }}</label><input id="map-filter-{{ $field }}" name="{{ $field }}" type="number" min="1" max="9223372036854775807" step="1" inputmode="numeric" data-filter-label="{{ $label }}" value="{{ $initialFilters[$field] ?? '' }}" placeholder="Cualquier clase"></div>
                    @endforeach
                </div>
            </details>

            <details class="map-filter-group" data-analytics-filter-topic="risk">
                <summary>Riesgo</summary>
                <div class="map-fields">
                    @include('risk.filter')
                </div>
            </details>

            <details class="map-filter-group" data-analytics-filter-topic="timeline">
                <summary>Fechas de inspección</summary>
                <div class="map-fields">
                    <p class="map-hint">El rango selecciona árboles por su última inspección; en las series históricas también delimita los registros del periodo.</p>
                    <div class="field"><label for="map-date-from">Inspección desde</label><input id="map-date-from" name="date_from" type="date" data-filter-label="Inspección desde" value="{{ $initialFilters['date_from'] ?? '' }}"></div>
                    <div class="field"><label for="map-date-to">Inspección hasta</label><input id="map-date-to" name="date_to" type="date" data-filter-label="Inspección hasta" value="{{ $initialFilters['date_to'] ?? '' }}"></div>
                </div>
            </details>
        @endif

        @if ($filterConfig['can_filter_maintenance'])
            <details class="map-filter-group" data-analytics-filter-topic="maintenances">
                <summary>Mantenimientos</summary>
                <div class="map-fields">
                    @include('maps.select', ['field' => 'maintenance_type_id', 'label' => 'Tipo de mantenimiento', 'kind' => 'maintenance_types'])
                    <div class="field"><label for="map-maintenance-status">Estado del mantenimiento</label><select id="map-maintenance-status" name="maintenance_status" data-filter-label="Estado del mantenimiento"><option value="">Todos</option>@foreach ($mapConfig['maintenance_statuses'] as $option)<option value="{{ $option['value'] }}" @selected(($initialFilters['maintenance_status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
                    <div class="field"><label for="map-maintenance-priority">Prioridad</label><select id="map-maintenance-priority" name="maintenance_priority" data-filter-label="Prioridad"><option value="">Todas</option>@foreach ($mapConfig['maintenance_priorities'] as $option)<option value="{{ $option['value'] }}" @selected(($initialFilters['maintenance_priority'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
                    <label class="map-check"><input type="checkbox" name="has_pending_maintenance" value="1" data-filter-label="Mantenimiento pendiente" @checked((string) ($initialFilters['has_pending_maintenance'] ?? '') === '1')> Solo con trabajo pendiente</label>
                </div>
            </details>
        @endif
        <div class="map-filter-actions"><button class="button" type="submit">Aplicar filtros</button><a class="button secondary" href="{{ route('analytics.index', ['scope' => $filterConfig['scope']['mode']]) }}">Limpiar filtros</a></div>
    </form>
</details>

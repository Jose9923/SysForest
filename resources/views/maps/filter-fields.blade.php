        <details class="map-filter-group" open><summary>Territorio e inventario</summary><div class="map-fields">
            @include('maps.select', ['field' => 'department_id', 'label' => 'Departamento', 'kind' => 'departments'])
            @include('maps.select', ['field' => 'municipality_id', 'label' => 'Municipio', 'kind' => 'municipalities'])
            @include('maps.select', ['field' => 'organization_id', 'label' => 'Organización', 'kind' => 'organizations'])
            @include('maps.select', ['field' => 'inventory_id', 'label' => 'Inventario', 'kind' => 'inventories'])
        </div></details>
        <details class="map-filter-group"><summary>Identidad y taxonomía</summary><div class="map-fields">
            <div class="field"><label for="map-q">Buscar en el arbolado</label><input id="map-q" name="q" data-filter-label="Búsqueda" maxlength="150" value="{{ $initialFilters['q'] ?? '' }}" placeholder="{{ ($publicMap ?? false) ? 'Código o nombre de especie' : 'Código, nombre o ubicación' }}"></div>
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
        </div></details>
        <details class="map-filter-group"><summary>Origen, ubicación y estado</summary><div class="map-fields">
            @include('maps.select', ['field' => 'placement_id', 'label' => 'Emplazamiento', 'kind' => 'placements'])
            @include('maps.select', ['field' => 'growth_habit_id', 'label' => 'Hábito de crecimiento', 'kind' => 'growth_habits'])
            @include('maps.select', ['field' => 'establishment_origin_id', 'label' => 'Origen de establecimiento', 'kind' => 'establishment_origins'])
            @include('maps.select', ['field' => 'biogeographic_origin_id', 'label' => 'Origen biogeográfico', 'kind' => 'biogeographic_origins'])
            <div class="field"><label for="map-status">Estado del árbol</label><select id="map-status" name="current_status" data-filter-label="Estado del árbol"><option value="">Todos</option>@foreach ($mapConfig['tree_statuses'] as $option)<option value="{{ $option['value'] }}" @selected(($initialFilters['current_status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
            @foreach (['is_protected' => 'Árbol protegido', 'is_heritage' => 'Árbol patrimonial'] as $field => $label)
                <div class="field"><label for="map-filter-{{ $field }}">{{ $label }}</label><select id="map-filter-{{ $field }}" name="{{ $field }}" data-filter-label="{{ $label }}"><option value="">Todos</option><option value="1" @selected(in_array($initialFilters[$field] ?? null, [true, 1, '1'], true))>Sí</option><option value="0" @selected(in_array($initialFilters[$field] ?? null, [false, 0, '0'], true))>No</option></select></div>
            @endforeach
        </div></details>
        @if ($mapConfig['can_inspect'])
            <details class="map-filter-group"><summary>{{ ($publicMap ?? false) ? 'Última inspección publicada' : 'Última inspección' }}</summary><div class="map-fields">
                @unless ($publicMap ?? false)@include('risk.filter')@endunless
                <p class="map-hint">{{ ($publicMap ?? false) ? 'Se consulta la inspección publicada más reciente de cada árbol.' : 'Se consulta la inspección más reciente de cada árbol.' }} Todas las condiciones se evalúan sobre esa misma inspección.</p>
                <div class="field"><label for="map-date-from">Inspección desde</label><input id="map-date-from" name="date_from" type="date" data-filter-label="Inspección desde" value="{{ $initialFilters['date_from'] ?? '' }}"></div>
                <div class="field"><label for="map-date-to">Inspección hasta</label><input id="map-date-to" name="date_to" type="date" data-filter-label="Inspección hasta" value="{{ $initialFilters['date_to'] ?? '' }}"></div>
                @include('maps.select', ['field' => 'maturity_state_id', 'label' => 'Madurez', 'kind' => 'maturity_states'])
                @foreach (['diameter_class_number' => 'Clase diamétrica', 'height_class_number' => 'Clase de altura'] as $field => $label)
                    <div class="field"><label for="map-filter-{{ $field }}">{{ $label }}</label><input id="map-filter-{{ $field }}" name="{{ $field }}" type="number" min="1" max="9223372036854775807" step="1" inputmode="numeric" data-filter-label="{{ $label }}" value="{{ $initialFilters[$field] ?? '' }}" placeholder="Cualquier clase"></div>
                @endforeach
                @include('maps.select', ['field' => 'physical_condition_ids', 'label' => 'Condición física', 'kind' => 'physical_conditions', 'multiple' => true])
                @include('maps.select', ['field' => 'phytosanitary_condition_ids', 'label' => 'Condición fitosanitaria', 'kind' => 'phytosanitary_conditions', 'multiple' => true])
                <div class="field"><label for="map-condition-mode">Coincidencia de condiciones</label><select id="map-condition-mode" name="condition_mode" data-filter-label="Coincidencia de condiciones"><option value="any" @selected(($initialFilters['condition_mode'] ?? 'any') === 'any')>Al menos una</option><option value="all" @selected(($initialFilters['condition_mode'] ?? '') === 'all')>Todas en la misma inspección</option></select></div>
            </div></details>
        @endif
        @if ($mapConfig['can_filter_maintenance'])
            <details class="map-filter-group"><summary>Mantenimientos</summary><div class="map-fields">
                @include('maps.select', ['field' => 'maintenance_type_id', 'label' => 'Tipo de mantenimiento', 'kind' => 'maintenance_types'])
                <div class="field"><label for="map-maintenance-status">Estado del mantenimiento</label><select id="map-maintenance-status" name="maintenance_status" data-filter-label="Estado del mantenimiento"><option value="">Todos</option>@foreach ($mapConfig['maintenance_statuses'] as $option)<option value="{{ $option['value'] }}" @selected(($initialFilters['maintenance_status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
                @unless ($publicMap ?? false)
                <div class="field"><label for="map-maintenance-priority">Prioridad</label><select id="map-maintenance-priority" name="maintenance_priority" data-filter-label="Prioridad"><option value="">Todas</option>@foreach ($mapConfig['maintenance_priorities'] as $option)<option value="{{ $option['value'] }}" @selected(($initialFilters['maintenance_priority'] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></div>
                <label class="map-check"><input type="checkbox" name="has_pending_maintenance" value="1" data-filter-label="Mantenimiento pendiente" @checked((string) ($initialFilters['has_pending_maintenance'] ?? '') === '1')> Solo con trabajo pendiente</label>
                @endunless
            </div></details>
        @endif

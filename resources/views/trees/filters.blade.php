    <form method="GET" action="{{ route($filterRoute ?? 'trees.index') }}" class="panel forestry-panel filter-panel">
        @if (!empty($filters['tree_id']))<input type="hidden" name="tree_id" value="{{ $filters['tree_id'] }}">@endif
        <div class="forestry-filters">
            @if ($canViewRisk)@include('risk.filter')@endif
            <div class="field search-field"><label for="tree-q">Buscar árbol</label><input id="tree-q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="150" placeholder="Código, número anterior, especie o ubicación"></div>
            <div class="field"><label for="tree-inventory">Inventario</label><select class="catalog-select" id="tree-inventory" name="inventory_id"><option value="">Todos los inventarios</option>@foreach ($options['inventories'] as $option)<option value="{{ $option->id }}" @selected(($filters['inventory_id'] ?? '') === $option->id)>{{ $option->code }} · {{ $option->name }}</option>@endforeach</select></div>
            @include('forestry.taxonomy-filters', ['filterPrefix' => 'tree'])
            <div class="field"><label for="tree-placement">Emplazamiento</label><select class="catalog-select" id="tree-placement" name="placement_id"><option value="">Todos los emplazamientos</option>@foreach ($options['placements'] as $option)<option value="{{ $option->id }}" @selected(($filters['placement_id'] ?? '') === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
            <div class="field"><label for="tree-status">Estado del árbol</label><select id="tree-status" name="current_status"><option value="">Todos los estados</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['current_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        @if ($canFilterInspections ?? $canViewRisk)
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
            <div class="forestry-actions"><a class="button secondary" href="{{ route($filterRoute ?? 'trees.index') }}">Limpiar</a><button class="button">Aplicar filtros</button></div>
        </div>
    </form>

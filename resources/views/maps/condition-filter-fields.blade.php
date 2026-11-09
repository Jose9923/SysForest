@foreach (['physical_mechanical' => ['physical_condition_ids', 'Estado físico-mecánico'], 'phytosanitary' => ['phytosanitary_condition_ids', 'Estado fitosanitario']] as $type => [$field, $label])
    @include('inspections.condition-fields', ['categories' => $conditionFilterCategories[$type] ?? collect(), 'assessmentLabel' => $label, 'fieldName' => $field, 'selectedIds' => $initialFilters[$field] ?? [], 'filterMode' => true])
@endforeach
<div class="field">
    <label for="map-condition-mode">Coincidencia de condiciones</label>
    <select id="map-condition-mode" name="condition_mode" data-filter-label="Coincidencia de condiciones" aria-describedby="map-condition-mode-help">
        <option value="any" @selected(($initialFilters['condition_mode'] ?? 'any') === 'any')>Al menos una condición seleccionada</option>
        <option value="all" @selected(($initialFilters['condition_mode'] ?? '') === 'all')>Todas las condiciones seleccionadas</option>
    </select>
    <p class="field-hint" id="map-condition-mode-help">Se consideran juntas las condiciones físico-mecánicas y fitosanitarias: «Al menos una» admite cualquiera de ellas; «Todas» exige cada una en la misma última inspección{{ ($publicMap ?? false) ? ' publicada' : '' }}.</p>
</div>

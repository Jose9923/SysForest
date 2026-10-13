<div class="field">
    <label for="{{ $filterPrefix }}-species">Nombre científico</label>
    <select class="catalog-select" id="{{ $filterPrefix }}-species" name="species_id" data-taxonomy-species>
        <option value="">Todos los nombres científicos</option>
        @foreach ($options['species'] as $option)
            <option value="{{ $option->id }}" @selected(($filters['species_id'] ?? '') === $option->id)>{{ $option->scientific_name }}{{ $option->commonNames->isNotEmpty() ? ' · '.$option->commonNames->pluck('name')->join(', ') : '' }}{{ $option->active ? '' : ' · Inactivo' }}</option>
        @endforeach
    </select>
</div>
<div class="field">
    <label for="{{ $filterPrefix }}-common-name">Nombre común</label>
    <select class="catalog-select" id="{{ $filterPrefix }}-common-name" name="common_name_id" data-taxonomy-common-name aria-describedby="{{ $filterPrefix }}-common-name-help">
        <option value="">Todos los nombres comunes</option>
        @foreach ($options['common_names'] as $option)
            <option value="{{ $option->id }}" data-species-id="{{ $option->species_id }}" @selected(($filters['common_name_id'] ?? '') === $option->id)>{{ $option->name }} · {{ $option->species?->scientific_name }}{{ $option->active ? '' : ' · Inactivo' }}</option>
        @endforeach
    </select>
    <p class="field-hint" id="{{ $filterPrefix }}-common-name-help">Una especie puede tener varios nombres comunes. Cualquiera encuentra los mismos árboles.</p>
</div>

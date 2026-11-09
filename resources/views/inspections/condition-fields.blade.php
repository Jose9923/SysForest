@once
    @push('styles')<link rel="stylesheet" href="{{ asset('css/condition-cascade.css') }}">@endpush
    @push('scripts')<script src="{{ asset('js/condition-cascade.js') }}" defer></script>@endpush
@endonce
@php
    $conditionErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
    $categorySelectId = $fieldName.'-categories';
    $categoryHelpId = $fieldName.'-category-help';
    $seriesFilter = ($filterMode ?? false) && ($seriesFilterMode ?? false);
    $categoryFieldName = $seriesFilter ? str_replace('_condition_ids', '_condition_category_ids', $fieldName) : $fieldName.'_categories';
    $previousCategories = $seriesFilter ? ($selectedCategories ?? []) : (($filterMode ?? false) ? [] : old($categoryFieldName, []));
    $previousCategories = is_array($previousCategories) ? array_values(array_filter($previousCategories, 'is_string')) : [];
    $selectedCategoryIds = $categories->filter(fn ($category) => $category->conditions->contains(fn ($condition) => in_array($condition->id, $selectedIds, true)))->pluck("id")->all();
    $selectedCategoryIds = array_values(array_unique(array_merge($selectedCategoryIds, array_intersect($previousCategories, $categories->pluck('id')->all()))));
@endphp
<div class="inspection-condition-cascade" data-condition-cascade data-condition-field="{{ $fieldName }}" @if ($seriesFilter) data-condition-category-field="{{ $categoryFieldName }}" @endif>
    <div class="field">
        <label for="{{ $categorySelectId }}">Órganos o grupos · {{ $assessmentLabel }}</label>
        <select id="{{ $categorySelectId }}" class="catalog-select" multiple @if (!($filterMode ?? false) || $seriesFilter) name="{{ $categoryFieldName }}[]" @endif @if ($seriesFilter) data-filter-label="Órganos · {{ $assessmentLabel }}" @endif data-condition-category-select data-close-on-select="false" data-placeholder="Busca y selecciona uno o varios órganos" aria-describedby="{{ $categoryHelpId }}">
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(in_array($category->id, $selectedCategoryIds, true))>{{ $category->name }}{{ !$category->active ? ' · referencia histórica inactiva' : '' }}</option>
            @endforeach
        </select>
        <p class="field-hint" id="{{ $categoryHelpId }}">{{ $seriesFilter ? 'Elegir órganos muestra sus condiciones en la gráfica y el reporte. Si marcas condiciones específicas, se muestran solo esas. Al quitar un órgano se limpian sus condiciones.' : (($filterMode ?? false) ? 'Elegir órganos solo muestra las opciones. Los filtros se aplican a las condiciones seleccionadas. Al quitar un órgano se limpian sus condiciones; los demás conservan sus selecciones.' : 'Selecciona varios órganos si es necesario y luego sus condiciones. Al quitar un órgano se limpian únicamente sus condiciones en este formulario; los demás conservan sus selecciones.') }}</p>
    </div>
    <p class="inspection-condition-empty" data-condition-empty @if ($selectedCategoryIds !== []) hidden @endif>Selecciona un órgano o grupo para ver sus condiciones disponibles.</p>
    <div class="inspection-conditions-grid">
        @foreach ($categories as $category)
            @php
                $categorySelected = in_array($category->id, $selectedCategoryIds, true);
                $conditionSelectId = $fieldName.'-'.$category->id;
            @endphp
            <section class="inspection-condition-panel" data-condition-panel="{{ $category->id }}" @if (!$categorySelected) hidden @endif>
                <div class="field">
                    <label for="{{ $conditionSelectId }}">Condiciones · {{ $category->name }}</label>
                    <select id="{{ $conditionSelectId }}" class="catalog-select" multiple name="{{ $fieldName }}[]" data-condition-select @if ($filterMode ?? false) data-filter-label="{{ $assessmentLabel }} · {{ $category->name }}" @endif data-close-on-select="false" data-placeholder="Busca y selecciona las condiciones observadas" aria-describedby="{{ $conditionSelectId }}-help" @disabled(!$categorySelected) @if ($conditionErrors->has($fieldName) || $conditionErrors->has($fieldName.'.*')) aria-invalid="true" @endif>
                        @foreach ($category->conditions as $condition)
                            <option value="{{ $condition->id }}" @selected(in_array($condition->id, $selectedIds, true))>{{ $condition->name }}{{ !$condition->active || !$category->active ? ' · referencia histórica inactiva' : '' }}</option>
                        @endforeach
                    </select>
                    <p class="field-hint" id="{{ $conditionSelectId }}-help">{{ $seriesFilter ? 'Sin marcar condiciones se muestran todas las de los órganos elegidos. Al marcar alguna, la gráfica y el reporte muestran solo las seleccionadas; también se filtran los árboles según el modo de coincidencia.' : (($filterMode ?? false) ? 'Selecciona una o varias condiciones para filtrar. Un órgano sin condiciones seleccionadas no aplica un filtro.' : 'Puedes seleccionar varias condiciones de este órgano o grupo. Dejarlo vacío no declara una ausencia evaluada.') }}</p>
                </div>
            </section>
        @endforeach
    </div>
    <noscript><p class="forestry-note warning">Activa JavaScript para elegir los órganos y consultar sus condiciones.</p></noscript>
</div>
@if ($conditionErrors->has($fieldName))<span class="field-error">{{ $conditionErrors->first($fieldName) }}</span>@endif
@if ($conditionErrors->has($fieldName.'.*'))<span class="field-error">{{ $conditionErrors->first($fieldName.'.*') }}</span>@endif

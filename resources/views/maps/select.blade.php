<div class="field">
    <label for="map-filter-{{ $field }}">{{ $label }}</label>
    <select id="map-filter-{{ $field }}" name="{{ $field }}{{ ($multiple ?? false) ? '[]' : '' }}" data-map-options="{{ $kind }}" data-filter-label="{{ $label }}" data-placeholder="{{ ($multiple ?? false) ? 'Seleccionar condiciones' : 'Todos' }}" @if ($multiple ?? false) multiple @endif>
        @unless ($multiple ?? false)<option value="">Todos</option>@endunless
        @foreach ($selectedOptions[$field] ?? [] as $option)
            <option value="{{ $option['id'] }}" selected>{{ $option['text'] }}</option>
        @endforeach
    </select>
</div>

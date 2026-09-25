<details class="map-filter-panel" data-map-filter-panel open>
    <summary><span>{{ $filterTitle ?? 'Filtros del mapa' }} <span class="map-filter-number" data-map-filter-count>0</span></span><span class="map-filter-toggle" aria-hidden="true">⌄</span></summary>
    <form method="GET" action="{{ $filterAction ?? route('maps.index') }}" data-map-filters>
        @unless ($publicMap ?? false)<input type="hidden" name="scope" value="{{ $mapConfig['scope']['mode'] }}">@endunless
        @foreach ($filterHidden ?? [] as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        <p class="map-hint">Los filtros se combinan. Aplica los cambios para {{ ($publicList ?? false) ? 'actualizar el listado.' : 'actualizar el mapa.' }}</p>
        @include('maps.filter-fields')
        <div class="map-filter-actions"><button class="button" type="submit">Aplicar filtros</button><button class="button secondary" type="button" data-map-reset>{{ ($publicMap ?? false) ? 'Limpiar filtros' : 'Restablecer mapa' }}</button></div>
    </form>
</details>

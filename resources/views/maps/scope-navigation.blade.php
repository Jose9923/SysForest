<section class="map-scope-panel" aria-label="Organizaciones que muestra el mapa">
    <div><h2>Organizaciones en esta vista</h2><p>{{ $scopeNavigation['description'] }}</p>
        @if ($mapConfig['scope']['mode'] !== 'organization' && $scopeNavigation['organization_name'])
            <p class="muted">Organización seleccionada: {{ $scopeNavigation['organization_name'] }}. Esta vista incluye otras organizaciones autorizadas.</p>
        @endif
    </div>
    <nav aria-label="Cambiar organizaciones visibles">
        @foreach ($scopeNavigation['options'] as $option)
            @if ($option['current'])
                <span class="map-scope-current" aria-current="page">{{ match ($option['kind']) { 'national' => 'Todas las organizaciones autorizadas', 'department' => 'Mis departamentos autorizados', default => 'Organización seleccionada' } }}</span>
            @else
                <a class="button secondary" href="{{ $option['url'] }}" data-map-scope-link="{{ $option['kind'] }}" data-clear-fields="{{ json_encode($option['clear_fields']) }}">{{ $option['label'] }}</a>
            @endif
        @endforeach
        @unless ($scopeNavigation['organization_name'])<a class="button secondary" href="{{ route('organizations.index') }}">Seleccionar organización</a>@endunless
    </nav>
    @if (count($scopeNavigation['options']) > 1)
        <p class="map-hint">Al cambiar de vista se limpian territorio, organización e inventario. Se conservan los demás filtros que permiten tus permisos.</p>
    @endif
</section>

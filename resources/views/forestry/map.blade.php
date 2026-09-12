<section class="panel forestry-panel" data-map-panel aria-label="Mapa individual del árbol">
    <div class="map-toolbar"><h2>Ubicación del árbol</h2><button class="button secondary" type="button" data-map-recenter disabled>Centrar punto</button></div>
    <div id="{{ $mapId }}" class="tree-map" data-tree-map data-label="{{ $mapLabel ?? 'Ubicación del árbol' }}"
        data-latitude="{{ $mapLatitude ?? '' }}" data-longitude="{{ $mapLongitude ?? '' }}" data-format="{{ $mapFormat ?? '' }}"
        data-editable="{{ ($mapEditable ?? false) ? 'true' : 'false' }}"
        data-input-id="{{ $mapInputId ?? '' }}" data-datum-id="{{ $mapDatumId ?? '' }}"
        data-preview-url="{{ route('coordinates.preview') }}"
        data-tile-url="{{ config('forestry.tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png') }}"
        role="region" aria-label="Mapa de un árbol, con controles de acercamiento y alejamiento"></div>
    <dl class="coordinate-values"><div><dt>Latitud</dt><dd data-map-latitude>{{ $mapLatitude ?? '—' }}</dd></div><div><dt>Longitud</dt><dd data-map-longitude>{{ $mapLongitude ?? '—' }}</dd></div></dl>
    <p class="field-hint">Formato: <span data-map-format>{{ $mapFormat ?? '—' }}</span></p>
    <p class="map-status" data-map-status role="status" aria-live="polite">Cargando mapa…</p>
    @if ($mapEditable ?? false)<p class="field-hint">También puedes hacer clic en el mapa o arrastrar el marcador para escribir nuevas coordenadas decimales.</p>@endif
    <p class="forestry-note warning" data-map-tile-notice hidden>El mapa base no está disponible. Las coordenadas y su validación siguen disponibles.</p>
    <noscript><p class="forestry-note">Activa JavaScript para visualizar el mapa. Las coordenadas pueden guardarse con el formulario.</p></noscript>
</section>

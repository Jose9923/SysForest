<div class="map-toolbar panel">
    <div class="field map-locator"><label for="map-tree-search">Localizar un árbol</label><select id="map-tree-search" data-map-search data-placeholder="Buscar código o nombre…"><option value=""></option></select><p class="map-hint">Busca entre los árboles que cumplen los filtros aplicados.</p></div>
    <fieldset class="map-mode-picker"><legend>Representar el arbolado como</legend><div>@foreach (['points' => 'Puntos', 'clusters' => 'Agrupaciones', 'heat' => 'Densidad'] as $value => $label)<label><input type="radio" name="render_mode" value="{{ $value }}" @checked(($initialFilters['render_mode'] ?? 'clusters') === $value)><span>{{ $label }}</span></label>@endforeach</div></fieldset>
</div>
<div class="map-active-filters" data-map-active-filters aria-label="Filtros aplicados" hidden></div>
<div class="map-layout">
    @include('maps.filters')
    <div class="map-main">
        <div class="map-counters" aria-label="Resultados de la consulta"><div><span>Total filtrado</span><strong data-map-count="total">—</strong></div><div><span>En este encuadre</span><strong data-map-count="in_view">—</strong></div><div><span>Sin ubicación</span><strong data-map-count="without_geometry">—</strong></div></div>
        @unless ($publicMap ?? false)
            <div class="map-analysis-note" data-map-analysis-note hidden><span aria-hidden="true">◉</span><p><strong>Lee la distribución, no el riesgo.</strong> El calor compara concentraciones dentro de este encuadre. Acércate o filtra una zona para cambiar la escala de análisis.</p></div>
            <div class="map-stage" data-map-stage>
                <div class="map-tools" aria-label="Herramientas cartográficas">
                    <div class="map-tool-group"><button type="button" class="map-tool-button" data-map-locate>Ubicarme</button><button type="button" class="map-tool-button" data-map-show-location hidden>Ver mi ubicación</button></div>
                    <div class="map-tool-group"><button type="button" class="map-tool-button" data-map-measure="distance" aria-pressed="false">Medir distancia</button><button type="button" class="map-tool-button" data-map-measure="area" aria-pressed="false">Medir área</button><button type="button" class="map-tool-button" data-map-measure-clear hidden>Borrar medición</button></div>
                    <div class="map-tool-group map-tool-group-end"><button type="button" class="map-tool-button" data-map-fullscreen aria-pressed="false">Pantalla completa</button></div>
                </div>
                <div class="map-measure-controls" data-map-measure-controls hidden><strong data-map-measure-value>0 vértices</strong><button type="button" class="map-tool-button" data-map-add-center>Añadir centro</button><button type="button" class="map-tool-button" data-map-measure-undo disabled>Deshacer punto</button><button type="button" class="map-tool-button" data-map-measure-finish disabled>Finalizar</button><span>Esc cancela · Enter finaliza desde el mapa</span></div>
        @endunless
        <div class="map-frame"><div class="map-canvas" data-map-canvas aria-label="Mapa interactivo de árboles" role="region" aria-busy="true" tabindex="0"></div><span class="map-loading" data-map-loading>Consultando mapa…</span>@unless ($publicMap ?? false)<span class="map-center-crosshair" aria-hidden="true"></span><span class="map-north" aria-hidden="true"><b>N</b>↑</span>@endunless</div>
        @unless ($publicMap ?? false)
                <div class="map-coordinate-bar"><span><b>WGS84</b> · Latitud, longitud <output data-map-coordinates>—</output></span><span data-map-zoom>Zoom —</span></div>
                <p class="map-tool-status" data-map-tool-status role="status" aria-live="polite">Usa el selector de capas del mapa para cambiar el fondo o los límites territoriales.</p>
            </div>
        @endunless
        <div class="map-below-canvas"><button type="button" class="button secondary" data-map-fit>Ver extensión de los resultados</button>@unless ($publicMap ?? false)<button type="button" class="button secondary" data-map-copy-link>Compartir filtros</button>@endunless<p class="map-tile-notice" data-map-tile-notice hidden>Algunas imágenes del mapa base no pudieron cargarse. Los datos del arbolado se consultan por separado.</p></div>
        <p class="map-base-notice" data-map-base-notice role="note" hidden></p>
        <p class="map-territory-notice" data-map-territory-notice role="note" hidden></p>
        <div class="map-feedback"><p class="map-status" data-map-status role="status" aria-live="polite">Preparando el mapa…</p><button type="button" class="button secondary" data-map-retry hidden>Reintentar</button><p class="map-empty" data-map-empty hidden></p></div>
        <div class="map-search-result panel" data-map-search-result hidden></div>
        <div class="map-context-panels">
            <section class="map-legend panel" aria-label="Leyenda del mapa">
                <div data-map-status-legend><h2>Estado del árbol</h2><ul>@foreach ($mapConfig['legend'] as $item)<li><span class="map-legend-dot" style="background-color: {{ $item['color'] }}" aria-hidden="true"></span>{{ $item['label'] }}</li>@endforeach</ul><p class="map-hint">El número de cada agrupación indica cuántos árboles reúne.</p></div>
                <div data-map-heat-legend hidden><h2>Densidad de árboles</h2><div class="map-heat-ramp" style="background: linear-gradient(90deg, @foreach ($mapConfig['heat_gradient'] as $stop => $color) {{ $color }} {{ (float) $stop * 100 }}%{{ !$loop->last ? ',' : '' }}@endforeach)" aria-hidden="true"></div><div class="map-heat-labels"><span>Menor concentración</span><span>Mayor concentración</span></div><p class="map-hint">Densidad relativa al encuadre. No representa riesgo ni condición sanitaria, y no permite comparar colores entre encuadres distintos.</p></div>
            </section>
            @unless ($publicMap ?? false)
                <section class="map-local-panel panel" aria-labelledby="map-local-title"><div class="map-section-heading"><div><p class="eyebrow">CONTEXTO TERRITORIAL</p><h2 id="map-local-title">Tus capas locales</h2></div><label class="map-file-button" for="map-local-file">+ Añadir GeoJSON<input id="map-local-file" type="file" accept=".geojson,.json,application/geo+json,application/json" data-map-local-file></label></div><p class="map-hint">Superpón zonas, recorridos o puntos en WGS84. Máximo 2 MiB, 3.000 elementos y 30.000 vértices por capa; hasta 3 capas.</p><p class="map-local-empty" data-map-local-empty>Agrega una capa para contrastarla con el arbolado.</p><ul class="map-local-layers" data-map-local-layers aria-label="Capas locales cargadas"></ul><p class="map-hint map-local-privacy">Los archivos se leen en este navegador y se retiran al salir o recargar. No se suben ni cambian los árboles.</p></section>
            @endunless
        </div>
        <details class="map-help panel" id="map-help"><summary>Cómo explorar el mapa</summary><p>Mueve o amplía el mapa para consultar el encuadre visible. Activa un punto para ver sus datos y abrir su ficha; activa una agrupación para explorar sus árboles.</p><p>Con el foco sobre el mapa puedes usar las flechas para moverte y las teclas + y − para cambiar el acercamiento. Los puntos y agrupaciones se pueden activar con el teclado.</p><p>«Total filtrado» incluye los árboles sin ubicación. Los filtros aplicados y la visualización se conservan en la dirección de esta página. {{ ($publicMap ?? false) ? '«Limpiar filtros» vuelve a la extensión de los árboles publicados.' : '«Restablecer mapa» limpia los filtros y vuelve a la extensión de tu alcance.' }}</p>@unless ($publicMap ?? false)<p>Para medir, activa distancia o área y marca vértices en el mapa. También puedes mover el mapa con el teclado y pulsar «Añadir centro». En áreas, dibuja un contorno sin cruces. Las mediciones son aproximaciones sobre una esfera y no sustituyen un levantamiento.</p><p>«Ubicarme» pide permiso al navegador y marca una posición temporal. «Ver mi ubicación» centra el mapa y consulta los árboles de ese encuadre. Las capas GeoJSON locales son referencias visuales: no filtran, validan ni modifican el inventario. Compartir filtros no incluye capas locales, ubicación ni mediciones.</p>@endunless</details>
    </div>
</div>

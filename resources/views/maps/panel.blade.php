        <div class="map-toolbar panel">
            <div class="field map-locator"><label for="map-tree-search">Localizar un árbol</label><select id="map-tree-search" data-map-search data-placeholder="Buscar código o nombre…"><option value=""></option></select><p class="map-hint">Busca entre los árboles que cumplen los filtros aplicados.</p></div>
            <fieldset class="map-mode-picker"><legend>Visualización</legend><div>@foreach (['points' => 'Puntos', 'clusters' => 'Clusters', 'heat' => 'Mapa de calor'] as $value => $label)<label><input type="radio" name="render_mode" value="{{ $value }}" @checked(($initialFilters['render_mode'] ?? 'clusters') === $value)><span>{{ $label }}</span></label>@endforeach</div></fieldset>
        </div>
        <div class="map-active-filters" data-map-active-filters aria-label="Filtros aplicados" hidden></div>
        <div class="map-layout">
            @include('maps.filters')
            <div class="map-main">
                <div class="map-counters" aria-label="Resultados de la consulta"><div><span>Total filtrado</span><strong data-map-count="total">—</strong></div><div><span>En este encuadre</span><strong data-map-count="in_view">—</strong></div><div><span>Sin ubicación</span><strong data-map-count="without_geometry">—</strong></div></div>
                <div class="map-frame"><div class="map-canvas" data-map-canvas aria-label="Mapa interactivo de árboles" role="region" aria-busy="true" tabindex="0"></div><span class="map-loading" data-map-loading>Consultando mapa…</span></div>
                <div class="map-below-canvas"><button type="button" class="button secondary" data-map-fit>Ver extensión de los resultados</button><p class="map-tile-notice" data-map-tile-notice hidden>Algunas imágenes del mapa base no pudieron cargarse. Los datos del arbolado se consultan por separado.</p></div>
                <p class="map-territory-notice" data-map-territory-notice role="note" hidden></p>
                <div class="map-feedback"><p class="map-status" data-map-status role="status" aria-live="polite">Preparando el mapa…</p><button type="button" class="button secondary" data-map-retry hidden>Reintentar</button><p class="map-empty" data-map-empty hidden></p></div>
                <div class="map-search-result panel" data-map-search-result hidden></div>
                <section class="map-legend panel" aria-label="Leyenda del mapa">
                    <div data-map-status-legend><h2>Estado del árbol</h2><ul>@foreach ($mapConfig['legend'] as $item)<li><span class="map-legend-dot" style="background-color: {{ $item['color'] }}" aria-hidden="true"></span>{{ $item['label'] }}</li>@endforeach</ul><p class="map-hint">En modo Clusters, el número indica cuántos árboles reúne cada agrupación.</p></div>
                    <div data-map-heat-legend hidden><h2>Densidad de árboles</h2><div class="map-heat-ramp" style="background: linear-gradient(90deg, @foreach ($mapConfig['heat_gradient'] as $stop => $color) {{ $color }} {{ (float) $stop * 100 }}%{{ !$loop->last ? ',' : '' }}@endforeach)" aria-hidden="true"></div><div class="map-heat-labels"><span>Menor concentración</span><span>Mayor concentración</span></div><p class="map-hint">El color muestra densidad relativa en el encuadre actual. No representa riesgo ni condición sanitaria.</p></div>
                </section>
                <details class="map-help panel" id="map-help"><summary>Cómo explorar el mapa</summary><p>Mueve o amplía el mapa para consultar el encuadre visible. Activa un punto para ver sus datos y abrir su ficha; activa una agrupación para explorar sus árboles.</p><p>Con el foco sobre el mapa puedes usar las flechas para moverte y las teclas + y − para cambiar el acercamiento. Los puntos y agrupaciones se pueden activar con el teclado.</p><p>«Total filtrado» incluye los árboles sin ubicación. Los filtros aplicados y la visualización se conservan en la dirección de esta página. {{ ($publicMap ?? false) ? '«Limpiar filtros» vuelve a la extensión de los árboles publicados.' : '«Restablecer mapa» limpia los filtros y vuelve a la extensión de tu alcance.' }}</p></details>
            </div>
        </div>

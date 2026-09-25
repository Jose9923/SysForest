import {cleanFilters, filterCount, readUrlState, shareableUrl, viewportBounds, pointLatLng, validBounds, territoryNotice, FILTER_KEYS} from './state.js';
import {LatestRequest, debounce, requestUrl} from './api.js';
import {MapFilters} from './filters.js';
import {MapLayers} from './layers.js';
import {treePopupContent} from './popup-content.js';

export function mountMap(root, boot, {popupRenderer = treePopupContent, filterKeys = FILTER_KEYS, fixedScope = null, createRequest = () => new LatestRequest(), onFiltersChanged = () => {}, publicMode = false} = {}) {
    if (!root) return;
    const status = root.querySelector('[data-map-status]');
    const retry = root.querySelector('[data-map-retry]');
    const announce = (message, error = false) => {
        status.textContent = message;
        status.classList.toggle('map-status-error', error);
        retry.hidden = !error;
    };
    try {
        const config = boot.config;
        if (!window.L || !window.Supercluster || !window.LeafletHeat || !window.jQuery?.fn.select2) throw new Error('No se pudieron cargar los componentes locales del mapa. Recarga la página.');
        const L = window.L;
        root.querySelector('[data-map-filter-panel]').open = window.matchMedia('(min-width: 1151px)').matches;
        const origin = window.location.origin;
        const csrf = root.querySelector('[data-map-csrf]')?.value || '';
        const normalize = values => cleanFilters(fixedScope ? {...values, scope: fixedScope} : values, filterKeys);
        let filters = normalize(boot.filters);
        let mode = boot.filters.render_mode || readUrlState(window.location.search, filterKeys).mode;
        let initialized = false;
        let suppressMove = false;
        let extentBounds = null;
        let territoryLayer = null;
        let epoch = 0;
        const viewportRequest = createRequest();
        const extentRequest = createRequest();
        const defaultView = config.default_view || {center: [0, 0], zoom: 2};
        const map = L.map(root.querySelector('[data-map-canvas]'), {zoomControl: false, minZoom: 2, maxZoom: 20, preferCanvas: true, worldCopyJump: false, attributionControl: true}).setView(defaultView.center, defaultView.zoom);
        L.control.zoom({position: 'topright', zoomInTitle: 'Acercar', zoomOutTitle: 'Alejar'}).addTo(map);
        const bases = {};
        config.base_layers.forEach((base, index) => {
            const layer = L.tileLayer(base.url, {attribution: base.attribution, maxNativeZoom: base.max_zoom || 19, maxZoom: 20, noWrap: true, referrerPolicy: 'origin'});
            bases[base.name] = layer;
            if (!index) layer.addTo(map);
            layer.on('tileerror', () => { root.querySelector('[data-map-tile-notice]').hidden = false; });
        });
        const layerControl = L.control.layers(bases, {}, {position: 'topright'}).addTo(map);
        const layerToggle = layerControl.getContainer().querySelector('.leaflet-control-layers-toggle');
        if (layerToggle) { layerToggle.title = 'Capas del mapa'; layerToggle.setAttribute('aria-label', 'Capas del mapa'); }
        const layers = new MapLayers({map, L, Supercluster: window.Supercluster, heatLayer: window.LeafletHeat.heatLayer, heatGradient: config.heat_gradient, legend: config.legend, document, csrf, origin, popupRenderer, onPoints: () => {
            const control = root.querySelector('[name="render_mode"][value="points"]');
            control.checked = true; control.dispatchEvent(new Event('change'));
        }});
        const formatter = new Intl.NumberFormat('es-CO');
        const counters = metadata => {
            ['total', 'in_view', 'without_geometry'].forEach(key => {
                const value = Number(metadata[key]);
                root.querySelector(`[data-map-count="${key}"]`).textContent = Number.isFinite(value) && value >= 0 ? formatter.format(value) : '—';
            });
        };
        const clearCounts = () => root.querySelectorAll('[data-map-count]').forEach(element => { element.textContent = '—'; });
        const loading = value => {
            root.querySelector('[data-map-loading]').hidden = !value;
            root.querySelector('[data-map-canvas]').setAttribute('aria-busy', String(value));
        };
        const applyModeControls = () => {
            root.querySelectorAll('[name="render_mode"]').forEach(input => { input.checked = input.value === mode; });
            root.querySelector('[data-map-status-legend]').hidden = mode === 'heat';
            root.querySelector('[data-map-heat-legend]').hidden = mode !== 'heat';
        };
        const updateUrl = (replace = false) => {
            const url = shareableUrl(window.location.href, filters, mode, filterKeys);
            if (replace) window.history.replaceState(null, '', url);
            else if (`${location.pathname}${location.search}` !== url) window.history.pushState(null, '', url);
        };
        const updateChips = () => {
            const area = root.querySelector('[data-map-active-filters]');
            area.replaceChildren();
            controls.describe(filters).forEach(({text}) => {
                const chip = document.createElement('span'); chip.className = 'map-filter-chip'; chip.textContent = text; area.append(chip);
            });
            root.querySelector('[data-map-filter-count]').textContent = String(filterCount(filters, filterKeys));
            area.hidden = !area.childNodes.length;
            onFiltersChanged(filters);
        };
        const refresh = async () => {
            if (!initialized) return;
            const requestEpoch = epoch;
            const requestMode = mode;
            loading(true);
            announce('Consultando el encuadre…');
            try {
                const bounds = viewportBounds(map.getBounds(), map.getZoom());
                const response = await viewportRequest.get(requestUrl(config.endpoints[requestMode === 'points' ? 'trees' : requestMode], origin, filters, bounds, filterKeys));
                if (!response || requestEpoch !== epoch || requestMode !== mode) return;
                if (response.type !== 'FeatureCollection' || !Array.isArray(response.features)) throw new Error('El servidor devolvió una respuesta de mapa no válida.');
                layers.render(mode, response);
                const meta = response.meta ?? {};
                counters(meta);
                const displayed = response.features.length;
                root.querySelector('[data-map-empty]').hidden = displayed !== 0;
                root.querySelector('[data-map-empty]').textContent = Number(meta.total) === 0 ? 'No hay árboles que coincidan con los filtros.' : Number(meta.georeferenced) === 0 ? 'Los árboles encontrados todavía no tienen ubicación registrada.' : 'No hay árboles visibles en este encuadre. Amplía o restablece la vista.';
                let message = mode === 'heat' ? 'Densidad de árboles en el encuadre; no representa riesgo ni estado sanitario.' : mode === 'clusters' ? 'Agrupaciones de árboles. Activa una agrupación para explorarla.' : `${formatter.format(displayed)} puntos mostrados en este encuadre.`;
                if (meta.truncated) message += ` Se alcanzó el límite de ${formatter.format(config.limits.max_points)} puntos; acerca el mapa o usa Clusters.`;
                else if (meta.aggregation && meta.aggregation !== 'none' && meta.aggregation !== 'points') message += ' Los datos se muestran agrupados; los contadores representan árboles.';
                if (bounds.fullWorld) message += ' El encuadre atraviesa el borde mundial: la consulta abarca todas las longitudes.';
                announce(message);
            } catch (error) {
                if (requestEpoch === epoch) { layers.clear(); clearCounts(); announce(error.message, true); }
            } finally {
                if (requestEpoch === epoch && requestMode === mode && !viewportRequest.controller) loading(false);
            }
        };
        const debouncedRefresh = debounce(refresh, config.limits.debounce_ms || 300);
        const recenter = bounds => {
            if (!validBounds(bounds)) return;
            suppressMove = true;
            map.fitBounds(bounds, {padding: [30, 30], maxZoom: 17, animate: false});
            suppressMove = false;
        };
        const loadExtent = async () => {
            const requestEpoch = epoch;
            loading(true); announce(publicMode ? 'Consultando árboles publicados…' : 'Consultando el alcance autorizado…');
            try {
                const response = await extentRequest.get(requestUrl(config.endpoints.extent, origin, filters, {}, filterKeys));
                if (!response || requestEpoch !== epoch) return;
                const territoriesVisible = territoryLayer ? map.hasLayer(territoryLayer) : true;
                if (territoryLayer) { layerControl.removeLayer(territoryLayer); map.removeLayer(territoryLayer); }
                territoryLayer = null;
                if (response.territories?.type === 'FeatureCollection' && response.territories.features?.some(feature => ['Polygon', 'MultiPolygon'].includes(feature.geometry?.type))) {
                    territoryLayer = L.geoJSON(response.territories, {filter: feature => ['Polygon', 'MultiPolygon'].includes(feature.geometry?.type), style: {color: '#516b5e', weight: 1.5, fillOpacity: 0.03, dashArray: '5 5'}, interactive: false});
                    if (territoriesVisible) territoryLayer.addTo(map);
                    layerControl.addOverlay(territoryLayer, 'Límites territoriales');
                }
                const territoryMessage = territoryNotice(response.territories?.meta);
                const territoryStatus = root.querySelector('[data-map-territory-notice]');
                territoryStatus.textContent = territoryMessage;
                territoryStatus.hidden = !territoryMessage;
                extentBounds = response.bounds;
                if (validBounds(extentBounds)) recenter(extentBounds);
                initialized = true;
                await refresh();
            } catch (error) {
                if (requestEpoch === epoch) { announce(error.message, true); loading(false); }
            }
        };
        const invalidate = () => {
            epoch += 1; debouncedRefresh.cancel(); viewportRequest.cancel(); extentRequest.cancel(); controls.cancelLookups();
            layers.clear(); map.closePopup(); clearCounts();
            root.querySelector('[data-map-empty]').hidden = true;
        };
        const apply = (nextFilters, {reset = false, fromHistory = false} = {}) => {
            invalidate(); filters = normalize(nextFilters); controls.sync(filters); controls.resetSearch();
            updateChips(); applyModeControls();
            if (!fromHistory) updateUrl();
            if (reset && filterCount(filters, filterKeys) === 0) {
                suppressMove = true; map.setView(defaultView.center, defaultView.zoom, {animate: false}); suppressMove = false;
            }
            loadExtent();
        };
        const controls = new MapFilters({form: root.querySelector('[data-map-filters]'), config, document, origin, $: window.jQuery, filterKeys, createRequest,
            getAppliedFilters: () => filters, onApply: next => apply(next), onError: message => announce(message, true),
            onSearch: result => {
                root.querySelector('[data-map-search-result]').hidden = true;
                const point = pointLatLng({geometry: result.geometry});
                const popup = popupRenderer({code: result.text, open_url: result.open_url}, {document, csrf, origin});
                if (point) { map.setView(point, 18); L.popup({maxWidth: 300}).setLatLng(point).setContent(popup).openOn(map); }
                else {
                    const notice = root.querySelector('[data-map-search-result]');
                    notice.replaceChildren();
                    const text = document.createElement('p'); text.textContent = 'Este árbol no tiene ubicación registrada. Puedes consultar su ficha.';
                    notice.append(text, popup); notice.hidden = false;
                    announce('El árbol localizado no tiene ubicación; no se ha creado un punto en el mapa.');
                }
            },
        });
        controls.initialize(); controls.sync(filters); applyModeControls(); updateChips(); updateUrl(true);
        root.querySelectorAll('[name="render_mode"]').forEach(input => input.addEventListener('change', () => {
            if (!input.checked) return;
            invalidate(); mode = input.value; applyModeControls(); updateUrl();
            if (initialized) refresh(); else loadExtent();
        }));
        root.querySelector('[data-map-reset]').addEventListener('click', () => {
            mode = 'clusters'; root.querySelector('[data-map-search-result]').hidden = true; apply({}, {reset: true});
        });
        root.querySelector('[data-map-fit]').addEventListener('click', () => {
            if (validBounds(extentBounds)) { recenter(extentBounds); refresh(); } else loadExtent();
        });
        retry.addEventListener('click', () => { if (initialized) refresh(); else loadExtent(); });
        map.on('moveend', () => {
            if (!suppressMove && initialized) { viewportRequest.cancel(); debouncedRefresh(); }
        });
        root.querySelector('[data-map-filter-panel]').addEventListener('toggle', () => window.requestAnimationFrame(() => map.invalidateSize()));
        window.addEventListener('popstate', () => {
            const state = readUrlState(window.location.search, filterKeys);
            if (fixedScope && state.filters.scope && state.filters.scope !== fixedScope) {
                window.location.assign(shareableUrl(window.location.href, state.filters, state.mode, filterKeys));
                return;
            }
            mode = state.mode; apply(state.filters, {fromHistory: true});
        });
        window.addEventListener('pagehide', () => { debouncedRefresh.cancel(); viewportRequest.cancel(); extentRequest.cancel(); controls.cancelLookups(); layers.clear(); });
        if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize({pan: false})).observe(root.querySelector('[data-map-canvas]'));
        loadExtent();
    } catch (error) { announce(error.message || 'No se pudo iniciar el mapa. Recarga la página.', true); }
}

/** Entradas pequeñas eligen explícitamente política, transporte y navegación. */
export function startMap(optionsFactory = () => ({})) {
    const initialize = () => {
        const root = document.querySelector('[data-general-map]');
        if (!root) return;
        try {
            const boot = JSON.parse(document.getElementById('map-bootstrap').textContent);
            mountMap(root, boot, optionsFactory(boot));
        } catch {
            const status = root.querySelector('[data-map-status]');
            status.textContent = 'No se pudo iniciar el mapa. Recarga la página.';
            status.classList.add('map-status-error');
        }
    };
    if (document.readyState === 'complete') initialize();
    else document.addEventListener('DOMContentLoaded', initialize, {once: true});
}

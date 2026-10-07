import {LOCAL_LAYER_LIMITS, measurementLabel, validateLocalGeoJson} from './geometry.js';

/** Herramientas efímeras de lectura; nunca escriben en el inventario. */
export class MapTools {
    constructor({root, map, L, onMode}) {
        Object.assign(this, {root, map, L, onMode});
        this.stage = root.querySelector('[data-map-stage]');
        this.status = root.querySelector('[data-map-tool-status]');
        this.localLayers = new Map();
        this.drawing = L.layerGroup().addTo(map);
        this.vertices = [];
        this.measureMode = null;
        this.operationMode = 'clusters';
        this.destroyed = false;
        this.colors = ['#7c3aed', '#0369a1', '#c2410c'];
    }

    initialize() {
        this.L.control.scale({position: 'bottomleft', imperial: false, maxWidth: 150}).addTo(this.map);
        this.root.querySelectorAll('[data-map-workspace]').forEach(button => button.addEventListener('click', () => {
            this.onMode(button.dataset.mapWorkspace === 'analysis' ? 'heat' : this.operationMode);
        }));
        this.root.querySelector('[data-map-fullscreen]').addEventListener('click', () => this.toggleFullscreen());
        this.root.querySelector('[data-map-locate]').addEventListener('click', () => this.locate());
        this.root.querySelector('[data-map-show-location]').addEventListener('click', () => {
            if (this.locationPoint) this.map.setView(this.locationPoint, Math.max(this.map.getZoom(), 16));
        });
        this.root.querySelectorAll('[data-map-measure]').forEach(button => button.addEventListener('click', () => this.startMeasurement(button.dataset.mapMeasure)));
        this.root.querySelector('[data-map-add-center]').addEventListener('click', () => this.addVertex(this.map.getCenter()));
        this.root.querySelector('[data-map-measure-undo]').addEventListener('click', () => {
            this.vertices.pop(); this.renderMeasurement();
        });
        this.root.querySelector('[data-map-measure-finish]').addEventListener('click', () => this.finishMeasurement());
        this.root.querySelector('[data-map-measure-clear]').addEventListener('click', () => this.clearMeasurement());
        this.root.querySelector('[data-map-local-file]').addEventListener('change', event => this.importLayer(event.target));
        this.root.querySelector('[data-map-copy-link]').addEventListener('click', () => this.copyLink());
        this.map.on('click', event => { if (this.measureMode) this.addVertex(event.latlng); });
        this.map.on('mousemove', event => this.updateCoordinates(event.latlng));
        this.map.on('moveend zoomend', () => {
            this.updateCoordinates(this.map.getCenter());
            this.root.querySelector('[data-map-zoom]').textContent = `Zoom ${this.map.getZoom()}`;
        });
        this.keyHandler = event => {
            const expanded = document.fullscreenElement === this.stage || this.stage.classList.contains('map-expanded');
            if (event.key === 'Tab' && expanded) {
                const focusable = [...this.stage.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), [tabindex]:not([tabindex="-1"])')]
                    .filter(element => element.getClientRects().length > 0);
                const first = focusable[0];
                const last = focusable.at(-1);
                if (event.shiftKey && (document.activeElement === first || !this.stage.contains(document.activeElement))) {
                    event.preventDefault(); last?.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !this.stage.contains(document.activeElement))) {
                    event.preventDefault(); first?.focus();
                }
            }
            if (event.key === 'Escape') {
                if (this.measureMode) { this.clearMeasurement(); return; }
                if (this.stage.classList.contains('map-expanded')) this.collapse();
            }
            if (event.key === 'Enter' && this.measureMode && event.target === this.map.getContainer()) {
                event.preventDefault(); this.finishMeasurement();
            }
        };
        this.fullscreenHandler = () => {
            this.updateFullscreenButton();
            window.requestAnimationFrame(() => this.map.invalidateSize({pan: false}));
        };
        document.addEventListener('keydown', this.keyHandler);
        document.addEventListener('fullscreenchange', this.fullscreenHandler);
        this.updateCoordinates(this.map.getCenter());
        this.setMode('clusters');
    }

    say(message, error = false) {
        this.status.textContent = message;
        this.status.classList.toggle('map-status-error', error);
    }

    setMode(mode) {
        const analysis = mode === 'heat';
        if (!analysis) this.operationMode = mode;
        this.root.dataset.workspace = analysis ? 'analysis' : 'operation';
        this.root.querySelectorAll('[data-map-workspace]').forEach(button => button.setAttribute('aria-pressed', String((button.dataset.mapWorkspace === 'analysis') === analysis)));
        this.root.querySelector('[data-map-workspace-note]').textContent = analysis
            ? 'Analiza la concentración del arbolado y contrástala con tus capas locales. Los filtros y el alcance autorizado se conservan.'
            : 'Localiza árboles, abre sus fichas y prepara recorridos con filtros, capas y herramientas de lectura.';
        this.root.querySelector('[data-map-analysis-note]').hidden = !analysis;
    }

    updateCoordinates(point) {
        this.root.querySelector('[data-map-coordinates]').textContent = `${point.lat.toFixed(5)}°, ${point.lng.toFixed(5)}°`;
    }

    async copyLink() {
        try {
            if (!navigator.clipboard?.writeText) throw new Error();
            await navigator.clipboard.writeText(window.location.href);
            this.say('Enlace copiado con los filtros y la visualización. Las capas locales y mediciones no se incluyen.');
        } catch {
            this.say('Copia la dirección del navegador para compartir los filtros y la visualización. Las capas locales no se incluyen.');
        }
    }

    async toggleFullscreen() {
        if (document.fullscreenElement === this.stage) { await document.exitFullscreen(); return; }
        if (this.stage.classList.contains('map-expanded')) { this.collapse(); return; }
        if (this.stage.requestFullscreen) {
            try { await this.stage.requestFullscreen(); return; } catch { /* El modo expandido funciona cuando el navegador restringe pantalla completa. */ }
        }
        this.previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        this.stage.classList.add('map-expanded');
        this.updateFullscreenButton();
        window.requestAnimationFrame(() => this.map.invalidateSize({pan: false}));
        this.root.querySelector('[data-map-fullscreen]').focus();
    }

    collapse() {
        this.stage.classList.remove('map-expanded');
        document.body.style.overflow = this.previousOverflow ?? '';
        this.updateFullscreenButton();
        window.requestAnimationFrame(() => this.map.invalidateSize({pan: false}));
    }

    updateFullscreenButton() {
        const expanded = document.fullscreenElement === this.stage || this.stage.classList.contains('map-expanded');
        const button = this.root.querySelector('[data-map-fullscreen]');
        button.setAttribute('aria-pressed', String(expanded));
        button.textContent = expanded ? 'Salir de pantalla completa' : 'Pantalla completa';
        if (expanded) {
            this.stage.setAttribute('role', 'dialog');
            this.stage.setAttribute('aria-modal', 'true');
            this.stage.setAttribute('aria-label', 'Mapa en pantalla completa');
        } else {
            this.stage.removeAttribute('role');
            this.stage.removeAttribute('aria-modal');
            this.stage.removeAttribute('aria-label');
        }
    }

    locate() {
        const button = this.root.querySelector('[data-map-locate]');
        if (!navigator.geolocation) { this.say('Este navegador no ofrece ubicación. Puedes buscar un árbol o navegar por el mapa.', true); return; }
        if (!window.isSecureContext) { this.say('La ubicación requiere HTTPS o una dirección local segura.', true); return; }
        button.disabled = true;
        this.say('Solicitando tu ubicación al navegador…');
        navigator.geolocation.getCurrentPosition(position => {
            button.disabled = false;
            if (this.destroyed) return;
            const {latitude, longitude, accuracy} = position.coords;
            if (![latitude, longitude, accuracy].every(Number.isFinite) || accuracy < 0 || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
                this.say('El navegador no devolvió una ubicación válida.', true); return;
            }
            if (this.locationLayer) this.map.removeLayer(this.locationLayer);
            this.locationPoint = [latitude, longitude];
            this.locationLayer = this.L.layerGroup([
                this.L.circle(this.locationPoint, {radius: accuracy, color: '#2563eb', weight: 1, fillOpacity: 0.08, interactive: false}),
                this.L.circleMarker(this.locationPoint, {radius: 7, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1, interactive: false}),
            ]).addTo(this.map);
            this.root.querySelector('[data-map-show-location]').hidden = false;
            this.say(`Ubicación marcada con precisión aproximada de ${Math.round(accuracy).toLocaleString('es-CO')} m. No se guarda. Al pulsar «Ver mi ubicación» se consultarán los árboles de ese encuadre.`);
        }, error => {
            button.disabled = false;
            if (this.destroyed) return;
            const messages = {1: 'El permiso de ubicación está bloqueado. Puedes habilitarlo en el navegador o seguir explorando manualmente.',
                2: 'No fue posible determinar tu ubicación. Inténtalo en un lugar con mejor señal.', 3: 'La ubicación tardó demasiado. Puedes intentarlo de nuevo.'};
            this.say(messages[error.code] || 'No fue posible obtener tu ubicación.', true);
        }, {enableHighAccuracy: true, timeout: 12000, maximumAge: 30000});
    }

    startMeasurement(mode) {
        this.clearMeasurement(false);
        this.measureMode = mode;
        this.doubleClickWasEnabled = this.map.doubleClickZoom.enabled();
        this.map.doubleClickZoom.disable();
        this.map.closePopup();
        this.root.classList.add('map-measuring');
        this.root.querySelector('[data-map-measure-controls]').hidden = false;
        this.root.querySelectorAll('[data-map-measure]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mapMeasure === mode)));
        this.say(mode === 'area' ? 'Marca al menos tres vértices sin cruzar los lados. Finaliza para calcular el área aproximada.' : 'Marca al menos dos puntos del recorrido. Finaliza para calcular la distancia aproximada.');
        this.map.getContainer().focus();
        this.renderMeasurement();
    }

    addVertex(point) {
        if (!this.measureMode) return;
        if (this.vertices.length >= 500) { this.say('Se alcanzó el límite de 500 vértices. Finaliza o empieza una nueva medición.', true); return; }
        this.vertices.push({lat: point.lat, lng: point.lng});
        this.renderMeasurement();
    }

    renderMeasurement() {
        this.drawing.clearLayers();
        if (this.vertices.length > 1) {
            const shape = this.measureMode === 'area' && this.vertices.length > 2 ? this.L.polygon(this.vertices, {fillOpacity: 0.12}) : this.L.polyline(this.vertices);
            shape.setStyle({color: '#d97706', weight: 3, dashArray: '7 5', interactive: false}).addTo(this.drawing);
        }
        this.vertices.forEach(point => this.L.circleMarker(point, {radius: 4, color: '#92400e', weight: 2, fillColor: '#fff', fillOpacity: 1, interactive: false}).addTo(this.drawing));
        const enough = this.vertices.length >= (this.measureMode === 'area' ? 3 : 2);
        this.root.querySelector('[data-map-measure-value]').textContent = enough ? `${measurementLabel(this.measureMode, this.vertices)} · aproximados` : `${this.vertices.length} vértices`;
        this.root.querySelector('[data-map-measure-finish]').disabled = !enough;
        this.root.querySelector('[data-map-measure-undo]').disabled = !this.vertices.length;
    }

    finishMeasurement() {
        if (!this.measureMode || this.vertices.length < (this.measureMode === 'area' ? 3 : 2)) return;
        this.say(`Medición: ${measurementLabel(this.measureMode, this.vertices)}. Es una aproximación sobre el mapa; no sustituye un levantamiento de campo.`);
        this.measureMode = null;
        this.restoreInteractions();
        this.root.querySelector('[data-map-measure-controls]').hidden = true;
        this.root.querySelector('[data-map-measure-clear]').hidden = false;
    }

    restoreInteractions() {
        this.root.classList.remove('map-measuring');
        if (this.doubleClickWasEnabled) this.map.doubleClickZoom.enable();
        this.root.querySelectorAll('[data-map-measure]').forEach(button => button.setAttribute('aria-pressed', 'false'));
    }

    clearMeasurement(announce = true) {
        this.measureMode = null;
        this.vertices = [];
        this.drawing.clearLayers();
        this.restoreInteractions();
        this.root.querySelector('[data-map-measure-controls]').hidden = true;
        this.root.querySelector('[data-map-measure-clear]').hidden = true;
        if (announce) this.say('Medición retirada. Puedes seguir explorando el mapa.');
    }

    async importLayer(input) {
        const file = input.files?.[0];
        input.value = '';
        if (!file) return;
        if (this.localLayers.size >= LOCAL_LAYER_LIMITS.layers) { this.say('Puedes tener hasta tres capas locales. Retira una para agregar otra.', true); return; }
        if (!file.size || file.size > LOCAL_LAYER_LIMITS.bytes) { this.say('El archivo GeoJSON debe ocupar como máximo 2 MiB y no puede estar vacío.', true); return; }
        input.disabled = true;
        try {
            let data;
            try { data = JSON.parse(await file.text()); } catch { throw new Error('No se pudo leer el archivo. Selecciona un GeoJSON válido, codificado como UTF-8.'); }
            if (this.destroyed) return;
            const collection = validateLocalGeoJson(data);
            const color = this.colors.find(candidate => ![...this.localLayers.values()].some(item => item.color === candidate));
            const layer = this.L.geoJSON(collection, {style: {color, weight: 2, fillOpacity: 0.12},
                pointToLayer: (feature, point) => this.L.circleMarker(point, {radius: 5, color, weight: 2, fillColor: color, fillOpacity: 0.6}),
                onEachFeature: (feature, item) => {
                    const label = document.createElement('span'); label.textContent = feature.properties.label;
                    item.bindTooltip(label);
                }}).addTo(this.map);
            this.localLayers.set(layer, {name: file.name.slice(0, 100), color, count: collection.features.length});
            this.renderLocalLayers();
            this.say(`Capa «${file.name.slice(0, 100)}» añadida. Solo está en esta página; no se subió ni modifica el inventario.`);
        } catch (error) { this.say(error.message || 'No se pudo cargar la capa local.', true); }
        finally { input.disabled = false; }
    }

    renderLocalLayers() {
        const list = this.root.querySelector('[data-map-local-layers]');
        list.replaceChildren();
        this.root.querySelector('[data-map-local-empty]').hidden = this.localLayers.size > 0;
        this.localLayers.forEach((item, layer) => {
            const row = document.createElement('li');
            const label = document.createElement('label');
            const toggle = document.createElement('input'); toggle.type = 'checkbox'; toggle.checked = this.map.hasLayer(layer);
            toggle.addEventListener('change', () => { if (toggle.checked) layer.addTo(this.map); else this.map.removeLayer(layer); });
            const swatch = document.createElement('span'); swatch.className = 'map-layer-swatch'; swatch.style.background = item.color; swatch.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span'); text.textContent = `${item.name} · ${item.count.toLocaleString('es-CO')} elementos`;
            label.append(toggle, swatch, text);
            const zoom = document.createElement('button'); zoom.type = 'button'; zoom.className = 'map-tool-button'; zoom.textContent = 'Ver capa'; zoom.setAttribute('aria-label', `Ver capa ${item.name}`);
            zoom.addEventListener('click', () => {
                if (!this.map.hasLayer(layer)) { layer.addTo(this.map); toggle.checked = true; }
                this.map.fitBounds(layer.getBounds(), {padding: [35, 35], maxZoom: 17});
            });
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'map-tool-button'; remove.textContent = 'Retirar'; remove.setAttribute('aria-label', `Retirar capa ${item.name}`);
            remove.addEventListener('click', () => { this.map.removeLayer(layer); this.localLayers.delete(layer); this.renderLocalLayers(); this.say(`Capa «${item.name}» retirada.`); });
            row.append(label, zoom, remove); list.append(row);
        });
    }

    destroy() {
        this.destroyed = true;
        document.removeEventListener('keydown', this.keyHandler);
        document.removeEventListener('fullscreenchange', this.fullscreenHandler);
        if (this.stage.classList.contains('map-expanded')) this.collapse();
        this.localLayers.forEach((item, layer) => this.map.removeLayer(layer));
        this.localLayers.clear();
        this.drawing.clearLayers();
        if (this.locationLayer) this.map.removeLayer(this.locationLayer);
    }
}

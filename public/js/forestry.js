/* La conversión de coordenadas se valida siempre en el servidor. */
(function () {
    'use strict';

    const decimalPoint = (latitude, longitude) => {
        if (latitude === null || latitude === undefined || latitude === '' ||
            longitude === null || longitude === undefined || longitude === '') return null;
        const lat = Number(latitude);
        const lng = Number(longitude);
        return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180
            ? [lat, lng] : null;
    };

    document.querySelectorAll('[data-species-select]').forEach((select) => {
        const update = () => {
            const option = select.selectedOptions[0];
            const family = document.getElementById(select.dataset.familyOutput);
            const common = document.getElementById(select.dataset.commonOutput);
            if (family) family.textContent = option?.dataset.family || 'Sin especie seleccionada';
            if (common) common.textContent = option?.dataset.common || 'Sin nombre común registrado';
        };
        select.addEventListener('change', update);
        if (window.jQuery) window.jQuery(select).on('change', update);
        update();
    });

    document.querySelectorAll('[data-confirm-submit]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirmSubmit)) event.preventDefault();
        });
    });

    const initializeMap = (element) => {
        const wrapper = element.closest('[data-map-panel]');
        const status = wrapper.querySelector('[data-map-status]');
        const latitudeOutput = wrapper.querySelector('[data-map-latitude]');
        const longitudeOutput = wrapper.querySelector('[data-map-longitude]');
        const formatOutput = wrapper.querySelector('[data-map-format]');
        const tileNotice = wrapper.querySelector('[data-map-tile-notice]');
        const recenter = wrapper.querySelector('[data-map-recenter]');
        const input = element.dataset.inputId ? document.getElementById(element.dataset.inputId) : null;
        const datum = element.dataset.datumId ? document.getElementById(element.dataset.datumId) : null;
        const editable = element.dataset.editable === 'true';
        let marker = null;
        let timer = null;
        let request = null;
        let sequence = 0;
        const tell = (message, invalid = false) => {
            status.textContent = message;
            status.classList.toggle('invalid', invalid);
        };
        if (!window.L) {
            tell('El mapa no pudo cargarse. Puedes conservar y guardar las coordenadas escritas.', true);
            return;
        }
        const map = window.L.map(element, {scrollWheelZoom: false, worldCopyJump: true});
        const tiles = window.L.tileLayer(element.dataset.tileUrl, {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
            referrerPolicy: 'origin'
        }).addTo(map);
        tiles.on('tileerror', () => { tileNotice.hidden = false; });
        const clear = () => {
            if (marker) map.removeLayer(marker);
            marker = null;
            latitudeOutput.textContent = '—';
            longitudeOutput.textContent = '—';
            if (formatOutput) formatOutput.textContent = '—';
            recenter.disabled = true;
        };
        const show = (point, format = '') => {
            latitudeOutput.textContent = point[0].toFixed(8);
            longitudeOutput.textContent = point[1].toFixed(8);
            if (formatOutput) formatOutput.textContent = format || '—';
            if (!marker) {
                const popup = document.createElement('span');
                popup.textContent = element.dataset.label || 'Ubicación del árbol';
                marker = window.L.marker(point, {draggable: editable, keyboard: true, title: element.dataset.label || 'Árbol'})
                    .addTo(map).bindPopup(popup);
                if (editable && input) marker.on('dragend', () => {
                    const position = marker.getLatLng();
                    input.value = position.lat.toFixed(8) + ', ' + position.lng.toFixed(8);
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                });
            } else marker.setLatLng(point);
            map.setView(point, Math.max(map.getZoom(), 17));
            recenter.disabled = false;
        };
        map.fitWorld();
        const initial = decimalPoint(element.dataset.latitude, element.dataset.longitude);
        if (initial) {
            show(initial, element.dataset.format);
            tell(editable ? 'Punto validado. Puedes arrastrarlo para ajustar las coordenadas.' : 'Ubicación registrada del árbol.');
        } else {
            clear();
            tell('Sin coordenadas. El mapa no contiene un punto del árbol.');
        }
        recenter.addEventListener('click', () => { if (marker) map.setView(marker.getLatLng(), 18); });
        const disclosure = element.closest('details');
        if (disclosure) disclosure.addEventListener('toggle', () => {
            if (disclosure.open) window.setTimeout(() => map.invalidateSize(), 0);
        });
        if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize()).observe(element);
        if (!input || !editable) return;
        const preview = async () => {
            const currentSequence = ++sequence;
            if (request) request.abort();
            if (!input.value.trim()) {
                clear();
                tell('Sin coordenadas. Puedes registrar el árbol sin inventar una ubicación.');
                return;
            }
            request = new AbortController();
            tell('Validando coordenadas…');
            try {
                const csrf = document.querySelector('input[name="_token"]')?.value || '';
                const response = await fetch(element.dataset.previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({coordinate_raw: input.value, datum: datum?.value || null}),
                    signal: request.signal
                });
                const payload = await response.json().catch(() => ({}));
                if (currentSequence !== sequence) return;
                if (!response.ok) {
                    clear();
                    const validation = Object.values(payload.errors || {}).flat().find((message) => typeof message === 'string');
                    tell(response.status === 401 || response.status === 419
                        ? 'Tu sesión debe renovarse. Guarda el texto y vuelve a ingresar.'
                        : validation || 'No se pudieron validar estas coordenadas. Revisa el formato.', true);
                    return;
                }
                const point = decimalPoint(payload.latitude, payload.longitude);
                if (!point) {
                    clear();
                    tell('El servidor no devolvió un punto válido.', true);
                    return;
                }
                show(point, payload.format);
                tell('Coordenadas válidas. Confirma el punto y guarda el formulario para registrarlas.');
            } catch (error) {
                if (error.name === 'AbortError') return;
                if (currentSequence !== sequence) return;
                clear();
                tell('No se pudo comprobar la ubicación. Revisa la conexión; el texto permanece en el formulario.', true);
            }
        };
        const schedule = () => {
            sequence++;
            if (request) request.abort();
            window.clearTimeout(timer);
            clear();
            tell(input.value.trim() ? 'Coordenadas pendientes de validación…' : 'Sin coordenadas.');
            timer = window.setTimeout(preview, 350);
        };
        map.on('click', (event) => {
            input.value = event.latlng.lat.toFixed(8) + ', ' + event.latlng.lng.toFixed(8);
            input.dispatchEvent(new Event('input', {bubbles: true}));
        });
        input.addEventListener('input', schedule);
        if (datum) datum.addEventListener('change', schedule);
        if (input.value.trim()) preview();
    };
    document.querySelectorAll('[data-tree-map]').forEach((element) => {
        const disclosure = element.closest('details');
        if (disclosure && !disclosure.open) {
            const onOpen = () => {
                if (!disclosure.open) return;
                disclosure.removeEventListener('toggle', onOpen);
                initializeMap(element);
            };
            disclosure.addEventListener('toggle', onOpen);
        } else initializeMap(element);
    });
})();

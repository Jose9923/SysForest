import {MapFilters} from '../map/filters.js';
import {LatestRequest} from '../map/api.js';
import {cleanFilters, filtersFromEntries, filterCount, filterParams, sameOriginUrl} from '../map/state.js';
import {REPORT_FILTER_KEYS} from '../reports/links.js';

function initialize() {
    const root = document.querySelector('[data-risk-page]');
    if (!root) return;
    const status = root.querySelector('[data-risk-status]');
    try {
        const boot = JSON.parse(document.getElementById('risk-bootstrap').textContent);
        const config = boot.config;
        const filterKeys = (config.filter_keys ?? REPORT_FILTER_KEYS).filter(key => REPORT_FILTER_KEYS.includes(key));
        const normalize = values => cleanFilters({...values, scope: 'organization'}, filterKeys);
        const form = root.querySelector('[data-risk-filters]');
        const target = sameOriginUrl(form.action, location.origin);
        if (target.pathname !== '/risk' || config.scope.mode !== 'organization') throw new Error('Destino no válido.');
        const applied = normalize(boot.filters);
        const applySelection = () => {
            const values = normalize(filtersFromEntries(new FormData(form).entries(), filterKeys));
            target.search = filterParams(values, filterKeys).toString();
            target.hash = '';
            location.assign(`${target.pathname}${target.search}`);
        };
        const controls = new MapFilters({form, config, document, origin: location.origin, $: window.jQuery, filterKeys,
            createRequest: () => new LatestRequest(undefined, {credentials: 'same-origin'}),
            getAppliedFilters: () => applied, onApply: applySelection,
            onError: message => { status.textContent = message; status.hidden = false; }, onSearch: () => {}});
        controls.initialize(); controls.sync(applied);
        root.querySelector('[data-map-filter-count]').textContent = String(filterCount(applied, filterKeys));
        window.addEventListener('pagehide', () => controls.cancelLookups());
    } catch {
        status.textContent = 'No se pudieron cargar los filtros dinámicos. Puedes aplicar el formulario o limpiar los filtros.';
        status.hidden = false;
    }
}
if (document.readyState === 'complete') initialize();
else document.addEventListener('DOMContentLoaded', initialize, {once: true});

import {MapFilters} from '../map/filters.js';
import {LatestRequest} from '../map/api.js';
import {cleanFilters, filtersFromEntries, filterCount, sameOriginUrl} from '../map/state.js';
import {PUBLIC_REPORT_FILTER_KEYS, REPORT_FILTER_KEYS, reportPageUrl} from './links.js';

function initialize() {
    const root = document.querySelector('[data-reports-page]');
    if (!root) return;
    const status = root.querySelector('[data-reports-status]');
    try {
        const boot = JSON.parse(document.getElementById('reports-bootstrap').textContent);
        const config = boot.config;
        const isPublic = boot.isPublic === true;
        const endpoint = sameOriginUrl(config.endpoints.options, location.origin);
        if (isPublic && (config.access !== 'public' || config.credentials !== 'omit'
            || !endpoint.pathname.startsWith('/api/public/v1/') || endpoint.search || endpoint.hash)) throw new Error('Configuración no válida.');
        const allowedKeys = isPublic ? PUBLIC_REPORT_FILTER_KEYS : REPORT_FILTER_KEYS;
        const filterKeys = (config.filter_keys ?? allowedKeys).filter(key => allowedKeys.includes(key));
        const fixedScope = isPublic ? null : config.scope.mode;
        const normalize = values => cleanFilters(fixedScope ? {...values, scope: fixedScope} : values, filterKeys);
        const form = root.querySelector('[data-report-filters]');
        const applied = normalize(boot.filters);
        const selectedReport = () => form.elements.namedItem('report').value;
        const navigate = (filters, report) => location.assign(reportPageUrl(form.action, filters,
            {origin: location.origin, isPublic, filterKeys, report}));
        const applySelection = () => navigate(normalize(filtersFromEntries(new FormData(form).entries(), filterKeys)), selectedReport());
        const controls = new MapFilters({form, config, document, origin: location.origin, $: window.jQuery, filterKeys,
            createRequest: () => new LatestRequest(undefined, {credentials: isPublic ? 'omit' : 'same-origin', public: isPublic}),
            getAppliedFilters: () => applied,
            onApply: applySelection,
            onError: message => { status.textContent = message; status.hidden = false; }, onSearch: () => {}});
        controls.initialize(); controls.sync(applied);
        window.jQuery(form.elements.namedItem('report')).on('change.reportKind', applySelection);
        root.querySelector('[data-map-filter-count]').textContent = String(filterCount(applied, filterKeys));
        root.querySelector('[data-map-filter-panel]').open = window.matchMedia('(min-width: 1151px)').matches;
        root.querySelector('[data-reports-reset]').addEventListener('click', () => navigate(normalize({}), boot.report));
        window.addEventListener('pagehide', () => controls.cancelLookups());
    } catch {
        status.textContent = 'No se pudieron cargar los filtros dinámicos. Puedes aplicar el formulario y consultar las descargas disponibles.';
        status.hidden = false;
    }
}
if (document.readyState === 'complete') initialize();
else document.addEventListener('DOMContentLoaded', initialize, {once: true});

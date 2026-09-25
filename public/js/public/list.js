import {MapFilters} from '../map/filters.js';
import {cleanFilters, filterCount, shareableUrl} from '../map/state.js';
import {publicListUrl, publicMapPolicy} from './policy.js';

function initialize() {
    const root = document.querySelector('[data-public-list]');
    if (!root) return;
    const status = root.querySelector('[data-public-filter-status]');
    try {
        const boot = JSON.parse(document.getElementById('public-list-bootstrap').textContent);
        const policy = publicMapPolicy(boot.config, location.origin);
        const filters = cleanFilters(boot.filters, policy.filterKeys);
        const form = root.querySelector('[data-map-filters]');
        const orderForm = root.querySelector('[data-public-order]');
        const order = () => ({sort: orderForm.elements.namedItem('sort').value, direction: orderForm.elements.namedItem('direction').value});
        const navigate = values => window.location.assign(publicListUrl(form.action, values, order(), policy.filterKeys));
        const controls = new MapFilters({form, config: boot.config, document, origin: location.origin, $: window.jQuery,
            filterKeys: policy.filterKeys, createRequest: policy.createRequest, getAppliedFilters: () => filters,
            onApply: navigate, onError: message => { status.textContent = message; status.hidden = false; }, onSearch: () => {}});
        controls.initialize(); controls.sync(filters);
        root.querySelector('[data-map-filter-panel]').open = window.matchMedia('(min-width: 1151px)').matches;
        root.querySelector('[data-map-filter-count]').textContent = String(filterCount(filters, policy.filterKeys));
        root.querySelector('[data-map-reset]').addEventListener('click', () => window.location.assign(publicListUrl(form.action, {}, {}, policy.filterKeys)));
        orderForm.addEventListener('submit', event => { event.preventDefault(); navigate(filters); });
        const mapLink = root.querySelector('[data-public-map-link]');
        if (mapLink) mapLink.href = shareableUrl(mapLink.href, filters, 'clusters', policy.filterKeys);
        window.addEventListener('pagehide', () => controls.cancelLookups());
    } catch { status.textContent = 'No se pudieron cargar los filtros. Puedes usar la búsqueda y navegación del listado.'; status.hidden = false; }
}
if (document.readyState === 'complete') initialize();
else document.addEventListener('DOMContentLoaded', initialize, {once: true});

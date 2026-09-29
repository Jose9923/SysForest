import {cleanFilters, filtersFromEntries, ARRAY_KEYS, FILTER_KEYS} from './state.js';
import {LatestRequest, requestUrl} from './api.js';

const CASCADES = Object.freeze({
    department_id: ['municipality_id', 'organization_id', 'inventory_id'],
    municipality_id: ['organization_id', 'inventory_id'],
    organization_id: ['inventory_id'],
    family_id: ['species_id'],
});

export class MapFilters {
    constructor({form, config, getAppliedFilters, onApply, onSearch, onError, document, origin, $, filterKeys = FILTER_KEYS, createRequest = () => new LatestRequest()}) {
        Object.assign(this, {form, config, getAppliedFilters, onApply, onSearch, onError, document, origin, $, filterKeys, createRequest});
        this.requests = [];
        this.synchronizing = false;
    }
    initialize() {
        this.form.addEventListener('submit', event => { event.preventDefault(); this.onApply(this.values()); });
        this.form.querySelectorAll('[data-map-options]').forEach(select => this.remoteSelect(select));
        Object.entries(CASCADES).forEach(([parent, descendants]) => {
            const select = this.form.elements.namedItem(parent);
            if (select) this.$(select).on('change.mapCascade', () => {
                if (this.synchronizing) return;
                this.synchronizing = true;
                descendants.forEach(name => {
                    const field = this.form.elements.namedItem(name);
                    if (field) this.$(field).val(null).trigger('change.select2');
                });
                this.synchronizing = false;
            });
        });
        const search = this.document.querySelector('[data-map-search]');
        if (search) this.remoteSelect(search, true);
    }
    values() { return filtersFromEntries(new FormData(this.form).entries(), this.filterKeys); }
    remoteSelect(select, search = false) {
        const request = this.createRequest();
        this.requests.push(request);
        const endpoint = search ? this.config.endpoints.search : `${this.config.endpoints.options.replace(/\/$/, '')}/${select.dataset.mapOptions}`;
        this.$(select).select2({
            width: '100%', language: 'es', placeholder: select.dataset.placeholder || 'Todos',
            allowClear: true, closeOnSelect: !select.multiple, minimumInputLength: search ? 1 : 0,
            ajax: {
                delay: this.config.limits.debounce_ms || 300,
                data: params => ({term: params.term || '', page: params.page || 1}),
                transport: (params, success, failure) => {
                    const filters = search ? this.getAppliedFilters() : this.values();
                    request.get(requestUrl(endpoint, this.origin, filters, params.data, this.filterKeys)).then(data => {
                        if (data) success(data);
                    }).catch(error => { this.onError(error.message); failure(error); });
                    return {abort: () => request.cancel()};
                },
                processResults: data => ({results: Array.isArray(data.results) ? data.results : [], pagination: {more: data.pagination?.more === true}}),
                cache: false,
            },
        });
        this.document.defaultView?.SysforestSelect2Accessibility?.associate(select, this.$(select).data('select2'));
        if (search) this.$(select).on('select2:select', event => this.onSearch(event.params.data));
    }
    cancelLookups() { this.requests.forEach(request => request.cancel()); }
    sync(filters) {
        this.synchronizing = true;
        const values = cleanFilters(filters, this.filterKeys);
        for (const element of this.form.elements) {
            if (!element.name) continue;
            const key = element.name.replace(/\[\]$/, '');
            if (!this.filterKeys.includes(key)) continue;
            const value = values[key] ?? (ARRAY_KEYS.includes(key) ? [] : key === 'condition_mode' ? 'any' : '');
            if (element.type === 'checkbox') element.checked = value === element.value;
            else if (element.tagName === 'SELECT') {
                const selected = Array.isArray(value) ? value : [value];
                selected.filter(Boolean).forEach(id => {
                    if (![...element.options].some(option => option.value === id)) element.add(new Option('Referencia seleccionada', id));
                });
                [...element.options].forEach(option => { option.selected = selected.includes(option.value); });
                if (this.$(element).hasClass('select2-hidden-accessible')) this.$(element).trigger('change.select2');
            } else element.value = value;
        }
        this.synchronizing = false;
    }
    resetSearch() { this.$('[data-map-search]').val(null).trigger('change.select2'); }
    describe(filters) {
        return Object.entries(cleanFilters(filters, this.filterKeys)).filter(([key]) => !['condition_mode', 'scope'].includes(key)).map(([key, value]) => {
            const element = this.form.elements.namedItem(ARRAY_KEYS.includes(key) ? `${key}[]` : key);
            const name = element?.dataset.filterLabel || key;
            let text = String(value);
            if (element?.tagName === 'SELECT') {
                const values = Array.isArray(value) ? value : [value];
                text = [...element.options].filter(option => values.includes(option.value)).map(option => option.textContent).join(', ') || 'Seleccionado';
            } else if (element?.type === 'checkbox') text = 'Sí';
            return {key, text: `${name}: ${text}`};
        });
    }
}

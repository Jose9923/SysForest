import test from 'node:test';
import assert from 'node:assert/strict';

test('cambiar tipo mediante Select2 o evento nativo navega con los filtros editados del formulario', async () => {
    const origin = 'https://forest.test';
    const field = (name, value, options = null) => Object.assign(new EventTarget(), {
        name, value, type: 'text', tagName: options ? 'SELECT' : 'INPUT', dataset: {},
        options: options?.map(option => ({value: option, selected: false})),
    });
    const report = field('report', 'inventory', ['inventory', 'species', 'families']);
    const code = field('code', 'OLD');
    const protectedField = field('is_protected', '0');
    const conditions = field('physical_condition_ids[]', '', ['PHY_ROOT_GOOD', 'PHY_STEM_CRACK']);
    conditions.multiple = true;
    const mode = field('condition_mode', 'all');
    const privateScope = field('scope', 'national');
    const elements = [report, code, protectedField, conditions, mode, privateScope];
    elements.namedItem = name => elements.find(element => element.name === name) ?? null;
    const form = Object.assign(new EventTarget(), {
        action: `${origin}/portal/reports`, elements, querySelectorAll: () => [],
    });
    const status = {textContent: '', hidden: true};
    const reset = new EventTarget();
    const count = {textContent: ''};
    const panel = {open: true};
    const nodes = {'[data-report-filters]': form, '[data-reports-status]': status,
        '[data-map-filter-count]': count, '[data-map-filter-panel]': panel, '[data-reports-reset]': reset};
    const root = {querySelector: selector => nodes[selector] ?? null};
    const filters = {code: 'OLD', is_protected: '0', physical_condition_ids: ['PHY_ROOT_GOOD'], condition_mode: 'all'};
    const boot = {isPublic: true, report: 'inventory', filters, config: {
        access: 'public', credentials: 'omit', endpoints: {options: '/api/public/v1/map/options'},
        limits: {debounce_ms: 300}, filter_keys: Object.keys(filters),
    }};
    const document = Object.assign(new EventTarget(), {
        readyState: 'loading', querySelector: selector => selector === '[data-reports-page]' ? root : null,
        getElementById: id => id === 'reports-bootstrap' ? {textContent: JSON.stringify(boot)} : null,
    });
    // Select2 notifica por jQuery; el adaptador conserva el mismo contrato de eventos y el despacho nativo.
    const $ = element => ({
        on(name, listener) { element.addEventListener(name.split('.')[0], listener); return this; },
        trigger(name) { element.dispatchEvent(new Event(name.split('.')[0])); return this; },
        hasClass: () => false,
    });
    const window = Object.assign(new EventTarget(), {jQuery: $, matchMedia: () => ({matches: true})});
    const navigations = [];
    const location = {origin, assign: url => navigations.push(new URL(url, origin))};
    class FormDataFixture {
        constructor(target) { this.form = target; }
        *entries() {
            for (const element of this.form.elements) {
                if (element.multiple) {
                    for (const option of element.options.filter(option => option.selected)) yield [element.name, option.value];
                } else yield [element.name, element.value];
            }
        }
    }
    const globals = {document, window, location, FormData: FormDataFixture};
    const previous = Object.fromEntries(Object.keys(globals).map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    try {
        for (const [key, value] of Object.entries(globals)) Object.defineProperty(globalThis, key, {value, writable: true, configurable: true});
        await import('../../public/js/reports/filters.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));
        assert.equal(status.hidden, true, status.textContent);
        assert.equal(navigations.length, 0);

        code.value = 'NEW-CODE';
        conditions.options[1].selected = true;
        report.value = 'species';
        $(report).trigger('change');
        assert.equal(navigations.length, 1);
        assert.equal(navigations[0].pathname, '/portal/reports');
        assert.equal(navigations[0].searchParams.get('report'), 'species');
        assert.equal(navigations[0].searchParams.get('code'), 'NEW-CODE');
        assert.equal(navigations[0].searchParams.get('is_protected'), '0');
        assert.deepEqual(navigations[0].searchParams.getAll('physical_condition_ids[]'), ['PHY_ROOT_GOOD', 'PHY_STEM_CRACK']);
        assert.equal(navigations[0].searchParams.has('scope'), false);

        report.value = 'families';
        code.value = 'NATIVE-CHANGE';
        report.dispatchEvent(new Event('change'));
        assert.equal(navigations.length, 2);
        assert.equal(navigations[1].searchParams.get('report'), 'families');
        assert.equal(navigations[1].searchParams.get('code'), 'NATIVE-CHANGE');
    } finally {
        for (const [key, descriptor] of Object.entries(previous)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
    }
});

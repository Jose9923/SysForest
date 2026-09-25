import test from 'node:test';
import assert from 'node:assert/strict';
import {PUBLIC_FILTER_KEYS, publicMapPolicy, publicListUrl, publicTreeUrl} from '../../public/js/public/policy.js';
import {cleanFilters, readUrlState, shareableUrl, filterCount} from '../../public/js/map/state.js';
import {requestUrl} from '../../public/js/map/api.js';
import {MapFilters} from '../../public/js/map/filters.js';

const origin = 'https://forest.test';
const config = () => ({access: 'public', credentials: 'omit', filter_keys: PUBLIC_FILTER_KEYS,
    endpoints: Object.fromEntries(['trees', 'clusters', 'heat', 'extent', 'search'].map(key => [key, `/api/public/v1/map/${key}`]).concat([['options', '/api/public/v1/options']]))});

test('filtros públicos conservan códigos compuestos y falsos, excluyendo alcance e identificadores privados', () => {
    const values = cleanFilters({department_id: 'COL~ANT', municipality_id: 'COL~ANT~05001', organization_id: 'AMVA', inventory_id: 'AMVA~2026',
        is_protected: false, physical_condition_ids: ['PHY_ROOT_GOOD'], scope: 'national', tree_id: 'private-id', trashed: 'only', maintenance_priority: 'high', has_pending_maintenance: '1'}, PUBLIC_FILTER_KEYS);
    assert.deepEqual(values, {department_id: 'COL~ANT', municipality_id: 'COL~ANT~05001', organization_id: 'AMVA', inventory_id: 'AMVA~2026', is_protected: '0', physical_condition_ids: ['PHY_ROOT_GOOD']});
    assert.equal(filterCount(values, PUBLIC_FILTER_KEYS), 6);
});

test('mapa y listado comparten filtros sin copiar página, alcance ni modo a la tabla', () => {
    const map = shareableUrl(`${origin}/portal/map`, {organization_id: 'ENTIDAD A', is_heritage: '0', physical_condition_ids: ['ROOT_A', 'ROOT_B']}, 'heat', PUBLIC_FILTER_KEYS);
    const state = readUrlState(new URL(map, origin).search, PUBLIC_FILTER_KEYS);
    assert.equal(state.mode, 'heat');
    const list = publicListUrl(`${origin}/portal/trees?page=3&scope=national`, state.filters, {sort: 'scientific_name', direction: 'desc'});
    const params = new URL(list, origin).searchParams;
    assert.equal(params.get('organization_id'), 'ENTIDAD A');
    assert.equal(params.get('is_heritage'), '0');
    assert.deepEqual(params.getAll('physical_condition_ids[]'), ['ROOT_A', 'ROOT_B']);
    assert.equal(params.get('sort'), 'scientific_name');
    assert.equal(params.get('direction'), 'desc');
    for (const key of ['page', 'scope', 'render_mode']) assert.equal(params.has(key), false);
    assert.equal(publicListUrl(`${origin}/portal/trees`, {}, {sort: 'notes', direction: 'anything'}), '/portal/trees');
});

test('política pública rechaza endpoints privados o externos y no admite modo autenticado', () => {
    for (const endpoint of ['/api/v1/map/trees', 'https://external.test/api/public/v1/map/trees', '/api/public/v1/map/trees?scope=organization', '/api/public/v1/map/trees#token']) {
        const candidate = config(); candidate.endpoints.trees = endpoint;
        assert.throws(() => publicMapPolicy(candidate, origin));
    }
    assert.throws(() => publicMapPolicy({...config(), credentials: 'same-origin'}, origin));
    assert.throws(() => publicMapPolicy({...config(), access: 'private'}, origin));
    const policy = publicMapPolicy({...config(), filter_keys: [...PUBLIC_FILTER_KEYS, 'scope', 'tree_id']}, origin);
    assert.equal(policy.filterKeys.includes('scope'), false);
    assert.equal(policy.fixedScope, null);
});

test('transporte público omite cookies y tokens y conserva q separado de term en Select2', async () => {
    let received;
    const policy = publicMapPolicy(config(), origin, async (url, options) => { received = {url, options}; return {ok: true, json: async () => ({results: []})}; });
    await policy.createRequest().get(requestUrl(config().endpoints.search, origin, {q: 'Oití', organization_id: 'AMVA', scope: 'national'}, {term: 'ARB-001', page: 2}, policy.filterKeys));
    assert.equal(received.options.credentials, 'omit');
    assert.equal(received.options.headers.Accept, 'application/json');
    assert.equal(received.options.headers.Authorization, undefined);
    assert.equal(received.options.headers['X-CSRF-TOKEN'], undefined);
    assert.equal(received.options.headers.Cookie, undefined);
    assert.equal(received.url.searchParams.get('q'), 'Oití');
    assert.equal(received.url.searchParams.get('term'), 'ARB-001');
    assert.equal(received.url.searchParams.has('scope'), false);
    assert.equal(received.url.searchParams.has('_token'), false);
});

test('errores y redirecciones públicas no sugieren login ni muestran respuestas internas', async () => {
    for (const status of [401, 403, 419]) {
        const client = publicMapPolicy(config(), origin, async () => ({ok: false, status, json: async () => ({message: 'SQL secreto'})})).createRequest();
        await assert.rejects(client.get('/api/public/v1/map/trees'), error => !/sesión|login|SQL secreto/i.test(error.message));
    }
    const client = publicMapPolicy(config(), origin, async () => ({ok: true, redirected: true})).createRequest();
    await assert.rejects(client.get('/api/public/v1/map/trees'), error => !/sesión|login/i.test(error.message));
});

test('cancelación y descarte de respuestas antiguas se mantienen en transporte público', async () => {
    const pending = [];
    const client = publicMapPolicy(config(), origin, (url, options) => new Promise(resolve => pending.push({options, resolve}))).createRequest();
    const older = client.get('/first'); const newer = client.get('/second');
    assert.equal(pending[0].options.signal.aborted, true);
    pending[1].resolve({ok: true, json: async () => ({total: 2})});
    assert.deepEqual(await newer, {total: 2});
    pending[0].resolve({ok: true, json: async () => ({total: 500})});
    assert.equal(await older, null);
});

test('sincronizar referencias públicas conserva campos auxiliares de ordenación', () => {
    const order = {name: 'sort', value: 'municipality'};
    const query = {name: 'q', tagName: 'INPUT', value: ''};
    const checkbox = {name: 'is_protected', tagName: 'SELECT', options: [{value: ''}, {value: '0'}, {value: '1'}]};
    const controls = new MapFilters({form: {elements: [order, query, checkbox]}, filterKeys: PUBLIC_FILTER_KEYS, $: () => ({hasClass: () => false})});
    controls.sync({q: 'Oití', is_protected: false});
    assert.equal(order.value, 'municipality'); assert.equal(query.value, 'Oití');
    assert.equal(checkbox.options[1].selected, true); assert.equal(checkbox.options[2].selected, false);
});

test('ficha pública permite únicamente GET al árbol público sin parámetros privados', () => {
    assert.equal(publicTreeUrl('/portal/organizations/AMVA/trees/ARB-000001', origin).pathname, '/portal/organizations/AMVA/trees/ARB-000001');
    for (const value of ['/map/trees/uuid/open', '/portal/organizations/AMVA/trees/ARB-1?scope=national', '/portal/organizations/AMVA/trees/ARB-1#private', '//external.test/portal/organizations/X/trees/Y', 'javascript:alert(1)']) assert.throws(() => publicTreeUrl(value, origin));
});

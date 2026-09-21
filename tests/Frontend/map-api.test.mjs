import test from 'node:test';
import assert from 'node:assert/strict';
import {LatestRequest, requestUrl, debounce} from '../../public/js/map/api.js';

const response = data => ({ok: true, redirected: false, json: async () => data});

test('requestUrl separa q del término Select2 y serializa bbox sin flags de interfaz', () => {
    const url = requestUrl('/api/v1/map/search', 'https://forest.test', {q: 'parque', is_protected: '0'}, {term: 'Oití', page: 2, west: -75, south: 4, east: -74, north: 5, zoom: 12, fullWorld: false});
    assert.equal(url.searchParams.get('q'), 'parque');
    assert.equal(url.searchParams.get('term'), 'Oití');
    assert.equal(url.searchParams.get('is_protected'), '0');
    assert.equal(url.searchParams.has('fullWorld'), false);
    assert.equal(url.searchParams.get('west'), '-75');
});
test('respuesta antigua se ignora aunque un transporte no obedezca AbortController', async () => {
    const pending = [];
    const client = new LatestRequest((url, options) => new Promise(resolve => pending.push({resolve, options})));
    const old = client.get('/old');
    const current = client.get('/current');
    assert.equal(pending[0].options.signal.aborted, true);
    pending[1].resolve(response({id: 'current'}));
    assert.deepEqual(await current, {id: 'current'});
    pending[0].resolve(response({id: 'old'}));
    assert.equal(await old, null);
});
test('cancelar filtros o cambiar modo invalida también una respuesta en decodificación', async () => {
    let finishJson;
    const client = new LatestRequest(async () => ({ok: true, json: () => new Promise(resolve => { finishJson = resolve; })}));
    const pending = client.get('/map');
    await Promise.resolve();
    client.cancel();
    finishJson({points: ['stale']});
    assert.equal(await pending, null);
});
test('peticiones usan credenciales del mismo origen y solicitan JSON sin incluir tokens en URL', async () => {
    let options;
    const client = new LatestRequest(async (url, received) => { options = received; return response({}); });
    await client.get('/map');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.headers.Authorization, undefined);
});
test('errores 422 muestran mensajes validables y 403 no expone contenido recibido', async () => {
    const invalid = new LatestRequest(async () => ({ok: false, status: 422, json: async () => ({errors: {date_to: ['La fecha final debe ser posterior.']}})}));
    await assert.rejects(invalid.get('/map'), /La fecha final debe ser posterior/);
    const denied = new LatestRequest(async () => ({ok: false, status: 403, json: async () => ({message: 'internal stack'} )}));
    await assert.rejects(denied.get('/map'), /^Error: No tienes permiso/);
});
test('redirigir al login no se interpreta como una colección vacía', async () => {
    const client = new LatestRequest(async () => ({ok: true, redirected: true, json: async () => { throw new Error('No debe decodificar HTML'); }}));
    await assert.rejects(client.get('/map'), /La sesión cambió/);
});
test('debounce ejecuta solo el último encuadre y permite cancelar al cambiar filtros', () => {
    const scheduled = new Map(); let next = 0; const calls = [];
    const timers = {setTimeout(callback, delay) { assert.equal(delay, 300); scheduled.set(++next, callback); return next; }, clearTimeout(id) { scheduled.delete(id); }};
    const render = debounce(value => calls.push(value), 300, timers);
    render('old'); render('latest');
    assert.equal(scheduled.size, 1);
    [...scheduled.values()][0]();
    assert.deepEqual(calls, ['latest']);
    scheduled.clear(); render('cancelled'); render.cancel();
    assert.equal(scheduled.size, 0);
});

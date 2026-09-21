import test from 'node:test';
import assert from 'node:assert/strict';
import {cleanFilters, filtersFromEntries, filterParams, readUrlState, shareableUrl, filterCount, viewportBounds, pointLatLng, validBounds, heatPoints, sameOriginUrl, territoryNotice, LayerSlot} from '../../public/js/map/state.js';

test('los filtros compartibles conservan No, listas únicas y enteros BIGINT como texto', () => {
    assert.deepEqual(cleanFilters({is_protected: false, is_heritage: '0', q: ' Oití ', diameter_class_number: '9223372036854775807', physical_condition_ids: ['a', 'a', 'b'], token: 'secreto', unknown: 4}), {
        q: 'Oití', is_protected: '0', is_heritage: '0', diameter_class_number: '9223372036854775807', physical_condition_ids: ['a', 'b'],
    });
});
test('URL preserva las condiciones como arrays, sin incorporar credenciales ni campos desconocidos', () => {
    const filters = filtersFromEntries(new URLSearchParams('q=%3Cscript%3E&physical_condition_ids%5B%5D=a&physical_condition_ids%5B%5D=b&_token=secret&has_pending_maintenance=1'));
    assert.equal(filterParams(filters).toString(), 'q=%3Cscript%3E&physical_condition_ids%5B%5D=a&physical_condition_ids%5B%5D=b&has_pending_maintenance=1');
    assert.equal(shareableUrl('https://forest.test/map?_token=secret#fragment', filters, 'heat'), '/map?q=%3Cscript%3E&physical_condition_ids%5B%5D=a&physical_condition_ids%5B%5D=b&has_pending_maintenance=1&render_mode=heat');
});
test('restablecer elimina filtros y modo sin retener parámetros previos', () => {
    assert.equal(shareableUrl('https://forest.test/map?q=abc&render_mode=heat', {scope: 'organization'}, 'clusters'), '/map?scope=organization');
    assert.deepEqual(readUrlState('?render_mode=javascript&q=abc'), {filters: {q: 'abc'}, mode: 'clusters'});
});
test('arrays mal tipados y objetos no terminan serializados como filtros', () => {
    assert.deepEqual(cleanFilters({q: {}, physical_condition_ids: 'a', is_protected: [], current_status: null}), {});
});
test('contador de grupos aplicados no confunde ANY/ALL con una condición seleccionada', () => {
    assert.equal(filterCount({condition_mode: 'any'}), 0);
    assert.equal(filterCount({condition_mode: 'all', physical_condition_ids: ['a', 'b'], is_protected: '0'}), 2);
    assert.equal(filterCount({scope: 'national', condition_mode: 'any'}), 0);
});
test('scope explícito se conserva en consultas y URL aunque no se cuenta como filtro forestal', () => {
    const state = readUrlState('?scope=organization&render_mode=points');
    assert.deepEqual(state, {filters: {scope: 'organization'}, mode: 'points'});
    assert.equal(filterParams(state.filters).get('scope'), 'organization');
    assert.equal(shareableUrl('https://forest.test/map', state.filters, state.mode), '/map?scope=organization&render_mode=points');
});
test('bbox mantiene el orden west/south/east/north y zoom entero', () => {
    assert.deepEqual(viewportBounds({getWest: () => -75, getSouth: () => 4, getEast: () => -74, getNorth: () => 5}, 12.4), {west: -75, south: 4, east: -74, north: 5, zoom: 12, fullWorld: false});
});
test('borde mundial solicita todas las longitudes y avisa sin bbox invertido', () => {
    const bounds = viewportBounds({getWest: () => 170, getSouth: () => -91, getEast: () => 190, getNorth: () => 92}, 2);
    assert.deepEqual(bounds, {west: -180, south: -90, east: 180, north: 90, zoom: 2, fullWorld: true});
});
test('bbox degenerado y coordenadas no finitas se rechazan antes de solicitar datos', () => {
    assert.throws(() => viewportBounds({getWest: () => NaN, getSouth: () => 0, getEast: () => 1, getNorth: () => 2}, 3));
    assert.throws(() => viewportBounds({getWest: () => 1, getSouth: () => 0, getEast: () => 1, getNorth: () => 2}, 3));
});
test('GeoJSON se transforma únicamente de longitude/latitude a latitude/longitude', () => {
    assert.deepEqual(pointLatLng({geometry: {type: 'Point', coordinates: [-74.08, 4.6]}}), [4.6, -74.08]);
    assert.equal(pointLatLng({geometry: null, latitude: 4.6, longitude: -74.08}), null);
    assert.equal(pointLatLng({geometry: {type: 'Point', coordinates: ['-74', 4]}}), null);
    assert.equal(pointLatLng({geometry: {type: 'Point', coordinates: [4, 100]}}), null);
});
test('bounds de extensión permite un árbol único y rechaza límites no geográficos', () => {
    assert.equal(validBounds([[4, -74], [4, -74]]), true);
    assert.equal(validBounds([[5, -74], [4, -74]]), false);
    assert.equal(validBounds(null), false);
});
test('calor respeta pesos agregados y nunca inventa puntos para geometría ausente', () => {
    assert.deepEqual(heatPoints([{geometry: {type: 'Point', coordinates: [-74, 4]}, properties: {weight: 123}}, {geometry: null, properties: {weight: 8}}, {geometry: {type: 'Point', coordinates: [-74, 4]}, properties: {weight: -1}}]), [[4, -74, 123]]);
});
test('peticiones privadas rechazan origen ajeno, URL con credenciales y protocolos activos', () => {
    assert.equal(sameOriginUrl('/api/v1/map/trees', 'https://forest.test').href, 'https://forest.test/api/v1/map/trees');
    for (const value of ['https://attacker.test/map', '//attacker.test', 'javascript:alert(1)', 'https://user:pass@forest.test/map']) assert.throws(() => sameOriginUrl(value, 'https://forest.test'));
});
test('cambiar modo elimina la capa anterior antes de crear otra y limpiar es idempotente', () => {
    const calls = [];
    const slot = new LayerSlot(layer => calls.push(`remove:${layer}`));
    slot.replace(() => { calls.push('create:points'); return 'points'; });
    slot.replace(() => { calls.push('create:heat'); return 'heat'; });
    slot.clear(); slot.clear();
    assert.deepEqual(calls, ['create:points', 'remove:points', 'create:heat', 'remove:heat']);
    assert.equal(slot.layer, null);
});
test('el aviso de límites diferencia omisiones, truncamiento y una colección completa', () => {
    assert.equal(territoryNotice(), '');
    assert.equal(territoryNotice({omitted_complex: 0, omitted_invalid: 0, truncated: false}), '');
    assert.match(territoryNotice({omitted_complex: 1}), /complejidad o validez/);
    assert.match(territoryNotice({omitted_invalid: 2}), /El encuadre conserva los datos disponibles/);
    assert.match(territoryNotice({truncated: true}), /límite de contornos/);
    assert.match(territoryNotice({omitted_complex: 1, truncated: true}), /datos disponibles\. Se alcanzó/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {publicTreePopup} from '../../public/js/public/popup.js';
import {MapLayers} from '../../public/js/map/layers.js';
import {pointLatLng} from '../../public/js/map/state.js';

class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; this.style = {}; }
    append(...children) { this.children.push(...children); }
    setAttribute(name, value) { this[name] = value; }
    set innerHTML(value) { throw new Error(`No se permite HTML: ${value}`); }
}
const document = {createElement: tag => new Element(tag), createTextNode: text => ({textContent: text})};
const context = {document, csrf: 'must-not-render', origin: 'https://forest.test'};
const descendants = element => [element, ...(element.children ?? []).flatMap(descendants)];

test('popup público escapa todo texto y crea sólo un enlace GET a la ficha publicada', () => {
    const attack = '<img src=x onerror=alert(1)>';
    const popup = publicTreePopup({code: attack, common_name: attack, scientific_name: attack, status_label: attack, placement: attack, latest_inspection: {date: '2026-09-21'}, open_url: '/portal/organizations/AMVA/trees/ARB-000001'}, context);
    const nodes = descendants(popup);
    assert.equal(nodes.filter(node => node.textContent === attack).length, 5);
    assert.equal(nodes.some(node => ['form', 'input', 'img', 'script'].includes(node.tag)), false);
    assert.equal(nodes.find(node => node.tag === 'a').href, 'https://forest.test/portal/organizations/AMVA/trees/ARB-000001');
    assert.equal(nodes.some(node => node.textContent.includes('Última inspección publicada')), true);
    assert.equal(nodes.some(node => node.textContent.includes('must-not-render')), false);
});

test('popup público nunca ofrece una acción privada o externa aunque el payload la contenga', () => {
    for (const open_url of ['/map/trees/uuid/open', '/logout', 'https://external.test/portal/organizations/AMVA/trees/ARB-1', '/portal/organizations/AMVA/trees/ARB-1?token=x']) {
        assert.equal(descendants(publicTreePopup({open_url}, context)).some(node => ['a', 'form'].includes(node.tag)), false);
    }
});

test('resultado público sin ubicación conserva acceso a ficha y nunca produce coordenadas 0,0', () => {
    const result = {geometry: null, code: 'ARB-1', open_url: '/portal/organizations/AMVA/trees/ARB-1'};
    assert.equal(pointLatLng(result), null);
    assert.equal(descendants(publicTreePopup(result, context)).filter(node => node.tag === 'a').length, 1);
});

test('capa común utiliza el renderer público inyectado sin crear formularios de administración', () => {
    let popup;
    const marker = {bindPopup(value) { popup = value(); return this; }, on() { return this; }};
    const L = {divIcon: options => options, marker: () => marker};
    const layers = new MapLayers({map: {}, L, document, origin: context.origin, legend: [], popupRenderer: publicTreePopup});
    layers.treeMarker({type: 'Feature', geometry: {type: 'Point', coordinates: [-75.5, 6.2]}, properties: {code: 'ARB-1', open_url: '/portal/organizations/AMVA/trees/ARB-1'}});
    assert.equal(descendants(popup).filter(node => node.tag === 'a').length, 1);
    assert.equal(descendants(popup).some(node => node.tag === 'form'), false);
});

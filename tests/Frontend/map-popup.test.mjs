import test from 'node:test';
import assert from 'node:assert/strict';
import {treePopup} from '../../public/js/map/popup.js';

class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; }
    append(...children) { this.children.push(...children); }
    set innerHTML(value) { throw new Error(`No se permite HTML: ${value}`); }
}
const document = {createElement: tag => new Element(tag), createTextNode: text => ({textContent: text})};
const id = '8a274c43-e0e3-4894-b7d1-626cbab41961';
const context = {document, csrf: 'csrf-fixture', origin: 'https://forest.test'};
const descendants = element => [element, ...(element.children ?? []).flatMap(descendants)];

test('popup imprime texto no confiable sin interpretar HTML', () => {
    const attack = '<img src=x onerror=alert(1)>';
    const popup = treePopup({code: attack, common_name: attack, scientific_name: attack, status_label: attack, placement: attack}, context);
    assert.equal(descendants(popup).filter(element => element.textContent === attack).length, 5);
    assert.equal(descendants(popup).some(element => element.tag === 'img' || element.tag === 'script'), false);
});
test('abrir ficha usa POST con CSRF y no incluye cookies ni secretos en la dirección', () => {
    const popup = treePopup({code: 'ARB-000001', open_url: `/map/trees/${id}/open`}, context);
    const form = descendants(popup).find(element => element.tag === 'form');
    assert.equal(form.method, 'POST');
    assert.equal(form.action, `https://forest.test/map/trees/${id}/open`);
    assert.equal(form.children[0].name, '_token');
    assert.equal(form.children[0].value, 'csrf-fixture');
    assert.equal(form.children[1].textContent, 'Ver ficha del árbol');
});
test('popup no crea formularios para URLs externas o acciones diferentes', () => {
    for (const open_url of [`https://attacker.test/map/trees/${id}/open`, '/logout', `/map/trees/${id}/open?token=x`, `/map/trees/${id}/open?scope=invalid`, `/map/trees/${id}/open?scope=organization&scope=national`, `/map/trees/${id}/open?scope=national&redirect=external`, 'javascript:alert(1)']) {
        assert.equal(descendants(treePopup({open_url}, context)).some(element => element.tag === 'form'), false);
    }
});
test('popup conserva exclusivamente el alcance explícito emitido por el servidor en el POST', () => {
    for (const scope of ['organization', 'department', 'national']) {
        const popup = treePopup({code: 'ARB-000001', open_url: `/map/trees/${id}/open?scope=${scope}`}, context);
        const form = descendants(popup).find(element => element.tag === 'form');
        assert.equal(form.method, 'POST');
        assert.equal(form.action, `https://forest.test/map/trees/${id}/open?scope=${scope}`);
        assert.equal(form.children[0].name, '_token');
    }
});

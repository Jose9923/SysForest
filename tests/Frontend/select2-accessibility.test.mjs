import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/js/select2-accessibility.js', import.meta.url), 'utf8');

function node(attributes = {}) {
    const values = new Map(Object.entries(attributes));
    const listeners = new Map();
    return {
        id: attributes.id || '', listeners, focused: false,
        getAttribute: name => values.get(name) ?? null,
        setAttribute: (name, value) => values.set(name, value),
        removeAttribute: name => values.delete(name),
        addEventListener: (event, callback) => listeners.set(event, callback),
        removeEventListener: (event, callback) => { if (listeners.get(event) === callback) listeners.delete(event); },
        focus() { this.focused = true; },
    };
}

function fixture({multiple = false} = {}) {
    const label = node();
    const select = Object.assign(node({'aria-describedby': 'field-help', 'aria-invalid': 'true'}), {
        id: 'field', labels: [label], multiple, required: true, ownerDocument: {getElementById: () => null},
    });
    const selection = node({'aria-labelledby': 'selected-value'});
    const search = node();
    const dropdownSearch = node();
    const events = new Map();
    const instance = {$selection: [selection], selection: {$search: multiple ? [search] : []}, dropdown: {$search: [dropdownSearch]}, on: (name, callback) => events.set(name, callback)};
    const window = {};
    vm.runInNewContext(source, {window, WeakMap, Set, Array});
    return {select, label, selection, search, dropdownSearch, instance, events, associate: window.SysforestSelect2Accessibility.associate};
}

test('el selector simple anuncia etiqueta y selección, conservando ayudas y errores', () => {
    const context = fixture();
    context.associate(context.select, context.instance);
    assert.equal(context.selection.getAttribute('aria-labelledby'), 'field-label-0 selected-value');
    assert.equal(context.selection.getAttribute('aria-describedby'), 'field-help');
    assert.equal(context.selection.getAttribute('aria-invalid'), 'true');
    assert.equal(context.selection.getAttribute('aria-required'), 'true');
    assert.equal(context.dropdownSearch.getAttribute('aria-labelledby'), 'field-label-0');
});

test('la búsqueda múltiple conserva el nombre del campo sin incorporar las etiquetas seleccionadas', () => {
    const context = fixture({multiple: true});
    context.label.id = 'conditions-label';
    context.associate(context.select, context.instance);
    assert.equal(context.selection.getAttribute('aria-labelledby'), 'conditions-label');
    assert.equal(context.search.getAttribute('aria-labelledby'), 'conditions-label');
    let prevented = false;
    context.label.listeners.get('click')({target: {}, preventDefault: () => { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(context.search.focused, true);
});

test('al abrir el desplegable se nombra el buscador que Select2 acaba de crear', () => {
    const context = fixture();
    context.instance.dropdown.$search = [];
    context.associate(context.select, context.instance);
    const nextSearch = node();
    context.instance.dropdown.$search = [nextSearch];
    context.events.get('open')();
    assert.equal(nextSearch.getAttribute('aria-labelledby'), 'field-label-0');
    context.associate(context.select, context.instance);
    assert.equal(context.selection.getAttribute('aria-labelledby'), 'field-label-0 selected-value');
});

test('se respetan etiquetas explícitas, ids existentes y controles deshabilitados', () => {
    const context = fixture();
    context.select.setAttribute('aria-labelledby', 'section-label');
    context.select.ownerDocument.getElementById = id => id === 'field-label-0' ? {} : null;
    context.associate(context.select, context.instance);
    assert.equal(context.label.id, 'field-label-0-1');
    assert.equal(context.selection.getAttribute('aria-labelledby'), 'section-label field-label-0-1 selected-value');
    context.select.disabled = true;
    context.label.listeners.get('click')({target: {}, preventDefault: () => assert.fail('No debe activar un control deshabilitado')});
    assert.equal(context.selection.focused, false);
});

test('aria-label nativo nombra el campo antes que el valor seleccionado cuando no existe label', () => {
    const context = fixture();
    context.select.labels = [];
    context.select.setAttribute('aria-label', 'Organización');
    context.associate(context.select, context.instance);
    assert.equal(context.selection.getAttribute('aria-label'), 'Organización');
    assert.equal(context.selection.getAttribute('aria-labelledby'), null);
    assert.equal(context.dropdownSearch.getAttribute('aria-label'), 'Organización');
    context.events.get('open')();
    assert.equal(context.dropdownSearch.getAttribute('aria-labelledby'), null);
});

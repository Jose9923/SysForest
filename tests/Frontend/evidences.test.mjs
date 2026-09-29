import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {File} from 'node:buffer';

const application = readFileSync(new URL('../../public/js/evidences.js', import.meta.url), 'utf8');

// Doble de DOM deliberadamente acotado: ejecuta el JS real, sin navegador, imágenes reales ni red.
class Element {
    constructor() {
        this.listeners = new Map();
        this.attributes = new Map();
        this.dataset = {};
        this.children = [];
        this.hidden = false;
        this.disabled = false;
        this.textContent = '';
        this.value = '';
        this.validity = {valid: true};
        const classes = new Set();
        this.classList = {
            toggle: (name, on) => on ? classes.add(name) : classes.delete(name),
            contains: (name) => classes.has(name)
        };
    }
    set innerHTML(_) { throw new Error('No se permite insertar nombres de archivo mediante HTML.'); }
    addEventListener(name, handler) {
        this.listeners.set(name, [...(this.listeners.get(name) || []), handler]);
    }
    emit(name, data = {}) {
        for (const handler of this.listeners.get(name) || []) handler(data);
    }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    appendChild(child) { this.children.push(child); child.parent = this; }
    replaceChildren(...children) { this.children = children; }
    remove() {
        this.removed = true;
        if (this.parent) this.parent.children = this.parent.children.filter((child) => child !== this);
    }
    focus() { this.focused = true; }
    click() { this.clicked = true; this.emit('click'); }
}

class Card extends Element {
    constructor() {
        super();
        this.parts = Object.fromEntries(['preview', 'preview-error', 'filename', 'filesize', 'remove', 'file-errors', 'file-status']
            .map((name) => [name, new Element()]));
        this.fields = Object.fromEntries(['evidence_type_id', 'caption', 'captured_at', 'notes'].map((name) => {
            const field = new Element();
            field.dataset.uploadField = name;
            return [name, field];
        }));
        this.labels = Object.fromEntries(Object.keys(this.fields).map((name) => {
            const label = new Element();
            label.textContent = name;
            return [name, label];
        }));
    }
    cloneNode() { return new Card(); }
    querySelector(selector) {
        let match = /^\[data-upload-field="([^"]+)"\]$/.exec(selector);
        if (match) return this.fields[match[1]];
        match = /^\[data-upload-label="([^"]+)"\]$/.exec(selector);
        if (match) return this.labels[match[1]];
        return this.parts[selector.slice('[data-upload-'.length, -1)];
    }
    querySelectorAll(selector) {
        assert.equal(selector, '[data-upload-field]');
        return Object.values(this.fields);
    }
}

function fixture(sessionContext = null) {
    const controls = Object.fromEntries(['files', 'camera-input', 'camera-button', 'queue', 'queue-section', 'count',
        'template', 'errors', 'submit', 'progress-panel', 'progress', 'progress-text', 'fallback'].map((name) => [name, new Element()]));
    controls.template.content = {firstElementChild: new Card()};
    const token = new Element();
    token.value = 'csrf-sintetico-sin-credencial';
    const context = sessionContext === null ? null : new Element();
    if (context) context.value = sessionContext;
    const form = new Element();
    form.action = 'https://sysforest.test/trees/test/evidences';
    form.dataset = {maxFiles: '3', maxSizeMb: '1', allowedMimes: 'image/jpeg,image/png,image/webp'};
    const selectorNames = {
        '[data-evidence-files]': 'files', '[data-camera-input]': 'camera-input',
        '[data-camera-button]': 'camera-button', '[data-upload-queue]': 'queue',
        '[data-upload-queue-section]': 'queue-section', '[data-upload-count]': 'count',
        '[data-upload-template]': 'template', '[data-upload-errors]': 'errors',
        '[data-upload-submit]': 'submit', '[data-upload-progress-panel]': 'progress-panel',
        '[data-upload-progress]': 'progress', '[data-upload-progress-text]': 'progress-text',
        '[data-upload-fallback]': 'fallback'
    };
    form.querySelector = (selector) => selector === 'input[name="_token"]' ? token
        : selector === 'input[name="_session_context"]' ? context : controls[selectorNames[selector]];
    form.querySelectorAll = () => [
        controls.files, controls['camera-input'], controls['camera-button'], controls.submit,
        ...controls.queue.children.flatMap((card) => [...Object.values(card.fields), card.parts.remove])
    ];
    const requests = [];
    class Request extends Element {
        constructor() { super(); this.upload = new Element(); this.headers = {}; requests.push(this); }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader(key, value) { this.headers[key] = value; }
        send(payload) { this.payload = payload; }
        respond(status, result) {
            this.status = status;
            this.responseText = JSON.stringify(result);
            this.emit('load');
        }
    }
    const revoked = [];
    let previewId = 0;
    class ObjectURL extends URL {
        static createObjectURL() { return 'blob:synthetic-'+(++previewId); }
        static revokeObjectURL(value) { revoked.push(value); }
    }
    const window = new Element();
    window.URL = ObjectURL;
    window.FormData = FormData;
    window.XMLHttpRequest = Request;
    const redirects = [];
    window.location = {origin: 'https://sysforest.test', href: 'https://sysforest.test/trees/test/evidences/create',
        assign: (destination) => redirects.push(destination)};
    const document = {
        querySelectorAll: (selector) => selector === '[data-evidence-upload]' ? [form] : [],
        createElement: () => new Element()
    };
    vm.runInNewContext(application, {window, document, URL: ObjectURL, FormData, XMLHttpRequest: Request, console});
    const pick = (...files) => { controls.files.files = files; controls.files.emit('change'); };
    const send = () => form.emit('submit', {preventDefault() {}});
    return {controls, form, requests, pick, send, revoked, redirects, window};
}

const image = (name = 'foto.png', size = 12) => new File([new Uint8Array(size)], name, {type: 'image/png'});
const classify = (card, type) => { card.fields.evidence_type_id.value = type; };

test('selección múltiple conserva nombres como texto, tipos independientes y eliminación revoca preview', () => {
    const f = fixture();
    const unsafe = '<img src=x onerror=alert(1)>.png';
    f.pick(image(unsafe), image('segunda.png'));
    assert.equal(f.controls.files.multiple, true);
    assert.equal(f.controls.queue.children.length, 2);
    assert.equal(f.controls.queue.children[0].parts.filename.textContent, unsafe);
    assert.equal(f.controls.queue.children[0].fields.evidence_type_id.value, '');
    assert.equal(f.controls.queue.children[1].fields.evidence_type_id.value, '');
    assert.match(f.controls.queue.children[0].parts.preview.src, /^blob:/);
    f.controls.queue.children[0].parts.remove.click();
    assert.equal(f.controls.queue.children.length, 1);
    assert.equal(f.revoked.length, 1);
    assert.equal(f.controls.count.textContent, '(1)');
});

test('exige tipo en cada archivo y envía items densos con su File, tipo, caption y fecha UTC literal', () => {
    const f = fixture();
    f.pick(image('uno.png'), image('dos.png'));
    const [first, second] = f.controls.queue.children;
    classify(first, 'tipo-individuo');
    f.send();
    assert.equal(f.requests.length, 0);
    assert.match(second.parts['file-errors'].children[0].textContent, /tipo de evidencia es obligatorio/);
    classify(second, 'tipo-fauna');
    first.fields.caption.value = 'Individuo de referencia';
    second.fields.caption.value = '0';
    second.fields.captured_at.value = '2026-09-12T14:35';
    f.send();
    const request = f.requests[0];
    assert.equal(request.method, 'POST');
    assert.equal(request.payload.get('items[0][file]').name, 'uno.png');
    assert.equal(request.payload.get('items[1][file]').name, 'dos.png');
    assert.equal(request.payload.get('items[0][evidence_type_id]'), 'tipo-individuo');
    assert.equal(request.payload.get('items[1][evidence_type_id]'), 'tipo-fauna');
    assert.equal(request.payload.get('items[1][caption]'), '0');
    assert.equal(request.payload.get('items[1][captured_at]'), '2026-09-12T14:35');
    assert.equal(request.headers.Accept, 'application/json');
    assert.equal(f.form.attributes.get('aria-busy'), 'true');
    assert.equal(first.parts.remove.disabled, true);
});

test('progreso representa transporte; 100% espera validación y422 conserva selección y errores por archivo', () => {
    const f = fixture();
    f.pick(image('uno.png'), image('dos.png'));
    f.controls.queue.children.forEach((card) => classify(card, 'tipo-fauna'));
    f.controls.queue.children[1].fields.caption.value = 'Descripción conservada';
    f.send();
    const request = f.requests[0];
    request.upload.emit('progress', {lengthComputable: true, loaded: 5, total: 10});
    assert.equal(f.controls.progress.value, 50);
    request.upload.emit('progress', {lengthComputable: true, loaded: 10, total: 10});
    assert.match(f.controls['progress-text'].textContent, /Validando y guardando/);
    request.respond(422, {errors: {'items.1.evidence_type_id': ['Selecciona un tipo activo.']}});
    assert.equal(f.controls.queue.children.length, 2);
    assert.equal(f.controls.queue.children[1].fields.caption.value, 'Descripción conservada');
    assert.equal(f.controls.queue.children[1].parts['file-errors'].children[0].textContent, 'Selecciona un tipo activo.');
    assert.equal(f.controls.queue.children[0].parts['file-errors'].children.length, 0);
    assert.match(f.controls.queue.children[0].parts['file-status'].textContent, /no se guardó el lote/);
    assert.equal(f.form.attributes.get('aria-busy'), 'false');
    assert.equal(f.controls.submit.disabled, false);
    assert.equal(f.redirects.length, 0);
});

test('límite por archivo y cantidad conserva controles y no inicia transporte inválido', () => {
    const f = fixture();
    f.pick(image('grande.png', 1048577), image('dos.png'), image('tres.png'), image('exceso.png'));
    assert.equal(f.controls.queue.children.length, 3);
    assert.match(f.controls.errors.textContent, /No se añadieron 1/);
    f.controls.queue.children.forEach((card) => classify(card, 'tipo'));
    f.send();
    assert.equal(f.requests.length, 0);
    assert.match(f.controls.queue.children[0].parts['file-errors'].children[0].textContent, /tamaño máximo permitido/);
});

test('timeout no reintenta automáticamente; conserva archivos y pide comprobar resultado', () => {
    const f = fixture();
    f.pick(image());
    classify(f.controls.queue.children[0], 'tipo');
    f.send();
    f.requests[0].emit('timeout');
    assert.equal(f.requests.length, 1);
    assert.equal(f.controls.queue.children.length, 1);
    assert.match(f.controls.errors.textContent, /Consulta el registro antes de reenviar/);
    assert.equal(f.controls.submit.disabled, false);
});

test('201 confirma y navega solo a origen propio; se liberan previews', () => {
    const f = fixture();
    f.pick(image());
    classify(f.controls.queue.children[0], 'tipo');
    f.send();
    f.requests[0].respond(201, {message: 'Guardadas', redirect: '/trees/test'});
    assert.deepEqual(f.redirects, ['https://sysforest.test/trees/test']);
    assert.equal(f.revoked.length, 1);
    assert.equal(f.controls.queue.children[0].parts['file-status'].textContent, 'Guardada');
});

test('respuesta201 con redirect externo no navega ni vuelve a enviar imágenes ya guardadas', () => {
    const f = fixture();
    f.pick(image());
    classify(f.controls.queue.children[0], 'tipo');
    f.send();
    f.requests[0].respond(201, {redirect: 'https://otro.example/upload'});
    assert.equal(f.redirects.length, 0);
    assert.equal(f.controls.submit.disabled, true);
    assert.match(f.controls.errors.textContent, /fueron guardadas/);
    assert.equal(f.revoked.length, 1);
});

test('XHR conserva el contexto verificado del formulario y un conflicto no elimina archivos ni reenvía', () => {
    const context = 'a'.repeat(64);
    const f = fixture(context);
    f.pick(image('sesion-renovada.png'));
    const card = f.controls.queue.children[0];
    classify(card, 'tipo-individuo');
    card.fields.caption.value = 'Descripción conservada al renovar';
    f.send();
    const request = f.requests[0];
    assert.equal(request.headers['X-Session-Context'], context);
    assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf-sintetico-sin-credencial');
    assert.equal(request.payload.get('items[0][file]').name, 'sesion-renovada.png');
    assert.equal(request.payload.get('items[0][caption]'), 'Descripción conservada al renovar');
    request.respond(409, {state: 'context_changed', message: 'La organización cambió.'});
    assert.equal(f.requests.length, 1);
    assert.equal(f.controls.queue.children.length, 1);
    assert.equal(card.fields.caption.value, 'Descripción conservada al renovar');
    assert.equal(f.revoked.length, 0);
    assert.equal(f.redirects.length, 0);
    assert.equal(f.controls.submit.disabled, false);
});

test('una página sin control de continuidad no inventa un encabezado de contexto', () => {
    const f = fixture();
    f.pick(image());
    classify(f.controls.queue.children[0], 'tipo');
    f.send();
    assert.equal(f.requests[0].headers['X-Session-Context'], undefined);
});

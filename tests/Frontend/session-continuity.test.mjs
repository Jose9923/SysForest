import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const application = readFileSync(new URL('../../public/js/session-continuity.js', import.meta.url), 'utf8');

class Element {
    constructor() { this.attributes = new Map(); this.listeners = new Map(); this.hidden = true; this.isConnected = true; }
    setAttribute(key, value) { this.attributes.set(key, value); }
    getAttribute(key) { return this.attributes.has(key) ? this.attributes.get(key) : null; }
    removeAttribute(key) { this.attributes.delete(key); }
    hasAttribute(key) { return this.attributes.has(key); }
    addEventListener(name, callback) { this.listeners.set(name, [...(this.listeners.get(name) || []), callback]); }
    focus() { this.focused = true; }
    scrollIntoView() { this.scrolled = true; }
    emit(name, event) { for (const callback of this.listeners.get(name) || []) callback(event); }
    querySelector() { return null; }
}

function lockManager() {
    let tail = Promise.resolve();
    return {request(_name, _options, operation) {
        const next = tail.then(operation);
        tail = next.catch(() => {});
        return next;
    }};
}

function fixture({locks = lockManager(), transport, context = 'same-family-and-tenant'} = {}) {
    const config = {status_url: '/auth/session/status', ensure_url: '/auth/session/ensure', context,
        csrf_token: 'csrf-initial', server_time: 100, access_expires_at: 160, session_expires_at: 1000, renew_before_seconds: 20};
    const document = new Element();
    document.hidden = false;
    const panel = new Element();
    const message = new Element();
    const login = new Element();
    const retry = new Element();
    panel.querySelector = selector => ({'[data-session-message]': message, '[data-session-login]': login, '[data-session-retry]': retry})[selector];
    document.getElementById = () => ({textContent: JSON.stringify(config)});
    document.querySelector = () => panel;
    const tokens = [];
    document.querySelectorAll = () => tokens;
    document.createElement = () => new Element();
    const timers = new Map();
    let timerId = 0;
    const requests = [];
    const ready = {...config, state: 'ready', csrf_token: 'csrf-current'};
    const response = (data, status = 200) => ({ok: status >= 200 && status < 300, status, json: async () => data});
    const window = new Element();
    window.document = document;
    window.navigator = {locks};
    window.setTimeout = (callback, delay) => { timers.set(++timerId, {callback, delay}); return timerId; };
    window.clearTimeout = id => timers.delete(id);
    const navigations = [];
    window.location = {href: 'https://sysforest.test/trees/create', origin: 'https://sysforest.test', pathname: '/trees/create', search: '', assign: url => navigations.push(url)};
    window.fetch = async (input, options) => {
        requests.push({input, options});
        return transport ? transport(input, options, {ready, response}) : response(ready);
    };
    vm.runInNewContext(application, {window, URL, Headers, AbortController, DOMException, Date});
    return {window, document, panel, message, login, retry, requests, config, ready, response, tokens, timers, navigations};
}

const settle = () => new Promise(resolve => setImmediate(resolve));

test('renueva antes de enviar una escritura y nunca reintenta la escritura fallida', async () => {
    const f = fixture({transport: async (url, options, {ready, response}) => {
        if (url === '/auth/session/status') {
            assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
            return response({...ready, state: 'renewal_required'});
        }
        if (url === '/auth/session/ensure') {
            assert.equal(options.method, 'POST');
            assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-current');
            assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
            assert.equal(JSON.parse(options.body).expected_context, ready.context);
            return response(ready);
        }
        return response({message: 'Fallo de escritura'}, 503);
    }});
    const result = await f.window.fetch('/trees', {method: 'POST', body: 'datos-intactos'});
    assert.equal(result.status, 503);
    assert.deepEqual(f.requests.map(r => r.input), ['/auth/session/status', '/auth/session/ensure', '/trees']);
    assert.equal(f.requests[2].options.headers.get('X-Session-Context'), f.config.context);
    assert.equal(f.requests[2].options.body, 'datos-intactos');
});

test('dos pestañas serializan la rotación y consultan de nuevo el estado dentro del bloqueo', async () => {
    const locks = lockManager();
    let rotations = 0;
    const transport = async (url, _options, {ready, response}) => {
        if (url === '/auth/session/status') return response({...ready, state: rotations ? 'ready' : 'renewal_required'});
        if (url === '/auth/session/ensure') { await settle(); rotations++; }
        return response(ready);
    };
    const first = fixture({locks, transport});
    const second = fixture({locks, transport});
    await Promise.all([first.window.fetch('/map'), second.window.fetch('/reports')]);
    assert.equal(rotations, 1);
    assert.equal(first.requests.filter(r => r.input === '/map').length, 1);
    assert.equal(second.requests.filter(r => r.input === '/reports').length, 1);
});

test('peticiones simultáneas en una pestaña comparten la comprobación', async () => {
    const f = fixture();
    await Promise.all([f.window.fetch('/map'), f.window.fetch('/options')]);
    assert.equal(f.requests.filter(r => r.input === '/auth/session/status').length, 1);
});

test('cambio de institución bloquea la escritura y mantiene la página', async () => {
    const f = fixture({transport: async (_url, _options, {ready, response}) => response({...ready, context: 'different-tenant'})});
    await assert.rejects(f.window.fetch('/trees', {method: 'POST'}), /institución cambió/);
    assert.equal(f.requests.length, 1);
    assert.equal(f.panel.hidden, false);
    assert.equal(f.navigations.length, 0);
});

test('revocación conserva el formulario y ofrece acceso separado sin enviar datos', async () => {
    const f = fixture({transport: async (_url, _options, {response}) => response({state: 'ended'}, 401)});
    await assert.rejects(f.window.fetch('/trees', {method: 'POST'}), /sesión terminó/);
    assert.equal(f.login.hidden, false);
    assert.equal(f.requests.length, 1);
    await assert.rejects(f.window.fetch('/trees', {method: 'POST'}), /sesión terminó/);
    assert.equal(f.login.hidden, false);
});

test('fallo de red permite reintentar explícitamente sin enviar la operación pendiente', async () => {
    let offline = true;
    const f = fixture({transport: async (_url, _options, {ready, response}) => {
        if (offline) throw new TypeError('offline');
        return response(ready);
    }});
    await assert.rejects(f.window.fetch('/trees', {method: 'POST'}), /conexión/);
    assert.equal(f.requests.length, 1);
    offline = false;
    await f.window.fetch('/trees', {method: 'POST'});
    assert.equal(f.requests.filter(r => r.input === '/trees').length, 1);
    assert.equal(f.panel.hidden, true);
});

test('cancelar un lookup durante la renovación impide enviarlo después', async () => {
    const controller = new AbortController();
    const f = fixture({transport: async (_url, _options, {ready, response}) => { controller.abort(); return response(ready); }});
    await assert.rejects(f.window.fetch('/map', {signal: controller.signal}), {name: 'AbortError'});
    assert.equal(f.requests.length, 1);
});

test('no interviene en recursos externos ni credenciales omitidas', async () => {
    const f = fixture();
    await f.window.fetch('https://tiles.example.test/1/2/3.png');
    await f.window.fetch('/public-resource', {credentials: 'omit'});
    assert.equal(f.requests.length, 2);
});

test('sin Web Locks no consume un refresh que podría estar usando otra pestaña', async () => {
    const f = fixture({locks: null, transport: async (_url, _options, {ready, response}) => response({...ready, state: 'renewal_required'})});
    await assert.rejects(f.window.fetch('/trees', {method: 'POST'}), /navegador/);
    assert.equal(f.requests.length, 1);
});

test('espera bloqueada por otra pestaña tiene plazo y conserva la operación sin enviarla', async () => {
    const locks = {request(_name, options) {
        return new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('Cancelado', 'AbortError'))));
    }};
    const f = fixture({locks});
    const result = f.window.fetch('/trees', {method: 'POST'});
    [...f.timers.values()].find(timer => timer.delay === 20000).callback();
    await assert.rejects(result, /conexión/);
    assert.equal(f.requests.length, 0);
    assert.equal(f.panel.hidden, false);
});

test('el último access de la sesión programa el vencimiento absoluto sin consultas cada segundo', async () => {
    const f = fixture({transport: async (_url, _options, {ready, response}) => response({...ready, access_expires_at: 110, session_expires_at: 110})});
    await f.window.fetch('/map');
    assert.deepEqual([...f.timers.values()].map(timer => timer.delay), [10000]);
});

test('submit conserva campos y adjuntos, actualiza CSRF y dispara el handler original una sola vez', async () => {
    const f = fixture();
    const form = new Element();
    form.action = 'https://sysforest.test/trees';
    form.method = 'post';
    const file = {name: 'evidencia.jpg'};
    form.fields = {_token: {value: 'old-csrf'}, notes: {value: 'Texto largo'}, files: {files: [file]}};
    form.querySelector = selector => form.fields[selector.match(/name="([^"]+)"/)[1]];
    form.appendChild = input => { form.fields[input.name] = input; };
    f.tokens.push(form.fields._token);
    const submitter = new Element();
    let submissions = 0;
    const submit = () => {
        const event = {target: form, submitter, prevented: false, preventDefault() {this.prevented = true;}, stopImmediatePropagation() {}};
        f.document.emit('submit', event);
        if (!event.prevented) submissions++;
    };
    form.requestSubmit = button => { assert.equal(button, submitter); submit(); };
    submit(); submit();
    await settle();
    assert.equal(submissions, 1);
    assert.equal(form.fields.notes.value, 'Texto largo');
    assert.equal(form.fields.files.files[0], file);
    assert.equal(form.fields._token.value, 'csrf-current');
    assert.equal(form.fields._session_context.value, f.config.context);
    assert.equal(form.hasAttribute('aria-busy'), false);
});

test('cerrar sesión queda disponible incluso si el acceso venció', () => {
    const f = fixture();
    const form = new Element();
    form.action = 'https://sysforest.test/logout';
    form.setAttribute('data-session-exempt', '');
    f.document.emit('submit', {target: form, preventDefault() {assert.fail('Logout no debe esperar renovación');}});
    assert.equal(f.requests.length, 0);
});

test('un envío bloqueado muestra el aviso aunque el formulario sea largo y conserva sus campos', async () => {
    const f = fixture({transport: async (_url, _options, {response}) => response({state: 'ended'}, 401)});
    const form = new Element();
    form.action = 'https://sysforest.test/trees';
    form.notes = 'Borrador conservado';
    form.requestSubmit = () => assert.fail('La escritura no debe enviarse');
    f.document.emit('submit', {target: form, preventDefault() {}, stopImmediatePropagation() {}});
    await settle();
    assert.equal(form.notes, 'Borrador conservado');
    assert.equal(f.panel.focused, true);
    assert.equal(f.panel.scrolled, true);
    assert.equal(f.login.hidden, false);
});

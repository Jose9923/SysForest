/* Coordina el transporte autenticado; el servidor decide vigencia, identidad y permisos. */
(function (root) {
    'use strict';
    const document = root.document;
    const element = document.getElementById('session-continuity-config');
    if (!element || !root.fetch) return;
    const config = JSON.parse(element.textContent);
    const rawFetch = root.fetch.bind(root);
    const panel = document.querySelector('[data-session-notice]');
    const message = panel?.querySelector('[data-session-message]');
    const login = panel?.querySelector('[data-session-login]');
    let pending = null;
    let timer = null;
    let blockedReason = null;
    let state = config;
    let dueAt = 0;
    const allowedSubmissions = new WeakSet();
    const pendingForms = new WeakSet();

    function fail(kind) {
        const error = new Error(kind === 'context_changed'
            ? 'La cuenta o la institución cambió en otra pestaña. Conservamos este formulario: copia lo que necesites y abre de nuevo la página en la institución correcta.'
            : kind === 'ended' || kind === 'context_missing'
                ? 'La sesión terminó o fue revocada. Conservamos lo escrito. Inicia sesión en otra pestaña y abre de nuevo este formulario antes de guardar.'
                : kind === 'unsupported'
                    ? 'Este navegador no permite renovar la sesión de forma coordinada. Conservamos lo escrito; utiliza una versión actual de Chrome, Edge, Firefox o Safari.'
                    : 'No pudimos comprobar la sesión. Lo escrito sigue aquí. Comprueba la conexión y vuelve a intentar.');
        error.sessionFailure = kind;
        return error;
    }

    function show(error, focus = false) {
        if (error.name === 'AbortError') return;
        if (['ended', 'context_changed', 'context_missing'].includes(error.sessionFailure)) blockedReason = error.sessionFailure;
        if (panel && message) {
            message.textContent = error.message;
            panel.hidden = false;
            if (login) login.hidden = !['ended', 'context_missing'].includes(error.sessionFailure);
            if (focus) {
                panel.focus({preventScroll: true});
                panel.scrollIntoView({block: 'center'});
            }
        }
    }

    function schedule(next) {
        state = {...state, ...next};
        root.clearTimeout(timer);
        const margin = state.access_expires_at >= state.session_expires_at ? 0 : config.renew_before_seconds;
        const remaining = ((state.access_expires_at || state.server_time) - state.server_time - margin) * 1000;
        dueAt = Date.now() + Math.max(0, remaining);
        // No se prolonga el vencimiento absoluto y una pestaña oculta no consume rotaciones.
        if (!document.hidden && !blockedReason) {
            timer = root.setTimeout(() => { ensure().catch(show); }, Math.max(1000, remaining));
        }
    }

    async function sessionRequest(url, options = {}) {
        const controller = new AbortController();
        const timeout = root.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await rawFetch(url, {
                credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                ...options, signal: controller.signal,
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...options.headers},
            });
            const data = await response.json();
            if (!response.ok) throw fail(data.state || (response.status === 401 ? 'ended' : 'network'));
            if (data.context !== config.context) throw fail('context_changed');
            return data;
        } catch (error) {
            throw error.sessionFailure ? error : fail('network');
        } finally {
            root.clearTimeout(timeout);
        }
    }

    async function check() {
        if (blockedReason) throw fail(blockedReason);
        let next = await sessionRequest(config.status_url);
        if (next.state === 'renewal_required') {
            // Web Locks serializa pestañas del mismo origen. Nunca se reutiliza un refresh consumido.
            if (!root.navigator.locks) throw fail('unsupported');
            next = await sessionRequest(config.ensure_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': next.csrf_token},
                body: JSON.stringify({expected_context: config.context}),
            });
        }
        if (next.state !== 'ready') throw fail('network');
        schedule(next);
        document.querySelectorAll('form input[name="_token"]').forEach(input => { input.value = next.csrf_token; });
        if (panel) panel.hidden = true;
        return next;
    }

    function ensure() {
        if (!pending) {
            const controller = new AbortController();
            const timeout = root.setTimeout(() => controller.abort(), 20000);
            const operation = root.navigator.locks
                ? root.navigator.locks.request('sysforest-session-continuity', {signal: controller.signal}, check)
                : check();
            pending = operation.catch(error => {
                const failure = error.sessionFailure ? error : fail('network');
                show(failure);
                throw failure;
            }).finally(() => { root.clearTimeout(timeout); pending = null; });
        }
        return pending;
    }

    function internal(value) {
        const url = new URL(value, root.location.href);
        return url.origin === root.location.origin && /^https?:$/.test(url.protocol);
    }

    function aborted(signal) {
        if (signal?.aborted) throw new DOMException('Solicitud cancelada.', 'AbortError');
    }

    root.fetch = async function (input, options = {}) {
        const url = typeof input === 'string' || input instanceof URL ? String(input) : input.url;
        if (!internal(url) || options.credentials === 'omit') return rawFetch(input, options);
        const signal = options.signal || input?.signal;
        aborted(signal);
        const current = await ensure();
        aborted(signal);
        const headers = new Headers(options.headers || input?.headers);
        headers.set('X-Session-Context', config.context);
        const method = (options.method || input?.method || 'GET').toUpperCase();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) headers.set('X-CSRF-TOKEN', current.csrf_token);
        // La escritura se envía una sola vez. Una respuesta fallida nunca provoca un reenvío automático.
        return rawFetch(input, {...options, headers});
    };

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!internal(form.action) || form.hasAttribute('data-session-exempt')) return;
        if (allowedSubmissions.has(form)) {
            allowedSubmissions.delete(form);
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pendingForms.has(form)) return;
        pendingForms.add(form);
        const busy = form.getAttribute('aria-busy');
        form.setAttribute('aria-busy', 'true');
        const submitter = event.submitter;
        ensure().then(current => {
            if (!form.isConnected) return;
            if ((form.method || 'get').toLowerCase() !== 'get') {
                for (const [name, value] of [['_token', current.csrf_token], ['_session_context', config.context]]) {
                    let input = form.querySelector(`input[name="${name}"]`);
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        form.appendChild(input);
                    }
                    input.value = value;
                }
            }
            allowedSubmissions.add(form);
            try { form.requestSubmit(submitter?.isConnected ? submitter : undefined); }
            finally { allowedSubmissions.delete(form); }
        }).catch(error => show(error, true)).finally(() => {
            pendingForms.delete(form);
            if (busy === null) form.removeAttribute('aria-busy');
            else form.setAttribute('aria-busy', busy);
        });
    }, true);

    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || (link.target && link.target !== '_self') || link.hasAttribute('data-session-exempt')
            || !internal(link.href)) return;
        const url = new URL(link.href, root.location.href);
        if (url.pathname === root.location.pathname && url.search === root.location.search && url.hash) return;
        event.preventDefault();
        ensure().then(() => { root.location.assign(url.href); }).catch(error => show(error, true));
    });

    function resume() {
        if (!document.hidden && Date.now() >= dueAt && !blockedReason) ensure().catch(show);
    }
    document.addEventListener('visibilitychange', resume);
    document.addEventListener('input', resume, true);
    root.addEventListener('pageshow', resume);
    panel?.querySelector('[data-session-retry]')?.addEventListener('click', () => { ensure().catch(show); });
    schedule(config);
})(window);

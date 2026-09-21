import {filterParams, sameOriginUrl} from './state.js';

export function debounce(callback, delay = 300, timers = globalThis) {
    let timer;
    function debounced(...args) {
        timers.clearTimeout(timer);
        timer = timers.setTimeout(() => { timer = undefined; callback(...args); }, delay);
    }
    debounced.cancel = () => { timers.clearTimeout(timer); timer = undefined; };
    return debounced;
}

export function requestUrl(endpoint, origin, filters, extra = {}) {
    const url = sameOriginUrl(endpoint, origin);
    const params = filterParams(filters);
    for (const [key, value] of Object.entries(extra)) {
        if (value !== undefined && value !== null && value !== '' && key !== 'fullWorld') params.set(key, String(value));
    }
    url.search = params.toString();
    return url;
}

/** Cada canal cancela su petición previa y descarta resultados incluso si el transporte ignora abort. */
export class LatestRequest {
    constructor(fetcher = globalThis.fetch.bind(globalThis)) {
        this.fetcher = fetcher;
        this.sequence = 0;
        this.controller = null;
    }
    cancel() {
        this.sequence += 1;
        this.controller?.abort();
        this.controller = null;
    }
    async get(url) {
        this.cancel();
        const sequence = this.sequence;
        const controller = new AbortController();
        this.controller = controller;
        try {
            const response = await this.fetcher(url, {
                signal: controller.signal, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            if (sequence !== this.sequence) return null;
            if (response.redirected) throw new Error('La sesión cambió. Recarga la página para continuar.');
            const data = await response.json();
            if (sequence !== this.sequence) return null;
            if (!response.ok) {
                if (response.status === 401 || response.status === 419) throw new Error('Tu sesión venció. Recarga la página para continuar.');
                if (response.status === 403) throw new Error('No tienes permiso para consultar este alcance del mapa.');
                const messages = Object.values(data.errors ?? {}).flat().filter(value => typeof value === 'string');
                throw new Error(messages.slice(0, 3).join(' ') || data.message || 'No fue posible consultar el mapa. Inténtalo nuevamente.');
            }
            return data;
        } catch (error) {
            if (sequence !== this.sequence || error.name === 'AbortError') return null;
            if (error instanceof SyntaxError || error instanceof TypeError) throw new Error('No se pudo conectar con el mapa. Revisa la conexión e inténtalo de nuevo.');
            throw error;
        } finally {
            if (sequence === this.sequence) this.controller = null;
        }
    }
}

import {FILTER_KEYS, cleanFilters, filterParams, sameOriginUrl} from '../map/state.js';
import {LatestRequest} from '../map/api.js';

export const PUBLIC_FILTER_KEYS = Object.freeze(FILTER_KEYS.filter(key => !['scope', 'maintenance_priority', 'has_pending_maintenance'].includes(key)));

/** La API vuelve a validar códigos y publicación; aquí se evita mezclar contratos de interfaz. */
export function publicMapPolicy(config, origin, fetcher = globalThis.fetch.bind(globalThis)) {
    if (config.access !== 'public' || config.credentials !== 'omit' || !Array.isArray(config.filter_keys)) throw new Error('Configuración pública no válida.');
    for (const endpoint of Object.values(config.endpoints ?? {})) {
        const url = sameOriginUrl(endpoint, origin);
        if (!url.pathname.startsWith('/api/public/v1/') || url.search || url.hash) throw new Error('El portal requiere endpoints públicos explícitos.');
    }
    const keys = config.filter_keys.filter(key => PUBLIC_FILTER_KEYS.includes(key));
    return {filterKeys: keys, fixedScope: null, publicMode: true,
        createRequest: () => new LatestRequest(fetcher, {credentials: 'omit', public: true})};
}

export function publicListUrl(href, filters, {sort, direction} = {}, keys = PUBLIC_FILTER_KEYS) {
    const url = new URL(href);
    const params = filterParams(cleanFilters(filters, keys), keys);
    if (['code', 'common_name', 'scientific_name', 'municipality', 'latest_inspection_date'].includes(sort)) params.set('sort', sort);
    if (['asc', 'desc'].includes(direction)) params.set('direction', direction);
    url.search = params.toString(); url.hash = '';
    return `${url.pathname}${url.search}`;
}

export function publicTreeUrl(value, origin) {
    const url = sameOriginUrl(value, origin);
    if (!/^\/portal\/organizations\/[^/]+\/trees\/[^/]+$/.test(url.pathname) || url.search || url.hash) throw new Error('Ficha pública no válida.');
    return url;
}

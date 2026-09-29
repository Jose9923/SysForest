import {FILTER_KEYS, filterParams, sameOriginUrl} from '../map/state.js';

const EXTRA_KEYS = ['sort', 'direction', 'cap_cm_min', 'cap_cm_max', 'dap_m_min', 'dap_m_max',
    'total_height_m_min', 'total_height_m_max', 'radius_latitude', 'radius_longitude', 'radius_meters',
    'west', 'south', 'east', 'north'];
export const REPORT_FILTER_KEYS = Object.freeze([...FILTER_KEYS, ...EXTRA_KEYS]);
export const PUBLIC_REPORT_FILTER_KEYS = Object.freeze(REPORT_FILTER_KEYS.filter(key => !['scope', 'maintenance_priority', 'has_pending_maintenance'].includes(key)));

/** Conserva filtros aplicados; no transporta página, modo del mapa, tokens ni parámetros desconocidos. */
export function reportPageUrl(base, filters, {origin, isPublic = false, filterKeys = REPORT_FILTER_KEYS, report = 'inventory'} = {}) {
    const url = sameOriginUrl(base, origin);
    if (url.pathname !== (isPublic ? '/portal/reports' : '/reports')) throw new Error('Dirección de reportes no válida.');
    const allowed = isPublic ? PUBLIC_REPORT_FILTER_KEYS : REPORT_FILTER_KEYS;
    const keys = filterKeys.filter(key => allowed.includes(key));
    const params = filterParams(filters, keys);
    params.set('report', /^[a-z_]+$/.test(report) ? report : 'inventory');
    url.search = params.toString(); url.hash = '';
    return `${url.pathname}${url.search}`;
}

export function updateReportLinks(root, filters, options) {
    root.querySelectorAll('[data-report-link]').forEach(link => {
        try {
            link.href = reportPageUrl(link.dataset.reportLink, filters, {...options, report: link.dataset.reportKind || 'inventory'});
        } catch {
            link.removeAttribute('href');
            link.setAttribute('aria-disabled', 'true');
        }
    });
}

/** Estado de presentación compartible; la autorización y los filtros forestales pertenecen al servidor. */
export const FILTER_KEYS = Object.freeze([
    'scope', 'department_id', 'municipality_id', 'organization_id', 'inventory_id', 'q', 'code',
    'common_name', 'scientific_name', 'common_name_id', 'species_id', 'family_id', 'placement_id',
    'growth_habit_id', 'establishment_origin_id', 'biogeographic_origin_id', 'current_status',
    'risk_priority', 'is_protected', 'is_heritage', 'date_from', 'date_to', 'maturity_state_id',
    'diameter_class_number', 'height_class_number', 'physical_condition_ids',
    'phytosanitary_condition_ids', 'condition_mode', 'maintenance_type_id',
    'maintenance_status', 'maintenance_priority', 'has_pending_maintenance',
]);
export const ARRAY_KEYS = Object.freeze(['physical_condition_ids', 'phytosanitary_condition_ids',
    'physical_condition_category_ids', 'phytosanitary_condition_category_ids']);
export const MODES = Object.freeze(['points', 'clusters', 'heat']);

export function cleanFilters(source = {}, keys = FILTER_KEYS) {
    const result = {};
    for (const key of keys) {
        const value = source[key];
        if (ARRAY_KEYS.includes(key)) {
            if (Array.isArray(value)) {
                const values = [...new Set(value.filter(item => typeof item === 'string').map(item => item.trim()).filter(Boolean))];
                if (values.length) result[key] = values;
            }
        } else if (['string', 'number', 'boolean'].includes(typeof value)) {
            const normalized = typeof value === 'boolean' ? (value ? '1' : '0') : String(value).trim();
            if (normalized !== '') result[key] = normalized;
        }
    }
    return result;
}

export function filtersFromEntries(entries, keys = FILTER_KEYS) {
    const filters = {};
    for (const [name, value] of entries) {
        const key = name.endsWith('[]') ? name.slice(0, -2) : name;
        if (!keys.includes(key)) continue;
        if (ARRAY_KEYS.includes(key)) (filters[key] ??= []).push(value);
        else filters[key] = value;
    }
    return cleanFilters(filters, keys);
}

export function filterParams(filters, keys = FILTER_KEYS) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(cleanFilters(filters, keys))) {
        if (Array.isArray(value)) value.forEach(item => params.append(`${key}[]`, item));
        else params.set(key, value);
    }
    return params;
}

export function readUrlState(search, keys = FILTER_KEYS) {
    const params = new URLSearchParams(search);
    const rawMode = params.get('render_mode');
    return {filters: filtersFromEntries(params.entries(), keys), mode: MODES.includes(rawMode) ? rawMode : 'clusters'};
}

export function shareableUrl(href, filters, mode, keys = FILTER_KEYS) {
    const url = new URL(href);
    const params = filterParams(filters, keys);
    if (MODES.includes(mode) && mode !== 'clusters') params.set('render_mode', mode);
    url.search = params.toString();
    url.hash = '';
    return `${url.pathname}${url.search}`;
}

export function filterCount(filters, keys = FILTER_KEYS) {
    return Object.keys(cleanFilters(filters, keys)).filter(key => !['condition_mode', 'scope'].includes(key)).length;
}

export function viewportBounds(bounds, zoom) {
    let west = Number(bounds.getWest());
    let east = Number(bounds.getEast());
    const south = Math.max(-90, Number(bounds.getSouth()));
    const north = Math.min(90, Number(bounds.getNorth()));
    if (![west, east, south, north, Number(zoom)].every(Number.isFinite) || south >= north || west >= east) {
        throw new Error('El encuadre del mapa no es válido.');
    }
    // La API usa un único bbox. Si cruza el antimeridiano se pide el ancho mundial explícitamente.
    const fullWorld = east - west >= 360 || west < -180 || east > 180;
    if (fullWorld) { west = -180; east = 180; }
    return {west, south, east, north, zoom: Math.max(0, Math.min(22, Math.floor(Number(zoom)))), fullWorld};
}

export function pointLatLng(feature) {
    if (feature?.geometry?.type !== 'Point') return null;
    const coordinates = feature.geometry.coordinates;
    if (!Array.isArray(coordinates) || coordinates.length < 2) return null;
    const [longitude, latitude] = coordinates;
    if (!Number.isFinite(longitude) || !Number.isFinite(latitude) || longitude < -180 || longitude > 180 || latitude < -90 || latitude > 90) return null;
    return [latitude, longitude];
}

export function validBounds(bounds) {
    return Array.isArray(bounds) && bounds.length === 2 && bounds.every(pair => Array.isArray(pair) && pair.length === 2 && pair.every(Number.isFinite))
        && bounds[0][0] >= -90 && bounds[1][0] <= 90 && bounds[0][1] >= -180 && bounds[1][1] <= 180
        && bounds[0][0] <= bounds[1][0] && bounds[0][1] <= bounds[1][1];
}

export function heatPoints(features) {
    return features.flatMap(feature => {
        const point = pointLatLng(feature);
        const weight = Number(feature?.properties?.weight);
        return point && Number.isFinite(weight) && weight > 0 ? [[...point, weight]] : [];
    });
}

export function sameOriginUrl(value, origin) {
    if (typeof value !== 'string' || !value.trim()) throw new Error('Dirección del mapa no válida.');
    const url = new URL(value, origin);
    if (url.origin !== new URL(origin).origin || !['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Dirección del mapa no válida.');
    return url;
}

export function territoryNotice(metadata = {}) {
    const messages = [];
    if (Number(metadata.omitted_complex) > 0 || Number(metadata.omitted_invalid) > 0) {
        messages.push('Algunos límites territoriales no se muestran por su complejidad o validez. El encuadre conserva los datos disponibles.');
    }
    if (metadata.truncated === true) {
        messages.push('Se alcanzó el límite de contornos territoriales visibles. Aplica filtros de departamento o municipio para consultar un área más pequeña.');
    }
    return messages.join(' ');
}

/** Un modo implica una sola capa costosa; las bases y límites territoriales se gestionan aparte. */
export class LayerSlot {
    constructor(remove) { this.layer = null; this.remove = remove; }
    replace(factory) {
        this.clear();
        this.layer = factory();
        return this.layer;
    }
    clear() {
        if (this.layer) this.remove(this.layer);
        this.layer = null;
    }
}

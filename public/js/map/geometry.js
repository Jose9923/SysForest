const EARTH_RADIUS = 6371008.8;
const radians = degrees => degrees * Math.PI / 180;
const normalizeAngle = angle => ((angle + Math.PI) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2) - Math.PI;

export const LOCAL_LAYER_LIMITS = Object.freeze({bytes: 2 * 1024 * 1024, features: 3000, coordinates: 30000, layers: 3});

/** Distancia sobre una esfera de radio medio. No sustituye un levantamiento. */
export function pathDistance(points) {
    return points.slice(1).reduce((distance, point, index) => {
        const previous = points[index];
        const latitude = radians(point.lat - previous.lat);
        const longitude = radians(point.lng - previous.lng);
        const haversine = Math.sin(latitude / 2) ** 2 + Math.cos(radians(previous.lat)) * Math.cos(radians(point.lat)) * Math.sin(longitude / 2) ** 2;
        return distance + 2 * EARTH_RADIUS * Math.asin(Math.sqrt(Math.min(1, Math.max(0, haversine))));
    }, 0);
}

/** Área esférica aproximada de un polígono simple; toma la superficie menor. */
export function polygonArea(points) {
    if (points.length < 3) return 0;
    const integral = points.reduce((sum, point, index) => {
        const next = points[(index + 1) % points.length];
        return sum + normalizeAngle(radians(next.lng - point.lng)) * (2 + Math.sin(radians(point.lat)) + Math.sin(radians(next.lat)));
    }, 0);
    const sphere = 4 * Math.PI * EARTH_RADIUS ** 2;
    const area = Math.abs(integral * EARTH_RADIUS ** 2 / 2) % sphere;
    return Math.min(area, sphere - area);
}

export function measurementLabel(mode, points, locale = 'es-CO') {
    const number = value => new Intl.NumberFormat(locale, {maximumFractionDigits: 2}).format(value);
    if (mode === 'area') {
        const area = polygonArea(points);
        return area >= 10000 ? `${number(area / 10000)} ha` : `${number(area)} m²`;
    }
    const distance = pathDistance(points);
    return distance >= 1000 ? `${number(distance / 1000)} km` : `${number(distance)} m`;
}

/** Copia solo geometrías WGS84 y etiquetas simples; ignora HTML, estilos y URLs del archivo. */
export function validateLocalGeoJson(input, limits = LOCAL_LAYER_LIMITS) {
    if (!input || input.type !== 'FeatureCollection' || !Array.isArray(input.features)) {
        throw new Error('El archivo debe contener una colección GeoJSON (FeatureCollection).');
    }
    if (input.crs) throw new Error('Convierte la capa a WGS84 / EPSG:4326, sin un campo crs, antes de cargarla.');
    if (!input.features.length || input.features.length > limits.features) {
        throw new Error(`La capa debe contener entre 1 y ${limits.features.toLocaleString('es-CO')} elementos.`);
    }
    let count = 0;
    const position = coordinates => {
        if (!Array.isArray(coordinates) || coordinates.length < 2 || coordinates.length > 3
            || !coordinates.every(Number.isFinite) || Math.abs(coordinates[0]) > 180 || Math.abs(coordinates[1]) > 90) {
            throw new Error('La capa contiene coordenadas no válidas. Usa longitud y latitud en WGS84.');
        }
        count += 1;
        if (count > limits.coordinates) throw new Error('La capa tiene demasiados vértices. Simplifícala a un máximo de 30.000 coordenadas.');
        return coordinates.slice(0, 2);
    };
    const list = (coordinates, minimum, callback) => {
        if (!Array.isArray(coordinates) || coordinates.length < minimum) throw new Error('La capa contiene una geometría vacía o incompleta.');
        return coordinates.map(callback);
    };
    const line = coordinates => list(coordinates, 2, position);
    const ring = coordinates => {
        const points = list(coordinates, 4, position);
        const last = points.at(-1);
        if (points[0][0] !== last[0] || points[0][1] !== last[1]) throw new Error('Los polígonos deben tener sus anillos cerrados.');
        return points;
    };
    const polygon = coordinates => list(coordinates, 1, ring);
    const parsers = {Point: position, MultiPoint: coordinates => list(coordinates, 1, position), LineString: line,
        MultiLineString: coordinates => list(coordinates, 1, line), Polygon: polygon, MultiPolygon: coordinates => list(coordinates, 1, polygon)};
    const features = input.features.map(feature => {
        if (!feature || feature.type !== 'Feature' || !feature.geometry || feature.crs || feature.geometry.crs) throw new Error('Cada elemento debe ser un Feature con geometría WGS84.');
        const geometry = feature.geometry;
        if (!Object.hasOwn(parsers, geometry.type)) throw new Error('Solo se admiten puntos, líneas, polígonos y sus variantes Multi.');
        const label = ['name', 'nombre', 'label', 'code', 'codigo'].map(key => feature.properties?.[key]).find(value => typeof value === 'string' || typeof value === 'number');
        return {type: 'Feature', geometry: {type: geometry.type, coordinates: parsers[geometry.type](geometry.coordinates)},
            properties: {label: String(label ?? 'Elemento de la capa local').slice(0, 160)}};
    });
    return {type: 'FeatureCollection', features};
}

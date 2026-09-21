import {LayerSlot, heatPoints, pointLatLng, validBounds} from './state.js';
import {treePopup} from './popup.js';

function colorFor(status, legend) {
    const color = legend.find(item => item.status === status)?.color;
    return typeof color === 'string' && /^#[0-9a-f]{6}$/i.test(color) ? color : '#66756d';
}

export class MapLayers {
    constructor({map, L, Supercluster, heatLayer, heatGradient, legend, document, csrf, origin, onPoints}) {
        Object.assign(this, {map, L, Supercluster, heatLayer, heatGradient, legend, document, csrf, origin, onPoints});
        this.slot = new LayerSlot(layer => map.removeLayer(layer));
        this.index = null;
    }
    clear() { this.slot.clear(); this.index = null; }
    render(mode, collection) {
        const features = Array.isArray(collection.features) ? collection.features.filter(feature => pointLatLng(feature)) : [];
        this.index = null;
        this.slot.replace(() => {
            if (mode === 'heat') {
                const points = heatPoints(features);
                const maximum = Math.max(1, ...points.map(point => point[2]));
                return this.heatLayer(points, {radius: 24, blur: 18, max: maximum, maxZoom: 18, minOpacity: 0.3,
                    gradient: this.heatGradient}).addTo(this.map);
            }
            const group = this.L.layerGroup().addTo(this.map);
            if (mode === 'clusters' && !features.some(feature => feature.properties?.kind === 'cluster')) {
                this.index = new this.Supercluster({radius: 60, maxZoom: 19, minPoints: 2}).load(features);
                this.index.getClusters([-180, -90, 180, 90], Math.floor(this.map.getZoom())).forEach(feature => {
                    if (feature.properties.cluster) this.clientCluster(feature).addTo(group);
                    else this.treeMarker(feature).addTo(group);
                });
            } else {
                features.forEach(feature => {
                    if (feature.properties?.kind === 'cluster') this.serverCluster(feature).addTo(group);
                    else if (feature.properties?.kind === 'tree') this.treeMarker(feature).addTo(group);
                });
            }
            return group;
        });
    }
    treeMarker(feature) {
        const properties = feature.properties ?? {};
        const dot = this.document.createElement('span');
        dot.className = 'map-tree-dot';
        dot.style.backgroundColor = colorFor(properties.status, this.legend);
        dot.setAttribute('aria-hidden', 'true');
        const icon = this.L.divIcon({className: 'map-tree-marker', html: dot, iconSize: [26, 26], iconAnchor: [13, 13]});
        return this.L.marker(pointLatLng(feature), {icon, keyboard: true, title: `${properties.code || 'Árbol'} · ${properties.status_label || 'Estado no informado'}`, riseOnHover: true})
            .bindPopup(() => treePopup(properties, this), {maxWidth: 310, minWidth: 220});
    }
    clusterMarker(feature, count, callback) {
        const label = this.document.createElement('span');
        label.className = 'map-cluster-count';
        label.textContent = new Intl.NumberFormat('es-CO').format(count);
        const icon = this.L.divIcon({className: 'map-cluster-marker', html: label, iconSize: [46, 46], iconAnchor: [23, 23]});
        return this.L.marker(pointLatLng(feature), {icon, keyboard: true, title: `${count} árboles. Activar para explorar.`}).on('click', callback);
    }
    serverCluster(feature) {
        const count = Number(feature.properties.count) || 0;
        return this.clusterMarker(feature, count, () => {
            if (this.map.getZoom() >= this.map.getMaxZoom()) {
                const content = this.document.createElement('div');
                const text = this.document.createElement('p');
                text.textContent = `${count} árboles permanecen agrupados en el máximo acercamiento. Usa los filtros o Localizar un árbol para encontrar una ficha concreta.`;
                const button = this.document.createElement('button');
                button.type = 'button'; button.className = 'button secondary'; button.textContent = 'Ver puntos (cantidad limitada)';
                button.addEventListener('click', () => this.onPoints?.());
                content.append(text, button);
                this.L.popup({maxWidth: 290}).setLatLng(pointLatLng(feature)).setContent(content).openOn(this.map);
                return;
            }
            const bounds = feature.properties.bounds;
            if (validBounds(bounds)) this.map.fitBounds(bounds, {padding: [35, 35], maxZoom: Math.min(20, this.map.getZoom() + 4)});
            else this.map.setView(pointLatLng(feature), Math.min(20, this.map.getZoom() + 2));
        });
    }
    clientCluster(feature) {
        const id = feature.properties.cluster_id;
        const index = this.index;
        const marker = this.clusterMarker(feature, feature.properties.point_count, () => {
            const zoom = index.getClusterExpansionZoom(id);
            if (zoom <= this.map.getMaxZoom() && this.map.getZoom() < zoom) this.map.setView(pointLatLng(feature), zoom);
            else marker.bindPopup(this.coincidentTrees(index, id, feature.properties.point_count), {maxWidth: 300}).openPopup();
        });
        return marker;
    }
    coincidentTrees(index, id, total) {
        const list = this.document.createElement('div');
        list.className = 'map-coincident-trees';
        const title = this.document.createElement('h3');
        title.textContent = `${total} árboles en este punto`;
        const items = this.document.createElement('div');
        const next = this.document.createElement('button');
        next.className = 'button secondary'; next.type = 'button'; next.textContent = 'Mostrar más árboles';
        let offset = 0;
        const append = () => {
            index.getLeaves(id, 20, offset).forEach(feature => {
                const button = this.document.createElement('button');
                button.type = 'button'; button.className = 'map-coincident-item';
                button.textContent = feature.properties.code || 'Ver árbol';
                button.addEventListener('click', () => this.L.popup({maxWidth: 300}).setLatLng(pointLatLng(feature)).setContent(treePopup(feature.properties, this)).openOn(this.map));
                items.append(button);
            });
            offset += 20; next.hidden = offset >= total;
        };
        next.addEventListener('click', append);
        append(); list.append(title, items, next);
        return list;
    }
}

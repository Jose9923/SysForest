import {pointLatLng} from '../map/state.js';
import {MapLayers} from '../map/layers.js';
import {publicTreePopup} from './popup.js';
import {bindPublicImageFallback} from './images.js';

function initialize() {
    const root = document.querySelector('[data-public-tree-map]');
    if (root && window.L) {
        const boot = JSON.parse(document.getElementById('public-tree-map-data').textContent);
        const coordinates = pointLatLng({geometry: boot.tree.geometry});
        if (coordinates) {
            const L = window.L;
            const map = L.map(root, {scrollWheelZoom: false, zoomControl: false, maxZoom: 20}).setView(coordinates, 17);
            L.control.zoom({zoomInTitle: 'Acercar', zoomOutTitle: 'Alejar'}).addTo(map);
            boot.config.base_layers.forEach((base, index) => {
                if (index) return;
                L.tileLayer(base.url, {attribution: base.attribution, maxNativeZoom: base.max_zoom || 19, maxZoom: 20, noWrap: true, referrerPolicy: 'origin'}).addTo(map)
                    .on('tileerror', () => { document.querySelector('[data-public-tree-tile-notice]').hidden = false; });
            });
            const layers = new MapLayers({map, L, document, origin: location.origin, legend: boot.config.legend, popupRenderer: publicTreePopup});
            layers.render('points', {features: [{type: 'Feature', geometry: boot.tree.geometry, properties: {...boot.tree, kind: 'tree', open_url: boot.tree.url}}]});
        }
    }
    document.querySelectorAll('[data-public-evidence]').forEach(bindPublicImageFallback);
}
if (document.readyState === 'complete') initialize();
else document.addEventListener('DOMContentLoaded', initialize, {once: true});

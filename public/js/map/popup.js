import {sameOriginUrl} from './state.js';
import {treePopupContent} from './popup-content.js';
import {attachTreePhoto, isMapRecordId} from './tree-photo.js';

export function treePopup(properties, {document, csrf, origin, map}) {
    const box = treePopupContent(properties, {document});
    if (properties.carbon) {
        const carbon = document.createElement('p');
        carbon.textContent = properties.carbon.co2_kg == null
            ? `Carbono aéreo: ${properties.carbon.status}`
            : `Carbono aéreo: ${properties.carbon.carbon_kg} kg C · ${properties.carbon.co2_kg} kg CO₂ (stock estimado en la visita). ${properties.carbon.reference_status || ''}.${properties.carbon.included_in_live_stock ? '' : ' Excluido del stock vivo actual.'}`;
        box.append(carbon);
    }
    if (properties.risk) {
        const risk = document.createElement('p');
        risk.textContent = `Prioridad fitosanitaria: ${properties.risk.label}`;
        box.append(risk);
    }
    if (properties.open_url) {
        try {
            const url = sameOriginUrl(properties.open_url, origin);
            // Solo se permite la acción privada entregada por la API del mapa.
            const queryKeys = [...url.searchParams.keys()];
            const hasSafeScope = queryKeys.length === 0 || (queryKeys.length === 1 && queryKeys[0] === 'scope'
                && ['organization', 'department', 'national'].includes(url.searchParams.get('scope')));
            const match = url.pathname.match(/^\/map\/trees\/([1-9][0-9]{0,18})\/open$/);
            if (!match || !isMapRecordId(match[1]) || !hasSafeScope || url.hash) return box;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url.href;
            const token = document.createElement('input');
            token.type = 'hidden'; token.name = '_token'; token.value = csrf;
            const button = document.createElement('button');
            button.type = 'submit'; button.className = 'button map-open-tree'; button.textContent = 'Ver ficha del árbol';
            form.append(token, button);
            box.append(form);
        } catch { /* Una URL ajena o mal formada nunca genera una acción. */ }
    }
    attachTreePhoto(box, properties, {document, origin, map});
    return box;
}

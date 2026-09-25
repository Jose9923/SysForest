import {treePopupContent} from '../map/popup-content.js';
import {publicTreeUrl} from './policy.js';

export function publicTreePopup(properties, {document, origin}) {
    const box = treePopupContent(properties, {document, inspectionLabel: 'Última inspección publicada'});
    if (properties.open_url) {
        try {
            const url = publicTreeUrl(properties.open_url, origin);
            const link = document.createElement('a');
            link.href = url.href; link.className = 'button map-open-tree'; link.textContent = 'Ver ficha pública';
            box.append(link);
        } catch { /* La proyección pública nunca habilita navegación institucional ni acciones. */ }
    }
    return box;
}

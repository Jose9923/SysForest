import {sameOriginUrl} from './state.js';
import {treePopupContent} from './popup-content.js';

export function treePopup(properties, {document, csrf, origin}) {
    const box = treePopupContent(properties, {document});
    if (properties.open_url) {
        try {
            const url = sameOriginUrl(properties.open_url, origin);
            // Solo se permite la acción privada entregada por la API del mapa.
            const queryKeys = [...url.searchParams.keys()];
            const hasSafeScope = queryKeys.length === 0 || (queryKeys.length === 1 && queryKeys[0] === 'scope'
                && ['organization', 'department', 'national'].includes(url.searchParams.get('scope')));
            if (!/^\/map\/trees\/[0-9a-f-]{36}\/open$/i.test(url.pathname) || !hasSafeScope || url.hash) return box;
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
    return box;
}

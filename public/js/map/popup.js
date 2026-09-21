import {sameOriginUrl} from './state.js';

function paragraph(document, label, value) {
    const line = document.createElement('p');
    const name = document.createElement('strong');
    name.textContent = `${label}: `;
    line.append(name, document.createTextNode(value === null || value === undefined || value === '' ? 'No informado' : String(value)));
    return line;
}

export function treePopup(properties, {document, csrf, origin}) {
    const box = document.createElement('div');
    box.className = 'map-tree-popup';
    const title = document.createElement('h3');
    title.textContent = properties.code || 'Árbol';
    box.append(title);
    box.append(paragraph(document, 'Nombre común', properties.common_name));
    box.append(paragraph(document, 'Nombre científico', properties.scientific_name));
    box.append(paragraph(document, 'Estado', properties.status_label));
    box.append(paragraph(document, 'Emplazamiento', properties.placement));
    box.append(paragraph(document, 'Última inspección', properties.latest_inspection?.date));
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

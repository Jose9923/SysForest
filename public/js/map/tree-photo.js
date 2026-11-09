import {sameOriginUrl} from './state.js';

const scopes = ['organization', 'department', 'national'];
const uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const imageTypes = ['image/jpeg', 'image/png', 'image/webp'];

/** Se crea únicamente al abrir un individuo; las colecciones de puntos no descargan fotografías. */
export function attachTreePhoto(box, properties, {document, origin, map}) {
    let endpoint;
    try {
        const open = sameOriginUrl(properties.open_url, origin);
        const match = open.pathname.match(new RegExp(`^/map/trees/(${uuid})/open$`, 'i'));
        const scope = open.searchParams.get('scope');
        if (!match || open.hash || [...open.searchParams.keys()].some(key => key !== 'scope')
            || open.searchParams.getAll('scope').length > 1 || !scopes.includes(scope)) return;
        endpoint = new URL(`/api/v1/map/trees/${match[1]}/whole-tree-photo`, origin);
        endpoint.searchParams.set('scope', scope);
    } catch { return; }

    const figure = document.createElement('figure');
    figure.className = 'map-tree-photo';
    const message = document.createElement('figcaption');
    message.textContent = 'Cargando fotografía de árbol completo…';
    message.setAttribute('role', 'status');
    figure.append(message);
    box.querySelector('h3')?.after(figure);

    const controller = new AbortController();
    const view = document.defaultView;
    let objectUrl = null;
    let popup = null;
    let stopped = false;
    let observer = null;
    const onOpen = event => {
        if (event.popup.getContent() === box) popup = event.popup;
    };
    const stop = () => {
        if (stopped) return;
        stopped = true;
        controller.abort();
        observer?.disconnect();
        map?.off('popupopen', onOpen);
        map?.off('popupclose', onClose);
        view?.removeEventListener('pagehide', stop);
        figure.querySelector('img')?.removeAttribute('src');
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    };
    const onClose = event => { if (event.popup.getContent() === box) stop(); };
    const active = () => !stopped && box.isConnected && !box.closest('[hidden]');
    const update = () => { if (active()) popup?.update(); };
    map?.on('popupopen', onOpen);
    map?.on('popupclose', onClose);
    view?.addEventListener('pagehide', stop, {once: true});

    const read = async () => {
        if (!active()) { stop(); return; }
        if (view?.MutationObserver) {
            observer = new view.MutationObserver(() => { if (!active()) stop(); });
            observer.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']});
        }
        try {
            const response = await fetch(endpoint, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            if (!active()) return;
            if (!response.ok || response.redirected) throw new Error('metadata');
            const payload = await response.json();
            if (!active()) return;
            if (payload.meta?.can_view_photo !== true) { figure.hidden = true; update(); return; }
            if (payload.data === null) {
                message.textContent = 'Sin fotografía de árbol completo disponible.';
                update(); return;
            }
            const data = payload.data;
            const content = sameOriginUrl(data.url, origin);
            const pattern = new RegExp(`^${endpoint.pathname}/(${uuid})/content$`, 'i');
            const match = content.pathname.match(pattern);
            if (!match || match[1].toLowerCase() !== String(data.id).toLowerCase() || content.hash
                || content.searchParams.get('scope') !== endpoint.searchParams.get('scope')
                || [...content.searchParams.keys()].length !== 1) throw new Error('content-url');
            const imageResponse = await fetch(content, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': imageTypes.join(', ')}});
            if (!active()) return;
            if (!imageResponse.ok || imageResponse.redirected) throw new Error('content');
            const blob = await imageResponse.blob();
            if (!active()) return;
            if (!imageTypes.includes(blob.type)) throw new Error('content-type');
            objectUrl = URL.createObjectURL(blob);
            const image = document.createElement('img');
            image.alt = `Árbol completo · ${properties.code || 'árbol seleccionado'}`;
            image.decoding = 'async';
            image.addEventListener('load', update, {once: true});
            image.addEventListener('error', () => {
                if (!active()) return;
                image.remove();
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                objectUrl = null;
                message.textContent = 'La fotografía no está disponible en este momento.';
                update();
            }, {once: true});
            image.src = objectUrl;
            figure.prepend(image);
            message.textContent = typeof data.caption === 'string' && data.caption.trim() ? data.caption : 'Fotografía de árbol completo';
            update();
        } catch (error) {
            if (!active() || error.name === 'AbortError') return;
            message.textContent = 'No se pudo cargar la fotografía. Vuelve a abrir el árbol para intentarlo de nuevo.';
            update();
        }
    };
    queueMicrotask(read);
}

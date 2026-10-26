const root = document.querySelector('[data-carbon-progress-url]');
const panel = root?.querySelector('[data-carbon-progress]');

if (root && panel && panel.dataset.active === 'true') {
    let timer;
    let stopped = false;
    const controller = new AbortController();
    const poll = async () => {
        if (stopped) return;
        if (document.hidden) {
            timer = window.setTimeout(poll, 5000);
            return;
        }
        try {
            const url = new URL(root.dataset.carbonProgressUrl, location.origin);
            if (url.origin !== location.origin) throw new Error('Origen no permitido');
            const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: controller.signal});
            if (!response.ok) throw new Error('Consulta no disponible');
            const {refresh} = await response.json();
            if (!refresh) return;
            panel.querySelector('[data-carbon-progress-label]').textContent = refresh.label;
            panel.querySelector('[data-carbon-progress-count]').textContent = refresh.processed + ' de ' + refresh.total + ' visitas procesadas';
            panel.querySelector('[data-carbon-progress-added]').textContent = String(refresh.estimates_added);
            panel.querySelector('[data-carbon-progress-message]').textContent = refresh.message;
            const bar = panel.querySelector('[data-carbon-progress-bar]');
            bar.max = Math.max(1, refresh.total);
            bar.value = refresh.processed;
            root.querySelectorAll('[data-carbon-refresh-button]').forEach(button => { button.disabled = refresh.active; });
            if (refresh.active) timer = window.setTimeout(poll, 5000);
        } catch {
            if (!stopped) panel.querySelector('[data-carbon-progress-message]').textContent = 'No pudimos consultar el progreso. Usa Consultar progreso para recargar la página.';
        }
    };
    timer = window.setTimeout(poll, 3000);
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); controller.abort(); }, {once: true});
}

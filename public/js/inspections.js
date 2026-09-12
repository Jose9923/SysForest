/* Solo presenta resultados del servidor; no contiene fórmulas forestales. */
(function () {
    'use strict';
    document.querySelectorAll('[data-condition-group]').forEach((group) => {
        const count = group.querySelector('[data-selected-count]');
        const update = () => {
            const selected = group.querySelectorAll('input[type="checkbox"]:checked').length;
            if (count) count.textContent = selected + (selected === 1 ? ' registrada' : ' registradas');
        };
        group.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-dendrometry-form]').forEach((form) => {
        const panel = form.querySelector('[data-calculation-panel]');
        const status = panel.querySelector('[data-calculation-status]');
        const fields = Array.from(form.querySelectorAll('[data-dendrometry-input]'));
        const outputs = Array.from(panel.querySelectorAll('[data-calculated-output]'));
        let pending = null;
        let timer = null;
        let sequence = 0;
        const clear = () => outputs.forEach((output) => { output.textContent = '—'; });
        const message = (text, invalid = false) => {
            status.textContent = text;
            status.classList.toggle('invalid', invalid);
        };
        const preview = async () => {
            const current = ++sequence;
            if (pending) pending.abort();
            clear();
            if (fields.every((field) => field.value.trim() === '')) {
                panel.dataset.pending = 'false';
                message('Ingresa las mediciones conocidas para ver los resultados.');
                return;
            }
            pending = new AbortController();
            panel.dataset.pending = 'true';
            message('Calculando…');
            const data = {};
            fields.forEach((field) => { data[field.name] = field.value.trim() || null; });
            if (form.dataset.inspectionId) data.inspection_id = form.dataset.inspectionId;
            try {
                const response = await fetch(form.dataset.previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value},
                    body: JSON.stringify(data),
                    signal: pending.signal
                });
                const result = await response.json().catch(() => ({}));
                if (current !== sequence) return;
                panel.dataset.pending = 'false';
                if (!response.ok) {
                    const detail = Object.values(result.errors || {}).flat().find((value) => typeof value === 'string');
                    message(response.status === 401 || response.status === 419
                        ? 'Tu sesión debe renovarse. Conserva los datos antes de volver a ingresar.'
                        : detail || 'Revisa las mediciones para calcular los resultados.', true);
                    return;
                }
                outputs.forEach((output) => {
                    const value = result[output.dataset.calculatedOutput];
                    output.textContent = typeof value === 'string' || typeof value === 'number' ? String(value) : '—';
                });
                message('Calculado automáticamente. Los resultados se confirman al guardar.');
            } catch (error) {
                if (error.name === 'AbortError' || current !== sequence) return;
                panel.dataset.pending = 'false';
                message('No se pudo obtener la vista previa. Las mediciones permanecen en el formulario.', true);
            }
        };
        const schedule = () => {
            sequence++;
            if (pending) pending.abort();
            window.clearTimeout(timer);
            clear();
            panel.dataset.pending = 'true';
            message('Resultados pendientes de actualización…');
            timer = window.setTimeout(preview, 400);
        };
        fields.forEach((field) => field.addEventListener('input', schedule));
        preview();
    });
})();

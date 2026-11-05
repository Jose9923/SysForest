/** Presenta resultados del servidor; no reproduce reglas forestales en el navegador. */
(() => {
    const box = document.querySelector('[data-risk-form]');
    if (!box) return;
    const form = box.closest('form');
    const result = box.querySelector('[data-risk-result]');
    const details = box.querySelector('[data-risk-details]');
    const confirmed = box.querySelector('[data-risk-confirmed]');
    const priority = box.querySelector('[name="risk_priority"]');
    let sequence = 0;
    let timer;
    async function preview() {
        const current = ++sequence;
        confirmed.checked = false;
        priority.setCustomValidity('Espera la sugerencia antes de confirmar.');
        result.textContent = 'Consultando sugerencia…';
        details.replaceChildren();
        try {
            const response = await fetch(box.dataset.previewUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value},
                body: JSON.stringify({inspection_id: box.dataset.inspectionId || null,
                    phytosanitary_condition_ids: new FormData(form).getAll('phytosanitary_condition_ids[]')}),
            });
            const data = await response.json();
            if (current !== sequence) return;
            if (!response.ok) throw new Error(data.message || 'No se pudo calcular. Revisa la sesión y vuelve a consultar.');
            box.querySelector('[data-risk-methodology]').value = data.methodology_id || '';
            confirmed.disabled = data.status !== 'valid';
            priority.setCustomValidity(data.status === 'valid' ? '' : 'Corrige las condiciones incompatibles antes de guardar.');
            result.textContent = data.status !== 'valid'
                ? 'Inspección inconsistente: corrige las selecciones antes de guardar.'
                : data.coverage_status === 'missing'
                    ? 'Captura insuficiente para sugerir una prioridad. Registra las condiciones observadas o justifica tu decisión profesional en las observaciones generales.'
                    : `Sugerencia: ${data.priority_label} (${data.methodology_code}). Severidad máxima ${data.severity_max}; extensión ${data.extension_class} (${data.affected_component_count} componentes). ${data.management_message}`;
            const components = {PHT_ROOT: 'Sistema radicular', PHT_STEM: 'Fuste o estípite', PHT_BRANCH: 'Ramas', PHT_FOLIAGE: 'Follaje', PHT_FLOWER_FRUIT: 'Flores y frutos'};
            for (const message of [...data.errors, ...data.warnings,
                ...data.affected_components.map(key => `Componente afectado: ${components[key] || key}.`),
                ...data.determinants.map(item => `Determinante: ${item.display_label || item.label} (peso ${item.weight}).`),
                ...data.overrides.map(item => `Prevalencia: ${item.label}, mínimo ${item.minimum}.`),
                ...(data.excluded_conditions || []).map(item => `Condición registrada que no puntúa: ${item.display_label || item.label}.`)]) {
                const li = document.createElement('li'); li.textContent = message; details.append(li);
            }
        } catch (error) {
            if (current !== sequence) return;
            confirmed.disabled = true;
            priority.setCustomValidity('Actualiza la sugerencia antes de guardar.');
            result.textContent = error.message;
        }
    }
    const conditionsChanged = event => {
        if (['physical_condition_ids[]', 'phytosanitary_condition_ids[]'].includes(event.target.name)) {
            ++sequence;
            confirmed.checked = false;
            confirmed.disabled = true;
            priority.setCustomValidity('Espera la sugerencia antes de confirmar.');
            result.textContent = 'Condiciones modificadas. Actualizando sugerencia…';
            clearTimeout(timer); timer = setTimeout(preview, 250);
        }
        if (event.target.name === 'risk_priority') confirmed.checked = false;
    };
    // Select2 emite change mediante jQuery; este listener recibe también los cambios nativos.
    if (window.jQuery) window.jQuery(form).on('change.inspectionRisk', conditionsChanged);
    else form.addEventListener('change', conditionsChanged);
    box.querySelector('[data-risk-preview]').addEventListener('click', preview);
    preview();
})();

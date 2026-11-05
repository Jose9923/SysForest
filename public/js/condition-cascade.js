/* Presentación de condiciones por órgano; las reglas forestales se validan en el servidor. */
(function (root) {
    'use strict';
    const instances = new WeakMap();
    const updateSelect = (select, notify = false) => {
        if (root.jQuery) root.jQuery(select).trigger(notify ? 'change' : 'change.select2');
        else if (notify) select.dispatchEvent(new Event('change', {bubbles: true}));
    };
    const initialize = cascade => {
        if (instances.has(cascade)) return instances.get(cascade);
        const categories = cascade.querySelector('[data-condition-category-select]');
        const panels = Array.from(cascade.querySelectorAll('[data-condition-panel]'));
        const empty = cascade.querySelector('[data-condition-empty]');
        const refresh = (notify = true) => {
            const selected = new Set(Array.from(categories.selectedOptions, option => option.value));
            panels.forEach(panel => {
                const visible = selected.has(panel.dataset.conditionPanel);
                const conditions = panel.querySelector('[data-condition-select]');
                const hadSelections = conditions.selectedOptions.length > 0;
                if (!visible) Array.from(conditions.options).forEach(option => { option.selected = false; });
                conditions.disabled = !visible;
                panel.hidden = !visible;
                updateSelect(conditions, notify && !visible && hadSelections);
            });
            empty.hidden = selected.size > 0;
        };
        if (root.jQuery) root.jQuery(categories).on('change.conditionCascade', () => refresh());
        else categories.addEventListener('change', () => refresh());
        const instance = {categories, panels, refresh};
        instances.set(cascade, instance);
        refresh(false);
        return instance;
    };
    const setValues = (cascade, ids) => {
        if (!cascade) return;
        const {categories, panels, refresh} = initialize(cascade);
        const selected = new Set(Array.isArray(ids) ? ids.filter(id => typeof id === 'string') : []);
        const selectedCategories = new Set();
        panels.forEach(panel => {
            const conditions = panel.querySelector('[data-condition-select]');
            Array.from(conditions.options).forEach(option => { option.selected = selected.has(option.value); });
            if (conditions.selectedOptions.length) selectedCategories.add(panel.dataset.conditionPanel);
        });
        Array.from(categories.options).forEach(option => { option.selected = selectedCategories.has(option.value); });
        updateSelect(categories);
        refresh(false);
    };
    root.SysforestConditionCascade = Object.freeze({setValues});
    const initializeAll = () => document.querySelectorAll('[data-condition-cascade]').forEach(initialize);
    // La inicialización general de Select2 se registra antes en catalog-select.js.
    if (root.jQuery) root.jQuery(initializeAll);
    else initializeAll();
})(window);

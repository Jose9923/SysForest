/* Select2 mantiene la etiqueta y las ayudas del campo nativo en sus controles visibles. */
(function (root) {
    'use strict';

    const associations = new WeakMap();

    function associate(select, instance) {
        if (!select || !instance?.$selection?.[0]) return;
        const document = select.ownerDocument;
        const labels = Array.from(select.labels || []);
        const labelIds = labels.map((label, index) => {
            if (!label.id) {
                const prefix = (select.id || 'select2-field') + '-label-' + index;
                let id = prefix;
                let suffix = 1;
                while (document.getElementById(id)) id = prefix + '-' + suffix++;
                label.id = id;
            }
            return label.id;
        });
        const originalLabels = (select.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean);
        const names = [...new Set([...originalLabels, ...labelIds])];
        const selection = instance.$selection[0];
        const selectedValue = (selection.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean);

        const describe = (control, withValue = false) => {
            if (!control) return;
            const references = [...new Set([...names, ...(withValue ? selectedValue : [])])];
            if (names.length) control.setAttribute('aria-labelledby', references.join(' '));
            else if (select.getAttribute('aria-label')) {
                control.removeAttribute('aria-labelledby');
                control.setAttribute('aria-label', select.getAttribute('aria-label'));
            } else if (references.length) control.setAttribute('aria-labelledby', references.join(' '));
            for (const attribute of ['aria-describedby', 'aria-invalid']) {
                const value = select.getAttribute(attribute);
                if (value) control.setAttribute(attribute, value);
            }
            if (select.required) control.setAttribute('aria-required', 'true');
        };
        const refresh = () => {
            describe(selection, !select.multiple);
            describe(instance.selection?.$search?.[0]);
            describe(instance.dropdown?.$search?.[0]);
        };
        refresh();

        const previous = associations.get(select);
        if (previous?.instance === instance) return;
        previous?.listeners.forEach(([label, listener]) => label.removeEventListener('click', listener));
        const listeners = labels.map(label => {
            const listener = event => {
                if (select.disabled || event.target === select || event.target.closest?.('a, button')) return;
                event.preventDefault();
                (instance.selection?.$search?.[0] || selection).focus();
            };
            label.addEventListener('click', listener);
            return [label, listener];
        });
        instance.on('open', refresh);
        associations.set(select, {instance, listeners});
    }

    root.SysforestSelect2Accessibility = Object.freeze({associate});
})(window);

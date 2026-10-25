/** Agrega controles; la validez y la semántica de cada regla se verifican en el servidor. */
(() => {
    document.querySelectorAll('[data-add-rule]').forEach(button => {
        button.addEventListener('click', () => {
            const kind = button.dataset.addRule;
            const list = document.querySelector(`[data-rule-list="${kind}"]`);
            const template = document.querySelector(`[data-rule-template="${kind}"]`);
            const index = Math.max(-1, ...[...list.querySelectorAll('[name]')].map(input => Number(input.name.match(/\[(\d+)\]/)?.[1] ?? -1))) + 1;
            const fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[name]').forEach(input => { input.name = input.name.replaceAll('__INDEX__', String(index)); });
            list.append(fragment);
        });
    });
})();

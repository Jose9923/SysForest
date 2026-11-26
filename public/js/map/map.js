import {startMap} from './engine.js?v=20261125-organization-scope';
import {treePopup} from './popup.js';
import {filterParams} from './state.js';

// Los enlaces conservan solo filtros aplicados y compatibles; cada destino vuelve a autorizarse.
function updateScopeLinks(filters) {
    document.querySelectorAll('[data-map-scope-link]').forEach(link => {
        const url = new URL(link.href, window.location.origin);
        if (url.origin !== window.location.origin || url.pathname !== '/map') return;
        const clear = JSON.parse(link.dataset.clearFields || '[]');
        const values = {...filters, scope: link.dataset.mapScopeLink};
        clear.forEach(key => { delete values[key]; });
        const params = filterParams(values);
        const mode = document.querySelector('[name="render_mode"]:checked')?.value;
        if (mode && mode !== 'clusters') params.set('render_mode', mode);
        url.search = params.toString();
        link.href = `${url.pathname}${url.search}`;
    });
}

startMap(boot => ({popupRenderer: treePopup, fixedScope: boot.config.scope.mode, onFiltersChanged: updateScopeLinks}));

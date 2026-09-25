import {startMap} from '../map/engine.js';
import {publicTreePopup} from './popup.js';
import {publicListUrl, publicMapPolicy} from './policy.js';

startMap(boot => {
    const policy = publicMapPolicy(boot.config, window.location.origin);
    return {...policy, popupRenderer: publicTreePopup, onFiltersChanged: filters => {
        const link = document.querySelector('[data-public-list-link]');
        if (link) link.href = publicListUrl(new URL(link.dataset.publicListLink, window.location.origin).href, filters, {}, policy.filterKeys);
    }};
});

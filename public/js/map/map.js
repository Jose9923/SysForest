import {startMap} from './engine.js';
import {treePopup} from './popup.js';

startMap(boot => ({popupRenderer: treePopup, fixedScope: boot.config.scope.mode}));

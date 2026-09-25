import test from 'node:test';
import assert from 'node:assert/strict';
import {bindPublicImageFallback} from '../../public/js/public/images.js';

class Image extends EventTarget {
    constructor(complete, naturalWidth) {
        super(); this.complete = complete; this.naturalWidth = naturalWidth;
        this.hidden = false; this.notice = {hidden: true};
    }
    closest() { return {querySelector: () => this.notice}; }
}

test('imagen retirada o fallida antes del arranque muestra el aviso de disponibilidad', () => {
    const image = new Image(true, 0);
    bindPublicImageFallback(image);
    assert.equal(image.hidden, true);
    assert.equal(image.notice.hidden, false);
});

test('imagen pendiente permanece visible y responde a un error posterior', () => {
    const image = new Image(false, 0);
    bindPublicImageFallback(image);
    assert.equal(image.hidden, false);
    assert.equal(image.notice.hidden, true);
    image.dispatchEvent(new Event('error'));
    assert.equal(image.hidden, true);
    assert.equal(image.notice.hidden, false);
});

test('imagen cargada correctamente mantiene fotografía y no muestra aviso de error', () => {
    const image = new Image(true, 48);
    bindPublicImageFallback(image);
    assert.equal(image.hidden, false);
    assert.equal(image.notice.hidden, true);
});

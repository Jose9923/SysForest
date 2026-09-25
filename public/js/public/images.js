/** También contempla imágenes cuyo error ocurrió antes de inicializar la galería. */
export function bindPublicImageFallback(image) {
    const unavailable = () => {
        image.hidden = true;
        const message = image.closest('figure')?.querySelector('[data-public-image-error]');
        if (message) message.hidden = false;
    };
    image.addEventListener('error', unavailable, {once: true});
    if (image.complete && image.naturalWidth === 0) unavailable();
}

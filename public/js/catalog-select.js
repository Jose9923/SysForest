'use strict';

// Búsqueda visual local; las relaciones y permisos siempre se validan en los Services.
jQuery(function ($) {
    // El catálogo completo permite conservar selecciones históricas; el científico acota las nuevas opciones.
    $('[data-taxonomy-species]').each(function () {
        const species = this;
        const commonName = species.form.querySelector('[data-taxonomy-common-name]');
        if (!commonName) return;
        const options = Array.from(commonName.options, option => option.cloneNode(true));
        const refresh = preserve => {
            const selected = preserve ? commonName.value : '';
            commonName.replaceChildren(...options.filter(option => !option.value || !species.value
                || option.dataset.speciesId === species.value || option.value === selected)
                .map(option => option.cloneNode(true)));
            commonName.value = selected;
            $(commonName).trigger('change.select2');
        };
        refresh(true);
        $(species).on('change.taxonomy', () => refresh(false));
    });
    $('.catalog-select').select2({
        width: '100%',
        language: 'es',
        minimumResultsForSearch: 0
    }).each(function () {
        window.SysforestSelect2Accessibility.associate(this, $(this).data('select2'));
    });
});

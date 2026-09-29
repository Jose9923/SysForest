'use strict';

// Mejora de presentación; el servidor conserva la validación territorial y la autorización.
jQuery(function ($) {
    $('.municipality-select').select2({
        width: '100%',
        language: 'es',
        placeholder: 'Selecciona un municipio'
    }).each(function () {
        window.SysforestSelect2Accessibility.associate(this, $(this).data('select2'));
    });
});

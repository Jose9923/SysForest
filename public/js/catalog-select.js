'use strict';

// Búsqueda visual local; las relaciones y permisos siempre se validan en los Services.
jQuery(function ($) {
    $('.catalog-select').select2({
        width: '100%',
        language: 'es',
        minimumResultsForSearch: 0
    }).each(function () {
        window.SysforestSelect2Accessibility.associate(this, $(this).data('select2'));
    });
});

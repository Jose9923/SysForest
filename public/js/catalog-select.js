'use strict';

// Búsqueda visual local; las relaciones y permisos siempre se validan en los Services.
jQuery(function ($) {
    $('.catalog-select').select2({
        width: '100%',
        language: 'es',
        minimumResultsForSearch: 0
    });
});

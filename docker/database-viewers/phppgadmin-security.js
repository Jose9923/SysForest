/* CSRF para los POST AJAX de la interfaz upstream; no guarda credenciales. */
(function () {
  "use strict";
  var token = document.querySelector('meta[name="sysforest-csrf"]');
  if (token && window.jQuery) {
    window.jQuery.ajaxPrefilter(function (options, original, xhr) {
      if (!options.crossDomain) xhr.setRequestHeader("X-SysForest-CSRF", token.content);
    });
  }
}());

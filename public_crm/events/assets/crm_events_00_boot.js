(function () {
  'use strict';

  const NS = (window.CRM_EVENTS = window.CRM_EVENTS || {});

  NS.util = NS.util || {};

  NS.util.q = function(sel){
    return document.querySelector(sel);
  };

  NS.util.esc = function(str){
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  };

})();
